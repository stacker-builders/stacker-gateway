<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramDeliveryJob;
use App\Models\Order;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramAccessOrderLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TelegramAccessRenewalService
{
    public function __construct(
        private TelegramAccessEventLogger $events,
        private TelegramAccessGrantService $grants,
        private TelegramAccessNotificationService $notifications,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('telegram_access_order_links')
            && Schema::hasColumn('telegram_access_grants', 'renewal_count');
    }

    public function findExtendableGrant(int $tenantId, int $userId, string $productId): ?TelegramAccessGrant
    {
        return TelegramAccessGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where('status', '!=', TelegramAccessGrant::STATUS_REVOKED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Estende o grant existente. Idempotente por order_id via telegram_access_order_links.
     */
    public function extendFromOrder(
        TelegramAccessGrant $grant,
        Order $order,
        ProductTelegramSetting $setting,
        bool $dispatchDelivery = true,
    ): TelegramAccessGrant {
        if (! $this->tablesReady()) {
            return $grant;
        }

        $existingLink = TelegramAccessOrderLink::query()
            ->where('order_id', $order->id)
            ->where('product_id', (string) $grant->product_id)
            ->first();
        if ($existingLink) {
            return TelegramAccessGrant::query()
                ->with(['communities.community', 'product'])
                ->findOrFail($existingLink->telegram_access_grant_id);
        }

        $paidAt = $this->grants->resolvePaidAt($order);
        $isRenewalFlag = (bool) $order->is_renewal;
        $linkType = $isRenewalFlag
            ? TelegramAccessOrderLink::TYPE_RENEWAL
            : TelegramAccessOrderLink::TYPE_EXTENSION;

        $updated = DB::transaction(function () use ($grant, $order, $setting, $paidAt, $linkType, $isRenewalFlag) {
            $locked = TelegramAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            // Corrida renovação x expiração: lock impede perda de extensão.
            if ($locked->status === TelegramAccessGrant::STATUS_REVOKED) {
                return $locked;
            }

            $again = TelegramAccessOrderLink::query()
                ->where('order_id', $order->id)
                ->where('product_id', (string) $locked->product_id)
                ->first();
            if ($again) {
                return $locked;
            }

            $days = max(1, (int) ($setting->access_duration ?? ($locked->snapshot['access_duration'] ?? 30)));
            $wasValid = $locked->isCurrentlyValid($paidAt);
            $previousEnds = $locked->utcMoment('access_ends_at');

            if ($setting->is_lifetime || $locked->is_lifetime) {
                $newEnds = null;
                $isLifetime = true;
            } elseif ($wasValid && $previousEnds) {
                // Renovação com período restante: estende a partir do vencimento atual.
                $base = $previousEnds->greaterThan($paidAt) ? $previousEnds->copy() : $paidAt->copy();
                $newEnds = $base->addDays($days);
                $isLifetime = false;
            } else {
                // Após vencimento: sem retroatividade — novo ciclo a partir do pagamento.
                $newEnds = $paidAt->copy()->addDays($days);
                $isLifetime = false;
            }

            TelegramAccessOrderLink::query()->create([
                'tenant_id' => $locked->tenant_id,
                'telegram_access_grant_id' => $locked->id,
                'order_id' => $order->id,
                'product_id' => (string) $locked->product_id,
                'link_type' => $linkType,
            ]);

            // Reabre comunidades revogadas para nova entrega pós-vencimento/remoção.
            TelegramAccessCommunity::query()
                ->where('telegram_access_grant_id', $locked->id)
                ->where('status', TelegramAccessCommunity::STATUS_REVOKED)
                ->update([
                    'status' => TelegramAccessCommunity::STATUS_PENDING,
                    'invite_link' => null,
                    'invite_link_expires_at' => null,
                    'joined_at' => null,
                    'last_error' => null,
                ]);

            $locked->load('communities');

            $locked->forceFill([
                'latest_order_id' => $order->id,
                'access_ends_at' => $newEnds?->utc()->format('Y-m-d H:i:s'),
                'is_lifetime' => $isLifetime,
                'renewal_count' => (int) $locked->renewal_count + 1,
                'last_renewed_at' => $paidAt->utc()->format('Y-m-d H:i:s'),
                'expired_at' => null,
                'status' => $this->statusAfterRenewal($locked),
                'last_error' => null,
            ])->save();

            $this->events->record($locked, TelegramAccessEvent::ACCESS_RENEWED, [
                'order_id' => $order->id,
                'is_renewal_flag' => $isRenewalFlag,
                'link_type' => $linkType,
                'was_valid' => $wasValid,
                'previous_ends_at' => $previousEnds?->toIso8601String(),
                'new_ends_at' => $newEnds?->toIso8601String(),
                'days_added' => $isLifetime ? null : $days,
                'telegram_user_id' => $locked->telegram_user_id,
            ], null, $paidAt);

            return $locked;
        });

        $fresh = $updated->fresh(['communities.community', 'product']);

        // Já no grupo: não gera novo convite. Demais (incl. reabertos): reprocessa entrega.
        $needsInvite = $fresh->communities->contains(function (TelegramAccessCommunity $c) {
            return $c->status !== TelegramAccessCommunity::STATUS_JOINED;
        });

        if ($dispatchDelivery && $needsInvite) {
            ProcessTelegramDeliveryJob::dispatch((int) $fresh->id);
        }

        try {
            $this->notifications->accessRenewed($fresh);
        } catch (\Throwable) {
            // notificação não bloqueia renovação
        }

        return $fresh;
    }

    private function statusAfterRenewal(TelegramAccessGrant $grant): string
    {
        $grant->loadMissing('communities');
        $joined = $grant->communities->where('status', TelegramAccessCommunity::STATUS_JOINED)->count();
        $total = $grant->communities->count();

        if ($total > 0 && $joined === $total) {
            return TelegramAccessGrant::STATUS_COMPLETED;
        }
        if ($joined > 0) {
            return TelegramAccessGrant::STATUS_PARTIAL;
        }
        if ($grant->hasIdentity()) {
            return TelegramAccessGrant::STATUS_PENDING_ENTRY;
        }

        return TelegramAccessGrant::STATUS_PENDING_IDENTITY;
    }
}
