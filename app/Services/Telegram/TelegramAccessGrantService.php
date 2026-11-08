<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramDeliveryJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramAccessOrderLink;
use App\Models\TelegramBuyerIdentity;
use App\Models\TelegramCommunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramAccessGrantService
{
    public function __construct(
        private TelegramAccessEventLogger $events,
        private TelegramPolicy $policy,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('telegram_access_grants')
            && Schema::hasTable('telegram_access_communities');
    }

    /**
     * Cria direitos de acesso para todos os produtos Telegram do pedido (idempotente).
     *
     * @return list<TelegramAccessGrant>
     */
    public function grantFromOrder(Order $order, bool $dispatchDelivery = true): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $order->loadMissing(['product', 'orderItems.product']);
        $products = collect();
        if ($order->product) {
            $products->push($order->product);
        }
        foreach ($order->orderItems as $item) {
            if ($item->product) {
                $products->push($item->product);
            }
        }

        $grants = [];
        foreach ($products->unique('id') as $product) {
            /** @var Product $product */
            if (! $product->isTelegram()) {
                continue;
            }
            $grants[] = $this->grantForOrderProduct($order, $product, $dispatchDelivery);
        }

        return $grants;
    }

    public function grantForOrderProduct(Order $order, Product $product, bool $dispatchDelivery = true): TelegramAccessGrant
    {
        if (! $product->isTelegram()) {
            throw new InvalidArgumentException('Produto não é do tipo Telegram.');
        }

        if (! $this->policy->canMaintainExistingAccess((int) $order->tenant_id)) {
            throw new InvalidArgumentException('Manutenção de acesso Telegram indisponível para este tenant.');
        }

        // Idempotência por pedido+produto (compra ou renovação já vinculada).
        if (Schema::hasTable('telegram_access_order_links')) {
            $linkQuery = TelegramAccessOrderLink::query()->where('order_id', $order->id);
            if (Schema::hasColumn('telegram_access_order_links', 'product_id')) {
                $linkQuery->where('product_id', (string) $product->id);
            }
            $linked = $linkQuery->first();
            if ($linked) {
                $grant = TelegramAccessGrant::query()
                    ->with(['communities.community', 'product'])
                    ->find($linked->telegram_access_grant_id);
                if ($grant) {
                    if ($dispatchDelivery) {
                        ProcessTelegramDeliveryJob::dispatch((int) $grant->id);
                    }

                    return $grant;
                }
            }
        }

        $existing = TelegramAccessGrant::query()
            ->where('order_id', $order->id)
            ->where('product_id', (string) $product->id)
            ->first();

        if ($existing) {
            if ($dispatchDelivery) {
                ProcessTelegramDeliveryJob::dispatch((int) $existing->id);
            }

            return $existing->load(['communities.community', 'product']);
        }

        $setting = ProductTelegramSetting::query()
            ->where('product_id', (string) $product->id)
            ->with('communities')
            ->first();

        if (! $setting) {
            throw new InvalidArgumentException('Produto Telegram sem configuração de entrega.');
        }

        $renewal = app(TelegramAccessRenewalService::class);
        $extendable = $renewal->findExtendableGrant(
            (int) $order->tenant_id,
            (int) $order->user_id,
            (string) $product->id
        );

        // Renovação recorrente: sempre estende o direito existente.
        // Compra avulsa com acesso ainda válido: estende sem perder o restante.
        // Após vencimento sem flag de renovação: novo direito (sem misturar histórico).
        if ($extendable && ((bool) $order->is_renewal || $extendable->isCurrentlyValid())) {
            return $renewal->extendFromOrder($extendable, $order, $setting, $dispatchDelivery);
        }

        $paidAt = $this->resolvePaidAt($order);
        [$startsAt, $endsAt, $isLifetime] = $this->resolveAccessWindow($setting, $paidAt);

        $identity = TelegramBuyerIdentity::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('user_id', $order->user_id)
            ->first();

        $grant = DB::transaction(function () use ($order, $product, $setting, $paidAt, $startsAt, $endsAt, $isLifetime, $identity) {
            /** @var TelegramAccessGrant $grant */
            $grant = TelegramAccessGrant::query()->create([
                'tenant_id' => (int) $order->tenant_id,
                'order_id' => $order->id,
                'latest_order_id' => $order->id,
                'product_id' => (string) $product->id,
                'user_id' => (int) $order->user_id,
                'telegram_bot_id' => $setting->telegram_bot_id,
                'status' => $identity
                    ? TelegramAccessGrant::STATUS_PENDING_INVITE
                    : TelegramAccessGrant::STATUS_PENDING_IDENTITY,
                'delivery_status' => TelegramAccessGrant::DELIVERY_PENDING,
                // Persistidos como UTC wall-clock (sem conversão pelo timezone da app).
                'paid_at' => $paidAt->utc()->format('Y-m-d H:i:s'),
                'access_starts_at' => $startsAt->utc()->format('Y-m-d H:i:s'),
                'access_ends_at' => $endsAt?->utc()->format('Y-m-d H:i:s'),
                'is_lifetime' => $isLifetime,
                'telegram_user_id' => $identity?->telegram_user_id,
                'telegram_username' => $identity?->telegram_username,
                'identity_linked_at' => $identity?->linked_at,
                'snapshot' => [
                    'access_duration' => $setting->access_duration,
                    'access_duration_unit' => $setting->access_duration_unit,
                    'is_lifetime' => $setting->is_lifetime,
                    'invite_expiration_hours' => $setting->invite_expiration_hours,
                    'welcome_message' => $setting->welcome_message,
                    'auto_remove_on_expiry' => $setting->auto_remove_on_expiry,
                    'community_ids' => $setting->communities->pluck('id')->values()->all(),
                ],
            ]);

            if (Schema::hasTable('telegram_access_order_links')) {
                $link = [
                    'tenant_id' => (int) $order->tenant_id,
                    'telegram_access_grant_id' => $grant->id,
                    'order_id' => $order->id,
                    'link_type' => TelegramAccessOrderLink::TYPE_PURCHASE,
                ];
                if (Schema::hasColumn('telegram_access_order_links', 'product_id')) {
                    $link['product_id'] = (string) $product->id;
                }
                TelegramAccessOrderLink::query()->create($link);
            }

            foreach ($setting->communities as $community) {
                /** @var TelegramCommunity $community */
                TelegramAccessCommunity::query()->create([
                    'tenant_id' => (int) $order->tenant_id,
                    'telegram_access_grant_id' => $grant->id,
                    'telegram_community_id' => $community->id,
                    'telegram_chat_id' => $community->telegram_chat_id,
                    'status' => TelegramAccessCommunity::STATUS_PENDING,
                ]);
            }

            $this->events->record($grant, TelegramAccessEvent::PAYMENT_CONFIRMED, [
                'order_id' => $order->id,
                'paid_at' => $paidAt->toIso8601String(),
            ], null, $paidAt);

            $this->events->record($grant, TelegramAccessEvent::ACCESS_GRANTED, [
                'access_starts_at' => $startsAt->toIso8601String(),
                'access_ends_at' => $endsAt?->toIso8601String(),
                'is_lifetime' => $isLifetime,
                'communities_count' => $setting->communities->count(),
            ], null, $paidAt);

            if ($identity) {
                $this->events->record($grant, TelegramAccessEvent::IDENTITY_LINKED, [
                    'telegram_user_id' => $identity->telegram_user_id,
                    'telegram_username' => $identity->telegram_username,
                    'source' => 'existing_identity',
                ]);
            }

            return $grant;
        });

        if ($dispatchDelivery) {
            ProcessTelegramDeliveryJob::dispatch((int) $grant->id);
        }

        return $grant->load(['communities.community', 'product']);
    }

    /**
     * Vigência: começa no pagamento e não muda se a entrada for posterior.
     *
     * @return array{0: Carbon, 1: Carbon|null, 2: bool}
     */
    public function resolveAccessWindow(ProductTelegramSetting $setting, Carbon $paidAt): array
    {
        $startsAt = $paidAt->copy()->utc();
        if ($setting->is_lifetime) {
            return [$startsAt, null, true];
        }

        $days = max(1, (int) ($setting->access_duration ?? 30));
        $endsAt = $startsAt->copy()->addDays($days);

        return [$startsAt, $endsAt, false];
    }

    public function resolvePaidAt(Order $order): Carbon
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        foreach (['paid_at', 'payment_confirmed_at', 'completed_at'] as $key) {
            if (! empty($meta[$key])) {
                return Carbon::parse($meta[$key])->utc();
            }
        }

        if ($order->period_start) {
            return Carbon::parse($order->period_start)->utc();
        }

        return ($order->updated_at ?? $order->created_at ?? now())->copy()->utc();
    }

    public function linkIdentity(
        TelegramAccessGrant $grant,
        int $telegramUserId,
        ?string $username = null,
        ?string $firstName = null,
    ): TelegramAccessGrant {
        return DB::transaction(function () use ($grant, $telegramUserId, $username, $firstName) {
            $locked = TelegramAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            TelegramBuyerIdentity::query()->updateOrCreate(
                [
                    'tenant_id' => $locked->tenant_id,
                    'user_id' => $locked->user_id,
                ],
                [
                    'telegram_user_id' => $telegramUserId,
                    'telegram_username' => $username,
                    'telegram_first_name' => $firstName,
                    'linked_at' => now()->utc(),
                ]
            );

            $wasLinked = $locked->telegram_user_id !== null;
            $locked->forceFill([
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $username,
                'identity_linked_at' => $locked->identity_linked_at ?? now()->utc(),
                // Invalida deep link anterior (anti reutilização / sequestro).
                'identity_link_nonce' => Str::lower(Str::random(24)),
            ])->save();

            if (! $wasLinked) {
                $this->events->record($locked, TelegramAccessEvent::IDENTITY_LINKED, [
                    'telegram_user_id' => $telegramUserId,
                    'telegram_username' => $username,
                ]);
            }

            $this->refreshGrantStatus($locked);

            return $locked->fresh(['communities.community', 'product']);
        });
    }

    public function refreshGrantStatus(TelegramAccessGrant $grant): TelegramAccessGrant
    {
        $grant->loadMissing('communities');

        // Grants terminais não são "ressuscitados" por convites antigos ou /start.
        if (in_array($grant->status, [
            TelegramAccessGrant::STATUS_REVOKED,
            TelegramAccessGrant::STATUS_EXPIRED,
        ], true)) {
            return $grant;
        }

        $communities = $grant->communities->where('status', '!=', TelegramAccessCommunity::STATUS_REVOKED);
        $total = $communities->count();
        $joined = $communities->where('status', TelegramAccessCommunity::STATUS_JOINED)->count();
        $failed = $communities->where('status', TelegramAccessCommunity::STATUS_FAILED)->count();
        $inviteReady = $communities->whereIn('status', [
            TelegramAccessCommunity::STATUS_INVITE_READY,
            TelegramAccessCommunity::STATUS_AWAITING_JOIN_REQUEST,
        ])->count();

        if ($total === 0) {
            // Todas revoked ou sem comunidades: não altera status de negócio se ainda houver rows.
            if ($grant->communities->isEmpty()) {
                $grant->forceFill([
                    'status' => TelegramAccessGrant::STATUS_FAILED,
                    'delivery_status' => TelegramAccessGrant::DELIVERY_FAILED,
                    'last_error' => 'Nenhuma comunidade vinculada ao direito de acesso.',
                ])->save();
            }

            return $grant->fresh(['communities.community', 'product']);
        }

        $previousDelivery = $grant->delivery_status;

        if ($joined === $total) {
            $grant->forceFill([
                'status' => TelegramAccessGrant::STATUS_COMPLETED,
                'delivery_status' => TelegramAccessGrant::DELIVERY_COMPLETED,
                'last_error' => null,
            ])->save();
            if ($previousDelivery !== TelegramAccessGrant::DELIVERY_COMPLETED) {
                $this->events->record($grant, TelegramAccessEvent::DELIVERY_COMPLETED, [
                    'joined' => $joined,
                    'total' => $total,
                ]);
            }
        } elseif ($joined > 0) {
            $grant->forceFill([
                'status' => TelegramAccessGrant::STATUS_PARTIAL,
                'delivery_status' => TelegramAccessGrant::DELIVERY_PARTIAL,
            ])->save();
            if ($previousDelivery !== TelegramAccessGrant::DELIVERY_PARTIAL) {
                $this->events->record($grant, TelegramAccessEvent::DELIVERY_PARTIAL, [
                    'joined' => $joined,
                    'total' => $total,
                ]);
            }
        } elseif ($failed === $total) {
            $grant->forceFill([
                'status' => TelegramAccessGrant::STATUS_FAILED,
                'delivery_status' => TelegramAccessGrant::DELIVERY_FAILED,
            ])->save();
        } elseif ($inviteReady > 0 || $communities->whereNotNull('invite_link')->isNotEmpty()) {
            $grant->forceFill([
                'status' => $grant->hasIdentity()
                    ? TelegramAccessGrant::STATUS_PENDING_ENTRY
                    : TelegramAccessGrant::STATUS_PENDING_IDENTITY,
                'delivery_status' => TelegramAccessGrant::DELIVERY_PENDING,
            ])->save();
        } else {
            $grant->forceFill([
                'status' => $grant->hasIdentity()
                    ? TelegramAccessGrant::STATUS_PENDING_INVITE
                    : TelegramAccessGrant::STATUS_PENDING_IDENTITY,
                'delivery_status' => TelegramAccessGrant::DELIVERY_PENDING,
            ])->save();
        }

        return $grant->fresh(['communities.community', 'product']);
    }

    public function findForBuyer(int $userId, int $grantId): ?TelegramAccessGrant
    {
        return TelegramAccessGrant::query()
            ->where('user_id', $userId)
            ->whereKey($grantId)
            ->with([
                'bot',
                'communities.community',
                'product',
                'events' => fn ($q) => $q->orderByDesc('occurred_at'),
            ])
            ->first();
    }

    public function findByOrderProduct(int $orderId, string $productId): ?TelegramAccessGrant
    {
        return TelegramAccessGrant::query()
            ->where('order_id', $orderId)
            ->where('product_id', $productId)
            ->with(['communities.community', 'product'])
            ->first();
    }
}
