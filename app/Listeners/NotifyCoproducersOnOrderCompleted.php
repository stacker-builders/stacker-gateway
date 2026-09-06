<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Models\WalletTransaction;
use App\Services\CoproductionCommissionNotifier;
use Illuminate\Support\Facades\Schema;

class NotifyCoproducersOnOrderCompleted
{
    public function __construct(
        protected CoproductionCommissionNotifier $notifier,
    ) {}

    public function handle(OrderCompleted $event): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('panel_notifications')) {
            return;
        }

        try {
            $transactions = WalletTransaction::query()
                ->where('order_id', $event->order->id)
                ->whereIn('type', [
                    WalletTransaction::TYPE_CREDIT_SALE,
                    WalletTransaction::TYPE_CREDIT_SALE_PENDING,
                ])
                ->where(function ($q) {
                    $q->where('meta->coproduction_role', 'coproducer')
                        ->orWhere('meta->coproduction', true)
                        ->orWhere('meta->coproduction', 1)
                        ->orWhere('meta->coproduction', 'true');
                })
                ->get();

            if ($transactions->isEmpty()) {
                return;
            }

            foreach ($transactions->groupBy('tenant_id') as $tenantId => $group) {
                $this->notifier->notifyNewCommission($event->order, (int) $tenantId, $group);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
