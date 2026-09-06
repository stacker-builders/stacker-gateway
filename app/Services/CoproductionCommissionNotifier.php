<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Support\Collection;

class CoproductionCommissionNotifier
{
    public function __construct(
        protected PanelPushService $panelPushService,
    ) {}

    /**
     * @param  Collection<int, \App\Models\WalletTransaction>  $transactions
     */
    public function notifyNewCommission(Order $order, int $coproducerTenantId, Collection $transactions): void
    {
        if ($coproducerTenantId < 1 || $transactions->isEmpty()) {
            return;
        }

        $user = User::query()->find($coproducerTenantId);
        if ($user === null) {
            return;
        }

        $order->loadMissing(['product']);
        $productName = $order->product?->name ?? 'Produto';
        $net = round((float) $transactions->sum('amount_net'), 2);
        $title = 'Nova comissão de co-produção';
        $body = $productName.' — R$ '.number_format($net, 2, ',', '.');
        $url = url('/vendas?q='.$order->id);
        $eventKey = 'coproduction_sale_'.$order->id.'_'.$coproducerTenantId;

        PanelNotification::firstOrCreate(
            [
                'user_id' => $user->id,
                'event_key' => $eventKey,
            ],
            [
                'tenant_id' => $coproducerTenantId,
                'type' => 'coproduction_sale_approved',
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ],
        );

        $this->panelPushService->sendAndPersistToTenant(
            $coproducerTenantId,
            'coproduction_sale_approved',
            $title,
            $body,
            $url,
            $eventKey,
        );
    }
}
