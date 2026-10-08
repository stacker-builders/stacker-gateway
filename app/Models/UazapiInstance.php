<?php

namespace App\Models;

use App\Support\UazapiCartRecoverySteps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UazapiInstance extends Model
{
    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTING = 'connecting';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_HIBERNATED = 'hibernated';

    public const EVENT_CART_RECOVERY = 'cart_recovery';

    public const EVENT_PIX_GENERATED = 'pix_generated';

    public const EVENT_ORDER_PAID = 'order_paid';

    public const EVENT_CAMPAIGN = 'campaign';

    protected $fillable = [
        'tenant_id',
        'name',
        'server_url',
        'instance_id',
        'instance_name',
        'instance_token',
        'webhook_secret',
        'status',
        'phone',
        'profile_name',
        'qrcode',
        'paircode',
        'is_active',
        'is_default',
        'cart_recovery_enabled',
        'pix_recovery_enabled',
        'order_paid_enabled',
        'send_product_image',
        'cart_recovery_steps',
        'pix_recovery_steps',
        'message_pix',
        'message_order_paid',
        'last_error',
        'connected_at',
        'webhook_synced_at',
        'last_used_at',
        'label_map',
    ];

    protected function casts(): array
    {
        return [
            'instance_token' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'cart_recovery_enabled' => 'boolean',
            'pix_recovery_enabled' => 'boolean',
            'order_paid_enabled' => 'boolean',
            'send_product_image' => 'boolean',
            'cart_recovery_steps' => 'array',
            'pix_recovery_steps' => 'array',
            'label_map' => 'array',
            'connected_at' => 'datetime',
            'webhook_synced_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(UazapiMessageDispatch::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'uazapi_instance_product')
            ->withTimestamps();
    }

    /**
     * @return list<string>
     */
    public function linkedProductIds(): array
    {
        if ($this->relationLoaded('products')) {
            return $this->products->pluck('id')->map(fn ($id) => (string) $id)->unique()->values()->all();
        }

        return $this->products()->pluck('products.id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    public function appliesToProduct(?string $productId): bool
    {
        $linked = $this->linkedProductIds();
        if ($linked === []) {
            return true;
        }
        if ($productId === null || $productId === '') {
            return false;
        }

        return in_array((string) $productId, $linked, true);
    }

    public function appliesToOrder(Order $order): bool
    {
        $linked = $this->linkedProductIds();
        if ($linked === []) {
            return true;
        }

        $order->loadMissing('orderItems');
        $candidates = collect([$order->product_id])
            ->merge($order->orderItems->pluck('product_id'))
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        return $candidates !== [] && count(array_intersect($linked, $candidates)) > 0;
    }

    public static function forTenant(int $tenantId): ?self
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public static function firstOrNewForTenant(int $tenantId): self
    {
        $existing = static::forTenant($tenantId);
        if ($existing) {
            return $existing;
        }

        return static::newForTenant($tenantId);
    }

    public static function newForTenant(int $tenantId): self
    {
        $template = static::forTenant($tenantId);
        $instance = new static;
        $instance->tenant_id = $tenantId;
        $instance->name = $template ? 'Nova conta' : 'Conta principal';
        $instance->webhook_secret = Str::lower(Str::random(48));
        $instance->status = self::STATUS_DISCONNECTED;
        $instance->is_active = true;
        $instance->is_default = $template === null;
        $instance->cart_recovery_enabled = (bool) ($template?->cart_recovery_enabled ?? false);
        $instance->pix_recovery_enabled = (bool) ($template?->pix_recovery_enabled ?? false);
        $instance->order_paid_enabled = (bool) ($template?->order_paid_enabled ?? false);
        $instance->send_product_image = $template?->send_product_image ?? true;
        $instance->cart_recovery_steps = $template?->cart_recovery_steps ?: UazapiCartRecoverySteps::defaults();
        $instance->pix_recovery_steps = $template?->pix_recovery_steps ?: UazapiCartRecoverySteps::pixDefaults();
        $instance->message_pix = (string) ($template?->message_pix ?: (config('uazapi.defaults.messages.pix_generated') ?? ''));
        $instance->message_order_paid = (string) ($template?->message_order_paid ?: (config('uazapi.defaults.messages.order_paid') ?? ''));

        return $instance;
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        if (is_string($this->profile_name) && trim($this->profile_name) !== '') {
            return trim($this->profile_name);
        }

        if (is_string($this->phone) && trim($this->phone) !== '') {
            return trim($this->phone);
        }

        return 'Conta WhatsApp';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'status' => $this->status,
            'phone' => $this->phone,
            'profile_name' => $this->profile_name,
            'server_url' => (string) ($this->server_url ?? ''),
            'has_credentials' => $this->hasCredentials(),
            'connected' => $this->isConnected(),
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'product_ids' => $this->linkedProductIds(),
            'last_error' => $this->last_error,
        ];
    }

    public function hasCredentials(): bool
    {
        return is_string($this->server_url) && trim($this->server_url) !== ''
            && is_string($this->instance_token) && trim($this->instance_token) !== '';
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && $this->hasCredentials();
    }

    public function canSendRecovery(): bool
    {
        return $this->is_active && $this->isConnected();
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public function cartRecoverySteps(): array
    {
        return UazapiCartRecoverySteps::forInstance($this);
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public function pixRecoverySteps(): array
    {
        return UazapiCartRecoverySteps::forPixInstance($this);
    }

    public function pixMessageTemplate(): string
    {
        $message = trim((string) ($this->message_pix ?? ''));
        if ($message !== '') {
            return $message;
        }

        return (string) (config('uazapi.defaults.messages.pix_generated') ?? '');
    }

    public function orderPaidMessageTemplate(): string
    {
        $message = trim((string) ($this->message_order_paid ?? ''));
        if ($message !== '') {
            return $message;
        }

        return (string) (config('uazapi.defaults.messages.order_paid') ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeQr = true): array
    {
        $connecting = in_array($this->status, [self::STATUS_CONNECTING, self::STATUS_DISCONNECTED], true);

        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'is_default' => (bool) $this->is_default,
            'status' => $this->status,
            'phone' => $this->phone,
            'profile_name' => $this->profile_name,
            'qrcode' => $includeQr && $connecting ? $this->qrcode : null,
            'paircode' => $includeQr && $connecting ? $this->paircode : null,
            'server_url' => (string) ($this->server_url ?? ''),
            'has_token' => is_string($this->instance_token) && trim($this->instance_token) !== '',
            'has_credentials' => $this->hasCredentials(),
            'is_active' => (bool) $this->is_active,
            'product_ids' => $this->linkedProductIds(),
            'cart_recovery_enabled' => (bool) $this->cart_recovery_enabled,
            'pix_recovery_enabled' => (bool) $this->pix_recovery_enabled,
            'order_paid_enabled' => (bool) $this->order_paid_enabled,
            'send_product_image' => (bool) $this->send_product_image,
            'cart_recovery_steps' => UazapiCartRecoverySteps::toUiSteps($this),
            'pix_recovery_steps' => UazapiCartRecoverySteps::toUiPixSteps($this),
            'message_pix' => $this->pixMessageTemplate(),
            'message_order_paid' => $this->orderPaidMessageTemplate(),
            'last_error' => $this->last_error,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'connected' => $this->isConnected(),
            'has_instance' => $this->hasCredentials(),
        ];
    }
}
