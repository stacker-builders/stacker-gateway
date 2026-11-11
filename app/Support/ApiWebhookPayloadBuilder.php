<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Withdrawal;
use App\Services\OrderFeeBreakdownService;

final class ApiWebhookPayloadBuilder
{
    /**
     * Legacy-compatible order webhook payload (frozen fields).
     *
     * @return array<string, mixed>
     */
    public static function orderPayload(Order $order, string $eventName): array
    {
        $payload = [
            'event' => $eventName,
            'order_id' => $order->id,
            'amount' => (float) $order->amount,
            'amount_net' => OrderFeeBreakdownService::forOrder($order)['net'],
            'status' => $order->status,
            'email' => $order->email,
            'metadata' => $order->metadata ?? [],
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];

        $customer = WebhookCustomerPayload::fromOrder($order);
        if ($customer !== []) {
            $payload['customer'] = $customer;
        }

        if ($eventName === 'order.completed') {
            $payload['transaction_id'] = $order->gateway_id;
            $payload['payment_method'] = $order->payment_method;
            $payload['paid_at'] = $order->updated_at?->toIso8601String();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function withdrawalPayload(Withdrawal $withdrawal, string $eventName): array
    {
        return [
            'event' => $eventName,
            'withdrawal_id' => $withdrawal->id,
            'status' => $withdrawal->status,
            'amount' => (float) $withdrawal->amount,
            'fee_amount' => (float) $withdrawal->fee_amount,
            'net_amount' => (float) $withdrawal->net_amount,
            'bucket' => $withdrawal->bucket,
            'failed_reason' => $withdrawal->failed_reason,
            'notes' => $withdrawal->notes,
            'payout_provider' => $withdrawal->payout_provider,
            'payout_external_id' => $withdrawal->payout_external_id,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
            'updated_at' => $withdrawal->updated_at?->toIso8601String(),
        ];
    }
}
