<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramDeliveryJob;
use App\Jobs\ProcessTelegramWebhookJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramWebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcilia entregas Telegram órfãs sem duplicar direitos nem remover membros.
 */
class TelegramDeliveryReconcileService
{
    public function __construct(
        private TelegramAccessGrantService $grants,
        private TelegramPolicy $policy,
    ) {}

    /**
     * @return array{
     *   orders_without_grant: int,
     *   grants_without_delivery: int,
     *   identity_without_invite: int,
     *   failed_requeued: int,
     *   webhooks_requeued: int,
     *   skipped: int
     * }
     */
    public function run(int $limit = 100): array
    {
        $stats = [
            'orders_without_grant' => 0,
            'grants_without_delivery' => 0,
            'identity_without_invite' => 0,
            'failed_requeued' => 0,
            'webhooks_requeued' => 0,
            'skipped' => 0,
        ];

        if (! Schema::hasTable('telegram_access_grants')) {
            return $stats;
        }

        $lock = Cache::lock('telegram-reconcile-deliveries', 55);
        if (! $lock->get()) {
            $stats['skipped'] = 1;

            return $stats;
        }

        try {
            $stats['orders_without_grant'] = $this->reconcileOrdersWithoutGrant($limit);
            $stats['grants_without_delivery'] = $this->reconcileGrantsNeedingDelivery($limit);
            $stats['identity_without_invite'] = $this->reconcileIdentityWithoutInvite($limit);
            $stats['failed_requeued'] = $this->reconcileFailedCommunities($limit);
            $stats['webhooks_requeued'] = $this->reconcileOrphanWebhooks($limit);
        } finally {
            $lock->release();
        }

        Log::info('telegram.reconcile.done', $stats);

        return $stats;
    }

    private function reconcileOrdersWithoutGrant(int $limit): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('products', 'type')) {
            return 0;
        }

        $orders = Order::query()
            ->where('status', 'completed')
            ->whereHas('product', fn ($q) => $q->where('type', Product::TYPE_TELEGRAM))
            ->whereDoesntHave('telegramAccessGrants')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            if (! $this->policy->canMaintainExistingAccess((int) $order->tenant_id)) {
                continue;
            }
            try {
                $created = $this->grants->grantFromOrder($order, true);
                if ($created !== []) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::warning('telegram.reconcile.order_grant_failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function reconcileGrantsNeedingDelivery(int $limit): int
    {
        $grants = TelegramAccessGrant::query()
            ->whereNotIn('status', [
                TelegramAccessGrant::STATUS_REVOKED,
                TelegramAccessGrant::STATUS_EXPIRED,
                TelegramAccessGrant::STATUS_COMPLETED,
            ])
            ->whereIn('delivery_status', [
                TelegramAccessGrant::DELIVERY_PENDING,
                TelegramAccessGrant::DELIVERY_PARTIAL,
                TelegramAccessGrant::DELIVERY_FAILED,
            ])
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($grants as $grant) {
            ProcessTelegramDeliveryJob::dispatch((int) $grant->id);
            $count++;
        }

        return $count;
    }

    private function reconcileIdentityWithoutInvite(int $limit): int
    {
        $grants = TelegramAccessGrant::query()
            ->whereNotNull('telegram_user_id')
            ->whereIn('status', [
                TelegramAccessGrant::STATUS_PENDING_INVITE,
                TelegramAccessGrant::STATUS_PENDING_ENTRY,
                TelegramAccessGrant::STATUS_PENDING_IDENTITY,
            ])
            ->whereHas('communities', function ($q) {
                $q->whereIn('status', [
                    TelegramAccessCommunity::STATUS_PENDING,
                    TelegramAccessCommunity::STATUS_FAILED,
                ]);
            })
            ->where('updated_at', '<=', now()->subMinutes(3))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($grants as $grant) {
            ProcessTelegramDeliveryJob::dispatch((int) $grant->id);
            $count++;
        }

        return $count;
    }

    private function reconcileFailedCommunities(int $limit): int
    {
        $rows = TelegramAccessCommunity::query()
            ->where('status', TelegramAccessCommunity::STATUS_FAILED)
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->whereHas('grant', function ($q) {
                $q->whereNotIn('status', [
                    TelegramAccessGrant::STATUS_REVOKED,
                    TelegramAccessGrant::STATUS_EXPIRED,
                ]);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $seen = [];
        $count = 0;
        foreach ($rows as $row) {
            $gid = (int) $row->telegram_access_grant_id;
            if (isset($seen[$gid])) {
                continue;
            }
            $seen[$gid] = true;
            ProcessTelegramDeliveryJob::dispatch($gid);
            $count++;
        }

        return $count;
    }

    private function reconcileOrphanWebhooks(int $limit): int
    {
        if (! Schema::hasTable('telegram_webhook_events')) {
            return 0;
        }

        $events = TelegramWebhookEvent::query()
            ->where('status', TelegramWebhookEvent::STATUS_RECEIVED)
            ->where('created_at', '<=', now()->subMinutes(3))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($events as $event) {
            ProcessTelegramWebhookJob::dispatch((int) $event->id);
            $count++;
        }

        return $count;
    }
}
