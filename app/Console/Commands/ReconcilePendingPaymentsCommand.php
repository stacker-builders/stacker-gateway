<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\GatewayPaymentCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-pending
                            {--limit=200 : Máximo de pedidos para checar por execução}
                            {--days=30 : Considerar pedidos criados nos últimos X dias}
                            {--min-age-minutes=2 : Não checar pedidos atualizados muito recentemente}
                            {--source= : Filtrar por metadata.source (ex.: pixgo)}';

    protected $description = 'Reconfirma pagamentos pendentes no gateway e aprova automaticamente quando liquidado.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        $minAgeMinutes = max(0, (int) $this->option('min-age-minutes'));
        $sourceFilter = trim((string) $this->option('source'));

        $query = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('gateway')
            ->where('gateway', '!=', '')
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->where('created_at', '>=', now()->subDays($days));

        if ($sourceFilter !== '') {
            $query->where('metadata->source', $sourceFilter);
        }

        if ($minAgeMinutes > 0) {
            $query->where('updated_at', '<=', now()->subMinutes($minAgeMinutes));
        }

        $activeCredentials = GatewayCredential::activePaymentCredentialIndex();
        $this->restrictToActiveAcquirers($query, $activeCredentials);

        $orders = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $paid = 0;
        $cancelled = 0;
        $skipped = 0;
        $skippedCredential = 0;
        $skippedDriver = 0;
        $apiErrors = 0;

        foreach ($orders as $order) {
            if ($this->isPastReconcileWindow($order)) {
                $skipped++;
                continue;
            }

            $checked++;

            $gatewaySlug = is_string($order->gateway) ? $order->gateway : '';
            $transactionId = is_string($order->gateway_id) ? $order->gateway_id : (string) $order->gateway_id;

            if ($gatewaySlug === '' || $transactionId === '') {
                Log::warning('payments:reconcile-pending skip', [
                    'order_id' => $order->id,
                    'reason' => 'missing_gateway_or_id',
                    'gateway' => $gatewaySlug,
                ]);
                continue;
            }

            $tenantId = $order->tenant_id !== null ? (int) $order->tenant_id : null;
            if (! GatewayCredential::indexHasActivePaymentCredential($activeCredentials, $tenantId, $gatewaySlug)) {
                $skippedCredential++;
                continue;
            }

            $credential = GatewayPaymentCredentials::resolve($order->tenant_id, $gatewaySlug, $order);

            if ($credential === null) {
                $skippedCredential++;
                Log::warning('payments:reconcile-pending skip', [
                    'order_id' => $order->id,
                    'reason' => 'credential_missing',
                    'gateway' => $gatewaySlug,
                    'tenant_id' => $order->tenant_id,
                ]);
                continue;
            }

            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                $skippedDriver++;
                Log::warning('payments:reconcile-pending skip', [
                    'order_id' => $order->id,
                    'reason' => 'driver_missing',
                    'gateway' => $gatewaySlug,
                ]);
                continue;
            }

            try {
                $apiStatus = $driver->getTransactionStatus($transactionId, $credential);
            } catch (\Throwable $e) {
                $apiErrors++;
                $apiStatus = null;
                Log::warning('payments:reconcile-pending api_exception', [
                    'order_id' => $order->id,
                    'gateway' => $gatewaySlug,
                    'transaction_id' => $transactionId,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($apiStatus === 'paid') {
                $paidEvent = $gatewaySlug === 'cajupay' ? 'checkout.payment.paid' : 'order.paid';
                $paidPayload = ['source' => 'reconcile_pending'];
                if ($gatewaySlug === 'cajupay') {
                    $paidPayload['webhook_source'] = 'reconcile_pending';
                }
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, $paidEvent, 'paid', $paidPayload);
                $paid++;
                continue;
            }

            if ($apiStatus === 'cancelled') {
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, 'order.cancelled', 'cancelled', [
                    'source' => 'reconcile_pending',
                ]);
                $cancelled++;
                continue;
            }

            if ($apiStatus !== null && $apiStatus !== 'pending') {
                Log::info('payments:reconcile-pending status', [
                    'order_id' => $order->id,
                    'gateway' => $gatewaySlug,
                    'api_status' => $apiStatus,
                ]);
            }
        }

        Cache::put('reconcile_heartbeat', now()->toIso8601String(), now()->addMinutes(30));
        Cache::put('reconcile_last_stats', [
            'command' => 'payments:reconcile-pending',
            'checked' => $checked,
            'paid' => $paid,
            'cancelled' => $cancelled,
            'skipped_window' => $skipped,
            'skipped_credential' => $skippedCredential,
            'skipped_driver' => $skippedDriver,
            'api_errors' => $apiErrors,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        $this->info("Checados: {$checked} | Pagos: {$paid} | Cancelados: {$cancelled} | Ignorados (janela): {$skipped} | Sem credencial: {$skippedCredential} | Erros API: {$apiErrors}");

        return self::SUCCESS;
    }

    /**
     * Pedidos de adquirente desconectada ou desabilitada não entram na fila.
     * Sem isso, o mesmo pendente gera warning a cada ciclo e ocupa o limite do lote.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $query
     * @param  array{global: array<string, true>, tenants: array<int, array<string, true>>}  $index
     */
    private function restrictToActiveAcquirers($query, array $index): void
    {
        $globalSlugs = array_keys($index['global']);
        $tenantScopes = [];
        foreach ($index['tenants'] as $tenantId => $slugs) {
            $onlyTenant = array_values(array_diff(array_keys($slugs), $globalSlugs));
            if ($onlyTenant === []) {
                continue;
            }
            $tenantScopes[] = [
                'tenant_id' => (int) $tenantId,
                'slugs' => $onlyTenant,
            ];
        }

        if ($globalSlugs === [] && $tenantScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($w) use ($globalSlugs, $tenantScopes) {
            $started = false;
            if ($globalSlugs !== []) {
                $w->whereIn('gateway', $globalSlugs);
                $started = true;
            }
            foreach ($tenantScopes as $scope) {
                $clause = function ($t) use ($scope) {
                    $t->where('tenant_id', $scope['tenant_id'])->whereIn('gateway', $scope['slugs']);
                };
                if ($started) {
                    $w->orWhere($clause);
                } else {
                    $w->where($clause);
                    $started = true;
                }
            }
        });
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
