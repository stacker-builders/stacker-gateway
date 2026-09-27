<?php

namespace App\Console\Commands;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Services\Uazapi\UazapiAccountResolver;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDispatcher;
use App\Services\Uazapi\UazapiMessageBuilder;
use App\Services\Whatsapp\WhatsappRecoveryGuard;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessUazapiCartRecoveryCommand extends Command
{
    protected $signature = 'uazapi:process-cart-recovery';

    protected $description = 'Envia WhatsApp de recuperação de carrinho e lembretes de PIX pendente via uazapi.';

    public function handle(
        UazapiClient $client,
        UazapiMessageBuilder $messageBuilder,
        UazapiDispatcher $dispatcher,
        UazapiAccountResolver $resolver
    ): int {
        $tenantIds = UazapiInstance::query()
            ->where('is_active', true)
            ->where('status', UazapiInstance::STATUS_CONNECTED)
            ->where(function ($query) {
                $query->where('cart_recovery_enabled', true)
                    ->orWhere('pix_recovery_enabled', true);
            })
            ->distinct()
            ->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            $this->line('Nenhuma instância WhatsApp conectada com recuperação ativa.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($tenantIds as $tenantId) {
            $dispatched += $this->processCart((int) $tenantId, $client, $messageBuilder, $dispatcher, $resolver);
            $dispatched += $this->processPix((int) $tenantId, $client, $messageBuilder, $dispatcher, $resolver);
        }

        if ($dispatched > 0) {
            Log::info('ProcessUazapiCartRecoveryCommand: WhatsApp enfileirados', ['count' => $dispatched]);
        }

        $this->info("uazapi recovery: {$dispatched} mensagem(ns) enfileirada(s).");

        return self::SUCCESS;
    }

    private function processCart(
        int $tenantId,
        UazapiClient $client,
        UazapiMessageBuilder $messageBuilder,
        UazapiDispatcher $dispatcher,
        UazapiAccountResolver $resolver
    ): int {
        $windowProbe = $resolver->routableForTenant($tenantId, UazapiAccountResolver::CAPABILITY_CART);
        if ($windowProbe->isEmpty()) {
            return 0;
        }

        $maxDelayMinutes = $windowProbe
            ->map(function (UazapiInstance $instance) {
                $steps = $instance->cartRecoverySteps();

                return $steps === [] ? 0 : (int) end($steps)['delay_minutes'];
            })
            ->max();
        if ($maxDelayMinutes < 1) {
            return 0;
        }

        $windowStart = now()->subMinutes($maxDelayMinutes + 120);
        $dispatched = 0;

        $sessions = CheckoutSession::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('created_at', '>=', $windowStart)
            ->with('product:id,name,checkout_slug,image,tenant_id')
            ->get();

        foreach ($sessions as $session) {
            $instance = $resolver->resolveForSession($session);
            if (! $instance) {
                continue;
            }

            $steps = $instance->cartRecoverySteps();
            if ($steps === []) {
                continue;
            }

            $phone = $client->normalizePhone($session->phone);
            if ($phone === null) {
                continue;
            }

            if ($this->isBlocked((int) $session->tenant_id, $phone, $session->created_at)) {
                continue;
            }

            if (WhatsappRecoveryGuard::alreadyOwnsProduct(
                (int) $session->tenant_id,
                $phone,
                $session->product_id
            )) {
                continue;
            }

            $abandonAt = $this->resolveAbandonAt($session);
            if ($abandonAt === null) {
                continue;
            }

            $vars = $messageBuilder->fromCheckoutSession($session);

            foreach ($steps as $index => $step) {
                if (WhatsappRecoveryGuard::sessionTaken((int) $session->id, UazapiInstance::EVENT_CART_RECOVERY, $index)) {
                    continue;
                }

                $dueAt = $abandonAt->copy()->addMinutes((int) $step['delay_minutes']);
                if (now()->lt($dueAt)) {
                    break;
                }

                if ($dispatcher->dispatchCartRecoveryStep(
                    $instance,
                    $session,
                    $index,
                    (string) $step['message'],
                    $vars
                )) {
                    $dispatched++;
                }

                break;
            }
        }

        return $dispatched;
    }

    private function processPix(
        int $tenantId,
        UazapiClient $client,
        UazapiMessageBuilder $messageBuilder,
        UazapiDispatcher $dispatcher,
        UazapiAccountResolver $resolver
    ): int {
        $windowProbe = $resolver->routableForTenant($tenantId, UazapiAccountResolver::CAPABILITY_PIX);
        if ($windowProbe->isEmpty()) {
            return 0;
        }

        $maxDelayMinutes = $windowProbe
            ->map(function (UazapiInstance $instance) {
                $steps = $instance->pixRecoverySteps();

                return $steps === [] ? 0 : (int) end($steps)['delay_minutes'];
            })
            ->max();
        if ($maxDelayMinutes < 1) {
            return 0;
        }

        $windowStart = now()->subMinutes($maxDelayMinutes + 120);
        $dispatched = 0;

        $orderIds = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', UazapiInstance::EVENT_PIX_GENERATED)
            ->where('sequence_step', 0)
            ->where('status', UazapiMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', $windowStart)
            ->pluck('order_id')
            ->filter()
            ->unique()
            ->all();

        if ($orderIds === []) {
            return 0;
        }

        $orders = Order::query()
            ->whereIn('id', $orderIds)
            ->where('status', 'pending')
            ->whereNull('api_application_id')
            ->whereNull('api_checkout_session_id')
            ->get();

        foreach ($orders as $order) {
            $instance = $resolver->resolveForOrder($order);
            if (! $instance) {
                continue;
            }

            $reminderSteps = $instance->pixRecoverySteps();
            if ($reminderSteps === []) {
                continue;
            }

            $phone = $client->normalizePhone((string) ($order->phone ?? ''));
            if ($phone === null) {
                $metadata = is_array($order->metadata) ? $order->metadata : [];
                $phone = $client->normalizePhone((string) ($metadata['phone'] ?? $metadata['customer_phone'] ?? ''));
            }
            if ($phone === null) {
                continue;
            }

            if ($this->isBlocked((int) $order->tenant_id, $phone, $order->created_at)) {
                continue;
            }

            $origin = $this->pixOriginAt($order->id) ?? $order->created_at;
            if ($origin === null) {
                continue;
            }

            $vars = $messageBuilder->fromOrder($order);

            foreach ($reminderSteps as $index => $step) {
                $stepIndex = $index + 1;
                if (WhatsappRecoveryGuard::orderStepTaken((int) $order->id, UazapiInstance::EVENT_PIX_GENERATED, $stepIndex)) {
                    continue;
                }

                $dueAt = $origin->copy()->addMinutes((int) $step['delay_minutes']);
                if (now()->lt($dueAt)) {
                    break;
                }

                if ($dispatcher->dispatchPixRecoveryStep(
                    $instance,
                    $order,
                    $phone,
                    $stepIndex,
                    (string) $step['message'],
                    $vars
                )) {
                    $dispatched++;
                }

                break;
            }
        }

        return $dispatched;
    }

    private function isBlocked(int $tenantId, string $phone, mixed $startedAt): bool
    {
        if (WhatsappRecoveryGuard::isOptedOut($tenantId, $phone)) {
            return true;
        }

        if (! $startedAt instanceof DateTimeInterface) {
            return false;
        }

        return WhatsappRecoveryGuard::blocks($tenantId, $phone, $startedAt);
    }

    private function pixOriginAt(int $orderId): ?Carbon
    {
        $dispatch = UazapiMessageDispatch::query()
            ->where('order_id', $orderId)
            ->where('event_type', UazapiInstance::EVENT_PIX_GENERATED)
            ->where('sequence_step', 0)
            ->where('status', UazapiMessageDispatch::STATUS_SENT)
            ->orderBy('id')
            ->first();

        return $dispatch?->sent_at instanceof Carbon ? $dispatch->sent_at : null;
    }

    private function resolveAbandonAt(CheckoutSession $session): ?Carbon
    {
        $timestamp = $session->form_filled_at
            ?? $session->form_started_at
            ?? $session->updated_at
            ?? $session->created_at;

        return $timestamp instanceof Carbon ? $timestamp : null;
    }
}
