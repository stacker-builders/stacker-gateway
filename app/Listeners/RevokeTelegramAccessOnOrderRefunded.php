<?php

namespace App\Listeners;

use App\Events\OrderRefunded;
use App\Services\Telegram\TelegramAccessRevocationService;
use Illuminate\Support\Facades\Log;

/**
 * Reembolso confirmado (integral ou chargeback perdido via refundPaidOrDisputed).
 */
class RevokeTelegramAccessOnOrderRefunded
{
    public function __construct(private TelegramAccessRevocationService $revocation) {}

    public function handle(OrderRefunded $event): void
    {
        $order = $event->order;
        $meta = is_array($order->metadata) ? $order->metadata : [];

        try {
            // Reembolso parcial: não expulsa; registra para revisão.
            if ($this->looksPartial($order, $meta)) {
                $this->revocation->markDisputeUnderReview($order);
                Log::info('telegram.access.partial_refund_recorded', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            $reason = $this->resolveReason($meta);
            $this->revocation->revokeForOrder(
                $order,
                $reason,
                (string) ($meta['refund_reason'] ?? $meta['chargeback_reason'] ?? $reason)
            );
        } catch (\Throwable $e) {
            Log::error('telegram.access.revoke_on_refund_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function looksPartial($order, array $meta): bool
    {
        $refunded = $meta['refunded_amount'] ?? $meta['partial_refund_amount'] ?? $meta['manual_refund']['amount'] ?? null;
        if ($refunded === null) {
            return false;
        }
        $orderAmount = (float) $order->amount;
        $refundedAmount = (float) $refunded;

        return $orderAmount > 0 && $refundedAmount > 0 && $refundedAmount + 0.009 < $orderAmount;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveReason(array $meta): string
    {
        $hint = strtolower((string) ($meta['refund_source'] ?? $meta['debit_reason'] ?? $meta['chargeback_outcome'] ?? ''));
        if (str_contains($hint, 'chargeback') || str_contains($hint, 'med')) {
            return TelegramAccessRevocationService::REASON_CHARGEBACK;
        }

        return TelegramAccessRevocationService::REASON_REFUND;
    }
}
