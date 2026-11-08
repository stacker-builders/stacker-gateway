<?php

namespace App\Listeners;

use App\Events\SubscriptionCancelled;
use App\Models\TelegramAccessGrant;
use App\Services\Telegram\TelegramAccessRevocationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelamento de renovação futura: acesso permanece até o fim do período contratado.
 */
class HandleTelegramAccessOnSubscriptionCancelled
{
    public function __construct(private TelegramAccessRevocationService $revocation) {}

    public function handle(SubscriptionCancelled $event): void
    {
        if (! Schema::hasTable('telegram_access_grants')) {
            return;
        }

        $subscription = $event->subscription;
        $userId = (int) ($subscription->user_id ?? 0);
        $productId = (string) ($subscription->product_id ?? '');
        if ($userId <= 0 || $productId === '') {
            return;
        }

        try {
            $grants = TelegramAccessGrant::query()
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->whereNotIn('status', [
                    TelegramAccessGrant::STATUS_REVOKED,
                    TelegramAccessGrant::STATUS_EXPIRED,
                ])
                ->get();

            foreach ($grants as $grant) {
                $this->revocation->cancelFutureRenewalOnly(
                    $grant,
                    'Assinatura cancelada — sem remoção imediata'
                );
            }
        } catch (\Throwable $e) {
            Log::error('telegram.access.subscription_cancel_failed', [
                'subscription_id' => $subscription->id ?? null,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
