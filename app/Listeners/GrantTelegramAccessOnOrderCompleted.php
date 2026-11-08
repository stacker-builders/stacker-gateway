<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\Telegram\TelegramAccessGrantService;
use Illuminate\Support\Facades\Log;

/**
 * Concede direito de acesso Telegram após pagamento confirmado.
 * A vigência inicia neste momento; convites são processados de forma assíncrona.
 */
class GrantTelegramAccessOnOrderCompleted
{
    public function __construct(private TelegramAccessGrantService $grants) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;

        try {
            $created = $this->grants->grantFromOrder($order, true);
            if ($created !== []) {
                Log::info('telegram.access.granted_from_order', [
                    'order_id' => $order->id,
                    'grants' => collect($created)->pluck('id')->all(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('telegram.access.grant_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
