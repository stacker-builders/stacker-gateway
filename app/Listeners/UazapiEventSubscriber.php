<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Services\Uazapi\UazapiAccountResolver;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiDispatcher;
use App\Services\Uazapi\UazapiLabelService;
use App\Services\Uazapi\UazapiMessageBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class UazapiEventSubscriber
{
    public function __construct(
        private UazapiDispatcher $dispatcher,
        private UazapiMessageBuilder $messageBuilder,
        private UazapiLabelService $labels,
        private UazapiClient $client,
        private UazapiAccountResolver $resolver,
    ) {}

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCompleted::class => 'handleOrderCompleted',
            PixGenerated::class => 'handlePixGenerated',
        ];
    }

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order->fresh() ?? $event->order;
        $sessionId = CheckoutSession::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->value('id');

        UazapiMessageDispatch::cancelPendingForOrder((int) $order->id, $sessionId !== null ? (int) $sessionId : null);

        $tenantId = $order->tenant_id !== null ? (int) $order->tenant_id : null;
        if ($tenantId === null) {
            return;
        }

        $phone = $this->resolveOrderPhone($order);
        $normalized = $phone !== null ? $this->client->normalizePhone($phone) : null;
        if ($normalized !== null && $order->product_id) {
            UazapiMessageDispatch::cancelPendingCartForOwnedProduct(
                $tenantId,
                $normalized,
                $order->product_id
            );
        }

        $instanceIds = $this->resolver->instanceIdsForOrderOrSession(
            (int) $order->id,
            $sessionId !== null ? (int) $sessionId : null
        );
        if ($phone && $instanceIds !== []) {
            $normalizedForLabel = $this->client->normalizePhone($phone);
            if ($normalizedForLabel) {
                $instances = UazapiInstance::query()->whereIn('id', $instanceIds)->get();
                foreach ($instances as $instance) {
                    $this->labels->apply($instance, $normalizedForLabel, UazapiLabelService::PAID);
                }
            }
        }
    }

    public function handlePixGenerated(PixGenerated $event): void
    {
        $order = $event->order->fresh() ?? $event->order;
        $tenantId = $order->tenant_id !== null ? (int) $order->tenant_id : null;
        if ($tenantId === null) {
            return;
        }

        $instance = $this->resolver->resolveForOrder($order);
        if (! $instance) {
            return;
        }

        $phone = $this->resolveOrderPhone($order);
        if ($phone === null) {
            Log::debug('UazapiEventSubscriber: pix_generated skipped (sem telefone)', ['order_id' => $order->id]);

            return;
        }

        $vars = $this->messageBuilder->fromOrder($order, $event->pixData);

        if ($this->dispatcher->dispatchPixGenerated($instance, $order, $phone, $vars)) {
            Log::info('UazapiEventSubscriber: pix_generated enfileirado', ['order_id' => $order->id]);
        }
    }

    private function resolveOrderPhone(Order $order): ?string
    {
        $phone = trim((string) ($order->phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $metaPhone = trim((string) ($metadata['phone'] ?? $metadata['customer_phone'] ?? ''));

        return $metaPhone !== '' ? $metaPhone : null;
    }
}
