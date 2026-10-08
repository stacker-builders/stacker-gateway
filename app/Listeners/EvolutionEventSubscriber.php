<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Models\CheckoutSession;
use App\Models\EvolutionMessageDispatch;
use App\Models\Order;
use App\Services\Evolution\EvolutionAccountResolver;
use App\Services\Evolution\EvolutionClient;
use App\Services\Evolution\EvolutionDispatcher;
use App\Services\Uazapi\UazapiMessageBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class EvolutionEventSubscriber
{
    public function __construct(
        private EvolutionDispatcher $dispatcher,
        private UazapiMessageBuilder $messageBuilder,
        private EvolutionClient $client,
        private EvolutionAccountResolver $resolver,
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

        EvolutionMessageDispatch::cancelPendingForOrder((int) $order->id, $sessionId !== null ? (int) $sessionId : null);

        $phone = $this->resolveOrderPhone($order);
        $normalized = $phone !== null ? $this->client->normalizePhone($phone) : null;
        if ($normalized !== null && $order->tenant_id !== null && $order->product_id) {
            EvolutionMessageDispatch::cancelPendingCartForOwnedProduct(
                (int) $order->tenant_id,
                $normalized,
                $order->product_id
            );
        }

        $this->dispatchOrderPaid($order);
    }

    private function dispatchOrderPaid(Order $order): void
    {
        $tenantId = $order->tenant_id !== null ? (int) $order->tenant_id : null;
        if ($tenantId === null || $order->paymentMethodReportKey() !== 'pix') {
            return;
        }

        $instance = $this->resolver->resolveForPaidOrder($order);
        if (! $instance) {
            return;
        }

        $phone = $this->resolveOrderPhone($order);
        if ($phone === null) {
            Log::debug('EvolutionEventSubscriber: order_paid skipped (sem telefone)', ['order_id' => $order->id]);

            return;
        }

        if ($this->dispatcher->dispatchOrderPaid($instance, $order, $phone, $this->messageBuilder->fromOrder($order))) {
            Log::info('EvolutionEventSubscriber: order_paid enfileirado', ['order_id' => $order->id]);
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
            Log::debug('EvolutionEventSubscriber: pix_generated skipped (sem telefone)', ['order_id' => $order->id]);

            return;
        }

        $vars = $this->messageBuilder->fromOrder($order, $event->pixData);

        if ($this->dispatcher->dispatchPixGenerated($instance, $order, $phone, $vars)) {
            Log::info('EvolutionEventSubscriber: pix_generated enfileirado', ['order_id' => $order->id]);
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
