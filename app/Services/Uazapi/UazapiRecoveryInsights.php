<?php

namespace App\Services\Uazapi;

use App\Models\CheckoutSession;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\EvolutionOptOut;
use App\Models\EvolutionRecoveryStop;
use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Models\UazapiRecoveryStop;
use Carbon\CarbonInterface;

class UazapiRecoveryInsights
{
    /**
     * @return array<string, mixed>
     */
    public function forTenant(int $tenantId, int $days = 7): array
    {
        $start = now()->subDays(max(1, $days))->startOfDay();

        $uazapiSentQuery = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UazapiMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', $start);

        $evolutionSentQuery = EvolutionMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', EvolutionMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', $start);

        $sent = (clone $uazapiSentQuery)->count() + (clone $evolutionSentQuery)->count();
        $cartSent = (clone $uazapiSentQuery)->where('event_type', UazapiInstance::EVENT_CART_RECOVERY)->count()
            + (clone $evolutionSentQuery)->where('event_type', EvolutionInstance::EVENT_CART_RECOVERY)->count();
        $pixSent = (clone $uazapiSentQuery)->where('event_type', UazapiInstance::EVENT_PIX_GENERATED)->count()
            + (clone $evolutionSentQuery)->where('event_type', EvolutionInstance::EVENT_PIX_GENERATED)->count();
        $orderPaidSent = (clone $uazapiSentQuery)->where('event_type', UazapiInstance::EVENT_ORDER_PAID)->count()
            + (clone $evolutionSentQuery)->where('event_type', EvolutionInstance::EVENT_ORDER_PAID)->count();

        $delivered = (clone $uazapiSentQuery)->whereIn('wa_status', ['Delivered', 'Read', 'Played'])->count()
            + (clone $evolutionSentQuery)->whereIn('wa_status', ['Delivered', 'Read', 'Played'])->count();
        $read = (clone $uazapiSentQuery)->whereIn('wa_status', ['Read', 'Played'])->count()
            + (clone $evolutionSentQuery)->whereIn('wa_status', ['Read', 'Played'])->count();

