<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class TelegramAccessGrant extends Model
{
    public const STATUS_PENDING_IDENTITY = 'pending_identity';

    public const STATUS_PENDING_INVITE = 'pending_invite';

    public const STATUS_PENDING_ENTRY = 'pending_entry';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_PARTIAL = 'partial';

    public const DELIVERY_COMPLETED = 'completed';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'latest_order_id',
        'product_id',
        'user_id',
        'telegram_bot_id',
        'status',
        'delivery_status',
        'paid_at',
        'access_starts_at',
        'access_ends_at',
        'is_lifetime',
        'renewal_count',
        'last_renewed_at',
        'expired_at',
        'revoked_at',
        'revoked_reason',
        'revoked_detail',
        'telegram_user_id',
        'telegram_username',
        'identity_link_nonce',
        'identity_linked_at',
        'last_error',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'access_starts_at' => 'datetime',
            'access_ends_at' => 'datetime',
            'is_lifetime' => 'boolean',
            'renewal_count' => 'integer',
            'last_renewed_at' => 'datetime',
            'expired_at' => 'datetime',
            'revoked_at' => 'datetime',
            'telegram_user_id' => 'integer',
            'identity_linked_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function communities(): HasMany
    {
        return $this->hasMany(TelegramAccessCommunity::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TelegramAccessEvent::class);
    }

    public function orderLinks(): HasMany
    {
        return $this->hasMany(TelegramAccessOrderLink::class);
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(TelegramAccessCompensation::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeNotTerminal(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_REVOKED, self::STATUS_EXPIRED]);
    }

    public function hasIdentity(): bool
    {
        return $this->telegram_user_id !== null;
    }

    public function isLifetime(): bool
    {
        return (bool) $this->is_lifetime;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isExpiredStatus(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCurrentlyValid(?Carbon $at = null): bool
    {
        if ($this->isRevoked() || $this->isExpiredStatus()) {
            return false;
        }
        if ($this->is_lifetime) {
            return true;
        }
        $ends = $this->utcMoment('access_ends_at');
        if ($ends === null) {
            return true;
        }
        $at = ($at ?? now())->utc();

        return $ends->greaterThan($at);
    }

    /**
     * Timestamps de vigência são persistidos em UTC (sem shift pelo timezone da app).
     */
    public function utcMoment(string $attribute): ?Carbon
    {
        $raw = $this->attributes[$attribute] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return Carbon::parse($raw, 'UTC');
    }

    public function displayInAppTimezone(string $attribute, string $format = 'd/m/Y H:i'): ?string
    {
        $utc = $this->utcMoment($attribute);
        if (! $utc) {
            return null;
        }

        return $utc->timezone((string) config('app.timezone', 'UTC'))->format($format);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBuyerArray(): array
    {
        $starts = $this->utcMoment('access_starts_at');
        $ends = $this->utcMoment('access_ends_at');
        $paid = $this->utcMoment('paid_at');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'delivery_status' => $this->delivery_status,
            'is_lifetime' => $this->is_lifetime,
            'paid_at' => $paid?->toIso8601String(),
            'paid_at_label' => $this->displayInAppTimezone('paid_at'),
            'access_starts_at' => $starts?->toIso8601String(),
            'access_starts_at_label' => $this->displayInAppTimezone('access_starts_at'),
            'access_ends_at' => $ends?->toIso8601String(),
            'access_ends_at_label' => $this->is_lifetime ? null : $this->displayInAppTimezone('access_ends_at'),
            'telegram_username' => $this->telegram_username,
            'has_identity' => $this->hasIdentity(),
            'identity_bot_url' => $this->hasIdentity()
                ? null
                : \App\Services\Telegram\TelegramIdentityLink::botUrl($this, $this->relationLoaded('bot') ? $this->bot : $this->bot()->first()),
            'product' => $this->relationLoaded('product') && $this->product
                ? ['id' => $this->product->id, 'name' => $this->product->name]
                : null,
            'communities' => $this->relationLoaded('communities')
                ? $this->communities->map(fn (TelegramAccessCommunity $c) => $c->toBuyerArray())->values()->all()
                : [],
            'access_starts_notice' => TelegramAccessGrant::accessStartsNotice(),
        ];
    }

    public static function accessStartsNotice(): string
    {
        return 'O prazo de acesso começa a contar após a confirmação do pagamento, independentemente da data de entrada no grupo ou canal.';
    }
}
