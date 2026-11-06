<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Services\MercadoPago\MercadoPagoCheckoutCompletionService;
use App\Support\GatewayPaymentCredentials;
use App\Support\MercadoPagoCredentialCandidates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileMercadoPagoPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-mercadopago
                            {--limit=100 : Máximo de pedidos para checar por execução}
                            {--days=45 : Considerar pedidos criados nos últimos X dias}
                            {--min-age-minutes=0 : Não checar pedidos atualizados muito recentemente}';

    protected $description = 'Reconcilia pagamentos PIX Mercado Pago pendentes (gateway_id + external_reference).';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        $minAgeMinutes = max(0, (int) $this->option('min-age-minutes'));

        $query = Order::query()
            ->where('status', 'pending')
            ->where('gateway', 'mercadopago')
            ->where('created_at', '>=', now()->subDays($days));

        if ($minAgeMinutes > 0) {
            $query->where('updated_at', '<=', now()->subMinutes($minAgeMinutes));
        }

        $orders = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $paid = 0;
        $skipped = 0;
        $skippedCredential = 0;

        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            $this->error('Mercado Pago driver não disponível.');

            return self::FAILURE;
        }

        $completion = app(MercadoPagoCheckoutCompletionService::class);
        $activeCredentials = GatewayCredential::activePaymentCredentialIndex();

        foreach ($orders as $order) {
            if ($this->isPastReconcileWindow($order)) {
                $skipped++;
                continue;
            }

            $checked++;
            $completion->applyPendingForOrder($order);
            $order->refresh();

            if ($order->status === 'completed') {
                $paid++;
                continue;
            }

            $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, 'mercadopago', $order);
            if ($credentials === null && MercadoPagoCredentialCandidates::forOrder($order) === []) {
                $skippedCredential++;
                $tenantId = $order->tenant_id !== null ? (int) $order->tenant_id : null;
                if (GatewayCredential::indexHasActivePaymentCredential($activeCredentials, $tenantId, 'mercadopago')) {
                    Log::warning('payments:reconcile-mercadopago skip', [
                        'order_id' => $order->id,
                        'reason' => 'credential_missing',
                        'tenant_id' => $order->tenant_id,
                    ]);
                }
                continue;
            }

            try {
                $approved = MercadoPagoCredentialCandidates::findApprovedPaymentForOrder($order, $driver);
            } catch (\Throwable $e) {
                Log::warning('payments:reconcile-mercadopago api_exception', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            if ($approved !== null) {
                $completion->applyPaid($order, $approved['payment_id'], [
                    'webhook_source' => 'reconcile_mercadopago',
                    'source' => 'reconcile_mercadopago',
                    'mp_credential_label' => $approved['label'],
                ]);
                $paid++;
            }
        }

        Cache::put('reconcile_heartbeat', now()->toIso8601String(), now()->addMinutes(30));
        Cache::put('reconcile_last_stats', [
            'command' => 'payments:reconcile-mercadopago',
            'checked' => $checked,
            'paid' => $paid,
            'skipped_window' => $skipped,
            'skipped_credential' => $skippedCredential,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        $this->info("Checados: {$checked} | Pagos: {$paid} | Ignorados (janela): {$skipped} | Sem credencial: {$skippedCredential}");

        return self::SUCCESS;
    }

    private function isPastReconcileWindow(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $until = $meta['reconcile_until'] ?? null;

        if (! is_string($until) || trim($until) === '') {
            return false;
        }

        try {
            return now()->greaterThan(\Illuminate\Support\Carbon::parse($until));
        } catch (\Throwable) {
            return false;
        }
    }
}