        $failed = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UazapiMessageDispatch::STATUS_FAILED)
            ->where('created_at', '>=', $start)
            ->count()
            + EvolutionMessageDispatch::query()
                ->where('tenant_id', $tenantId)
                ->where('status', EvolutionMessageDispatch::STATUS_FAILED)
                ->where('created_at', '>=', $start)
                ->count();

        $canceled = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UazapiMessageDispatch::STATUS_CANCELED)
            ->where('created_at', '>=', $start)
            ->count()
            + EvolutionMessageDispatch::query()
                ->where('tenant_id', $tenantId)
                ->where('status', EvolutionMessageDispatch::STATUS_CANCELED)
                ->where('created_at', '>=', $start)
                ->count();

        $replied = UazapiRecoveryStop::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->count()
            + EvolutionRecoveryStop::query()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $start)
                ->count();

        $optOuts = UazapiOptOut::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->count()
            + EvolutionOptOut::query()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $start)
                ->count();

        $cartConverted = $this->convertedCart($tenantId, $start);
        $pixConverted = $this->convertedPix($tenantId, $start);
        $convertedIds = array_values(array_unique(array_merge(
            $cartConverted['order_ids'],
            $pixConverted['order_ids']
        )));
        $convertedAmount = (float) Order::query()
            ->whereIn('id', $convertedIds !== [] ? $convertedIds : [0])
            ->sum('amount');

        return [
            'days' => $days,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'replied' => $replied,
            'converted' => count($convertedIds),
            'converted_amount' => round($convertedAmount, 2),
            'opt_outs' => $optOuts,
            'failed' => $failed,
            'canceled' => $canceled,
            'cart_sent' => $cartSent,
            'pix_sent' => $pixSent,
            'order_paid_sent' => $orderPaidSent,
            'cart_converted' => count($cartConverted['order_ids']),
            'pix_converted' => count($pixConverted['order_ids']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentDispatches(int $tenantId, int $limit = 30): array
    {
        $uazapi = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (UazapiMessageDispatch $dispatch) => $this->mapDispatch(
                provider: 'uazapi',
                id: (int) $dispatch->id,
                eventType: (string) $dispatch->event_type,
                status: (string) $dispatch->status,
                waStatus: $dispatch->wa_status,
                phone: (string) $dispatch->phone,
                sequenceStep: $dispatch->sequence_step !== null ? (int) $dispatch->sequence_step : null,
                error: $dispatch->error,
                sentAt: $dispatch->sent_at?->toIso8601String(),
                createdAt: $dispatch->created_at?->toIso8601String(),
            ));

        $evolution = EvolutionMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (EvolutionMessageDispatch $dispatch) => $this->mapDispatch(
                provider: 'evolution',
                id: (int) $dispatch->id,
                eventType: (string) $dispatch->event_type,
                status: (string) $dispatch->status,
                waStatus: $dispatch->wa_status,
                phone: (string) $dispatch->phone,
                sequenceStep: $dispatch->sequence_step !== null ? (int) $dispatch->sequence_step : null,
                error: $dispatch->error,
                sentAt: $dispatch->sent_at?->toIso8601String(),
                createdAt: $dispatch->created_at?->toIso8601String(),
            ));

        return $uazapi
            ->concat($evolution)
            ->sortByDesc(fn (array $row) => $row['created_at'] ?? '')
            ->take($limit)
            ->values()
            ->all();
    }

    public function canResendFailed(string $provider, string $eventType, string $status): bool
    {
        if ($status !== 'failed') {
            return false;
        }

        if (! in_array($provider, ['evolution', 'uazapi'], true)) {
            return false;
        }

        return in_array($eventType, [
            EvolutionInstance::EVENT_CART_RECOVERY,
            EvolutionInstance::EVENT_PIX_GENERATED,
            EvolutionInstance::EVENT_ORDER_PAID,
            UazapiInstance::EVENT_CART_RECOVERY,
            UazapiInstance::EVENT_PIX_GENERATED,
            UazapiInstance::EVENT_ORDER_PAID,
        ], true);
    }

    /**
     * @return array{order_ids: list<int>}
     */
    private function convertedCart(int $tenantId, CarbonInterface $start): array
    {
        $sessionIds = collect()
            ->merge(
                UazapiMessageDispatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', UazapiInstance::EVENT_CART_RECOVERY)
                    ->where('status', UazapiMessageDispatch::STATUS_SENT)
                    ->where('created_at', '>=', $start)
                    ->whereNotNull('checkout_session_id')
                    ->pluck('checkout_session_id')
            )
            ->merge(
                EvolutionMessageDispatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', EvolutionInstance::EVENT_CART_RECOVERY)
                    ->where('status', EvolutionMessageDispatch::STATUS_SENT)
                    ->where('created_at', '>=', $start)
                    ->whereNotNull('checkout_session_id')
                    ->pluck('checkout_session_id')
            )
            ->unique()
            ->values()
            ->all();

        if ($sessionIds === []) {
            return ['order_ids' => []];
        }

        $orderIds = CheckoutSession::query()
            ->whereIn('id', $sessionIds)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique()
            ->all();

        return ['order_ids' => $this->completedOrderIds($orderIds)];
    }

    /**
     * @return array{order_ids: list<int>}
     */
    private function convertedPix(int $tenantId, CarbonInterface $start): array
    {
        $orderIds = collect()
            ->merge(
                UazapiMessageDispatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', UazapiInstance::EVENT_PIX_GENERATED)
                    ->where('status', UazapiMessageDispatch::STATUS_SENT)
                    ->where('created_at', '>=', $start)
                    ->whereNotNull('order_id')
                    ->pluck('order_id')
            )
            ->merge(
                EvolutionMessageDispatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', EvolutionInstance::EVENT_PIX_GENERATED)
                    ->where('status', EvolutionMessageDispatch::STATUS_SENT)
                    ->where('created_at', '>=', $start)
                    ->whereNotNull('order_id')
                    ->pluck('order_id')
            )
            ->unique()
            ->values()
            ->all();

        return ['order_ids' => $this->completedOrderIds($orderIds)];
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return list<int>
     */
    private function completedOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return Order::query()
            ->whereIn('id', $orderIds)
            ->where('status', 'completed')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDispatch(
        string $provider,
        int $id,
        string $eventType,
        string $status,
        mixed $waStatus,
        string $phone,
        ?int $sequenceStep,
        mixed $error,
        ?string $sentAt,
        ?string $createdAt,
    ): array {
        return [
            'id' => $provider.'-'.$id,
            'dispatch_id' => $id,
            'provider' => $provider,
            'event_type' => $eventType,
            'status' => $status,
            'wa_status' => is_string($waStatus) ? $waStatus : null,
            'phone' => $phone,
            'sequence_step' => $sequenceStep,
            'error' => is_string($error) ? $error : null,
            'sent_at' => $sentAt,
            'created_at' => $createdAt,
            'can_resend' => $this->canResendFailed($provider, $eventType, $status),
        ];
    }
}
