<?php

namespace App\Services\Versell;

/**
 * Status Cash Out Versell → paid / failed / pending.
 */
final class VersellPayoutStatuses
{
    public static function isPaidStatus(string $status): bool
    {
        $status = strtoupper(trim($status));

        // LIQUIDATED = status real do webhook TRANSFER da Versell quando o PIX liquidou.
        return in_array($status, [
            'LIQUIDATED',
            'SETTLED',
            'PAID',
            'COMPLETED',
            'SUCCESS',
            'SUCCEEDED',
        ], true);
    }

    public static function isFailedStatus(string $status): bool
    {
        $status = strtoupper(trim($status));

        return in_array($status, [
            'CANCELED',
            'CANCELLED',
            'FAILED',
            'REJECTED',
            'DENIED',
            'ERROR',
        ], true);
    }

    public static function isPendingStatus(string $status): bool
    {
        $status = strtoupper(trim($status));

        return in_array($status, [
            'ON_QUEUE',
            'PROCESSING',
            'WAITING_APPROVAL',
            'WAITING_CONFIRMATION',
            'PENDING',
            '',
        ], true) || (! self::isPaidStatus($status) && ! self::isFailedStatus($status) && ! self::isRefundedStatus($status));
    }

    public static function isRefundedStatus(string $status): bool
    {
        $status = strtoupper(trim($status));

        return in_array($status, ['REFUNDED', 'PARTIALLY_REFUNDED'], true);
    }

    /**
     * @return 'paid'|'failed'|'pending'|'refunded'|null
     */
    public static function mapToLocal(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $status = trim($status);
        if ($status === '') {
            return null;
        }
        if (self::isPaidStatus($status)) {
            return 'paid';
        }
        if (self::isFailedStatus($status)) {
            return 'failed';
        }
        if (self::isRefundedStatus($status)) {
            return 'refunded';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function statusFromPayload(array $payload): string
    {
        foreach (['status', 'paymentStatus', 'payment_status', 'state'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return strtoupper(trim($v));
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['status', 'paymentStatus', 'payment_status'] as $key) {
                $v = $data[$key] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return strtoupper(trim($v));
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function endToEndIdFromPayload(array $payload): string
    {
        foreach (['endToEndId', 'end_to_end_id', 'e2eId', 'e2eid', 'id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['endToEndId', 'end_to_end_id', 'e2eId', 'id'] as $key) {
                $v = $data[$key] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function idempotencyKeyFromPayload(array $payload): string
    {
        foreach (['idempotencyKey', 'idempotency_key', 'x-idempotency-key'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['idempotencyKey', 'idempotency_key'] as $key) {
                $v = $data[$key] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return '';
    }
}
