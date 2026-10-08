<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvolutionMessageDispatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id',
        'evolution_instance_id',
        'checkout_session_id',
        'order_id',
        'event_type',
        'sequence_step',
        'phone',
        'message',
        'payload',
        'status',
        'wa_status',
        'provider_message_id',
        'track_id',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(EvolutionInstance::class, 'evolution_instance_id');
    }

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<int, int>
     */
    public static function consumedStepIndicesForSession(int $sessionId, string $eventType = EvolutionInstance::EVENT_CART_RECOVERY): array
    {
        return static::query()
            ->where('checkout_session_id', $sessionId)
            ->where('event_type', $eventType)
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_FAILED])
            ->whereNotNull('sequence_step')
            ->pluck('sequence_step')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public static function hasPendingStepForSession(int $sessionId, int $stepIndex, string $eventType = EvolutionInstance::EVENT_CART_RECOVERY): bool
    {
        return static::query()
            ->where('checkout_session_id', $sessionId)
            ->where('event_type', $eventType)
            ->where('sequence_step', $stepIndex)
            ->where('status', self::STATUS_PENDING)
            ->exists();
    }

    public static function cancelPendingForOrder(int $orderId, ?int $checkoutSessionId = null): int
    {
        return static::query()
            ->where('status', self::STATUS_PENDING)
            ->where('event_type', '!=', EvolutionInstance::EVENT_ORDER_PAID)
            ->where(function ($query) use ($orderId, $checkoutSessionId) {
                $query->where('order_id', $orderId);
                if ($checkoutSessionId !== null) {
                    $query->orWhere('checkout_session_id', $checkoutSessionId);
                }
            })
            ->update([
                'status' => self::STATUS_CANCELED,
                'error' => 'Pedido pago — recuperação interrompida.',
            ]);
    }

    public static function alreadyQueuedForOrderStep(int $orderId, string $eventType, int $stepIndex): bool
    {
        return static::query()
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->where('sequence_step', $stepIndex)
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_PENDING])
            ->exists();
    }

    /**
     * @return array<int, int>
     */
    public static function consumedStepIndicesForOrder(int $orderId, string $eventType = EvolutionInstance::EVENT_PIX_GENERATED): array
    {
        return static::query()
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_FAILED])
            ->whereNotNull('sequence_step')
            ->pluck('sequence_step')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public static function hasPendingStepForOrder(int $orderId, int $stepIndex, string $eventType = EvolutionInstance::EVENT_PIX_GENERATED): bool
    {
        return static::query()
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->where('sequence_step', $stepIndex)
            ->where('status', self::STATUS_PENDING)
            ->exists();
    }

    public static function cancelPendingForPhone(int $instanceId, string $phone, string $reason): int
    {
        return static::query()
            ->where('evolution_instance_id', $instanceId)
            ->where('phone', $phone)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status' => self::STATUS_CANCELED,
                'error' => $reason,
            ]);
    }

    public static function cancelPendingCartForOwnedProduct(int $tenantId, string $normalizedPhone, string|int|null $productId): int
    {
        if ($productId === null || $productId === '') {
            return 0;
        }

        $variants = \App\Services\Whatsapp\WhatsappRecoveryGuard::phoneMatchVariants($normalizedPhone);
        if ($variants === []) {
            return 0;
        }

        $pending = static::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', EvolutionInstance::EVENT_CART_RECOVERY)
            ->where('status', self::STATUS_PENDING)
            ->whereHas('checkoutSession', fn ($query) => $query->where('product_id', $productId))
            ->get();

        $ids = $pending
            ->filter(function (self $dispatch) use ($variants) {
                $dispatchVariants = \App\Services\Whatsapp\WhatsappRecoveryGuard::phoneMatchVariants($dispatch->phone);

                return array_intersect($variants, $dispatchVariants) !== [];
            })
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return static::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => self::STATUS_CANCELED,
                'error' => 'Cliente já comprou este produto — recuperação interrompida.',
            ]);
    }
}
