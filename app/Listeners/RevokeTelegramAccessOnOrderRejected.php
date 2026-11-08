<?php

namespace App\Listeners;

use App\Events\OrderRejected;
use App\Services\Telegram\TelegramAccessRevocationService;
use Illuminate\Support\Facades\Log;

class RevokeTelegramAccessOnOrderRejected
{
    public function __construct(private TelegramAccessRevocationService $revocation) {}

    public function handle(OrderRejected $event): void
    {
        try {
            $this->revocation->revokeForOrder(
                $event->order,
                TelegramAccessRevocationService::REASON_ORDER_REJECTED,
                'Pedido rejeitado após concessão de acesso'
            );
        } catch (\Throwable $e) {
            Log::error('telegram.access.revoke_on_reject_failed', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
