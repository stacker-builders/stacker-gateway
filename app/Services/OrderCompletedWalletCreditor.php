<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductCoproducer;
use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use App\Support\CardInstallmentEconomics;
use App\Support\WalletCreditReference;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crédito na carteira ao concluir pedido: suporta divisão de bruto (co-produção) com taxas por tenant.
 */
class OrderCompletedWalletCreditor
{
    public static function credit(Order $order): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }
        if (! Schema::hasColumn('tenant_wallets', 'available_pix')) {
            return;
        }

        $order = $order->fresh(['orderItems']);
        if ($order->status !== 'completed') {
            return;
        }

        $sellerTenantId = (int) $order->tenant_id;
        if ($sellerTenantId < 1) {
            return;
        }

        $lock = Cache::lock('wallet-credit-order-'.$order->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            self::creditWithinLock($order);
        } finally {
            $lock->release();
        }
    }

    private static function creditWithinLock(Order $order): void
    {
        if (WalletTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->exists()) {
            return;
        }

        $method = $order->payment_method;
        if ($method === null || $method === '') {
            $meta = $order->metadata ?? [];
            $method = is_array($meta) ? ($meta['checkout_payment_method'] ?? null) : null;
        }
        $feeMethod = EffectiveMerchantFees::feeMethodForOrder($order);
        $bucket = match ($method) {
            'card', 'apple_pay', 'google_pay', 'paypal' => 'card',
            'boleto' => 'boleto',
            'pix_auto', 'pix', 'open_finance', null, '' => 'pix',
            default => 'pix',
        };

        $methodKey = match ($method) {
            'apple_pay' => 'apple_pay',
            'google_pay' => 'google_pay',
            'card' => 'card',
            'paypal' => 'card',
            'boleto' => 'boleto',
            'open_finance' => 'open_finance',
            default => 'pix',
        };

        $grossTotal = (float) $order->lineItemsTotalAmount();
        if ($grossTotal <= 0) {
            return;
        }

        $isAffiliate = $order->isAffiliateSale();
        $slices = ProductCoproducer::buildGrossSlicesForOrder($order, $grossTotal, $isAffiliate);

        $createdPendingIds = [];

        foreach ($slices as $slice) {
            $tenantId = (int) $slice['tenant_id'];
            $grossSlice = (float) $slice['gross'];
            if ($tenantId < 1 || $grossSlice <= 0) {
                continue;
            }

            $role = $slice['role'] ?? 'seller';
            $baseMeta = [
                'coproduction' => $role === 'coproducer',
                'coproduction_role' => $role,
                'product_coproducer_id' => $slice['product_coproducer_id'] ?? null,
                'affiliate_enrollment_id' => $slice['product_affiliate_enrollment_id'] ?? null,
                'commission_percent' => $slice['commission_percent'] ?? null,
            ];

            $source = is_array($order->metadata ?? null) ? (($order->metadata['source'] ?? null) ?: null) : null;
            $installments = $feeMethod === 'card' ? CardInstallmentEconomics::countFromOrder($order) : 1;
            $feeCalc = EffectiveMerchantFees::calculateSaleFee(
                $tenantId,
                $feeMethod,
                $grossSlice,
                is_string($source) ? $source : null,
                $feeMethod === 'card' ? $installments : null
            );
            $net = $feeCalc['net'];
            if ($net <= 0) {
                continue;
            }

            $rules = EffectiveSettlementRules::forTenantMethod($tenantId, $methodKey);
            $days = $rules['days_to_available'];
            $reservePct = $rules['reserve_percent'];
            $reserveHoldDays = max(0, (int) ($rules['reserve_hold_days'] ?? 0));

            $installmentIntervalDays = 0;
            $splitInstallments = false;
            if ($feeMethod === 'card' && CardInstallmentEconomics::platformHasSavedTable()) {
                $instRule = CardInstallmentEconomics::ruleFor(
                    EffectiveMerchantFees::forTenant($tenantId)['card_installments'] ?? [],
                    $installments
                );
                $installmentIntervalDays = $instRule['days_to_available'];
                $splitInstallments = CardInstallmentEconomics::shouldSplit($installments, $installmentIntervalDays);
                if (! $splitInstallments && $installmentIntervalDays > 0) {
                    $days = $installmentIntervalDays;
                }
            }

            if (! $splitInstallments && $days === 0 && $reservePct <= 0) {
                if (self::creditAvailableDirectly($order, $tenantId, $bucket, $feeCalc, $grossSlice, $baseMeta)) {
                    continue;
                }

                continue;
            }

            $parts = [];
            if ($splitInstallments) {
                foreach (CardInstallmentEconomics::splitAmount($net, $installments) as $slice) {
                    if ($slice['amount'] <= 0.0001) {
                        continue;
                    }
                    $parts[] = [
                        'portion' => 'i'.$slice['index'],
                        'net' => $slice['amount'],
                        'days' => $slice['index'] * $installmentIntervalDays,
                        'installment_index' => $slice['index'],
                        'installments' => $installments,
                    ];
                }
            } else {
                $mainNet = round($net * (1 - $reservePct / 100.0), 2);
                $reserveNet = round($net - $mainNet, 2);
                if ($mainNet > 0.0001) {
                    $parts[] = ['portion' => 'main', 'net' => $mainNet];
                }
                if ($reserveNet > 0.0001) {
                    $parts[] = ['portion' => 'reserve', 'net' => $reserveNet];
                }
            }

            if ($parts === []) {
                continue;
            }

            $pendingCol = 'pending_'.$bucket;
            if (! in_array($pendingCol, ['pending_pix', 'pending_card', 'pending_boleto'], true)) {
                $pendingCol = 'pending_pix';
            }

            $feeTotal = (float) $feeCalc['fee'];
            $grossForSlice = (float) $grossSlice;

            DB::transaction(function () use ($order, $tenantId, $bucket, $feeCalc, $grossForSlice, $feeTotal, $net, $parts, $pendingCol, $days, $reserveHoldDays, $baseMeta, &$createdPendingIds) {
                $wallet = TenantWallet::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    $wallet = TenantWallet::query()->create([
                        'tenant_id' => $tenantId,
                        'available_balance' => 0,
                        'pending_balance' => 0,
                        'currency' => 'BRL',
                        'available_pix' => 0,
                        'available_card' => 0,
                        'available_boleto' => 0,
                        'pending_pix' => 0,
                        'pending_card' => 0,
                        'pending_boleto' => 0,
                    ]);
                    $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
                }

                foreach ($parts as $part) {
                    $portion = (string) ($part['portion'] ?? 'main');
                    $creditRef = WalletCreditReference::forPendingSale((int) $order->id, $tenantId, $portion);

                    if (self::creditReferenceExists($creditRef)) {
                        continue;
                    }

                    $netPart = $part['net'];
                    $ratio = $net > 0 ? ($netPart / $net) : 0;
                    $grossPart = round($grossForSlice * $ratio, 2);
                    $feePart = round($feeTotal * $ratio, 2);

                    $totalDays = array_key_exists('days', $part)
                        ? max(0, (int) $part['days'])
                        : ($days + (($portion === 'reserve') ? $reserveHoldDays : 0));
                    $clearsAt = $totalDays === 0
                        ? Carbon::now()
                        : Carbon::now()->addDays($totalDays);

                    $currentPend = (float) ($wallet->{$pendingCol} ?? 0);
                    $wallet->{$pendingCol} = round($currentPend + $netPart, 2);

                    if (Schema::hasColumn('tenant_wallets', 'pending_balance')) {
                        $wallet->pending_balance = round(
                            (float) ($wallet->pending_pix ?? 0)
                            + (float) ($wallet->pending_card ?? 0)
                            + (float) ($wallet->pending_boleto ?? 0),
                            2
                        );
                    }

                    $meta = array_merge($baseMeta, [
                        'payment_method' => $order->payment_method,
                        'percent_applied' => $feeCalc['percent'] ?? null,
                        'fixed_applied' => $feeCalc['fixed'] ?? null,
                        'clears_at' => $clearsAt->toIso8601String(),
                        'portion' => $portion,
                    ]);
                    if ($portion === 'reserve' && $reserveHoldDays > 0) {
                        $meta['reserve_hold_days'] = $reserveHoldDays;
                    }
                    if (isset($part['installments'])) {
                        $meta['installments'] = (int) $part['installments'];
                    }
                    if (isset($part['installment_index'])) {
                        $meta['installment_index'] = (int) $part['installment_index'];
                    }

                    try {
                        $tx = WalletTransaction::query()->create([
                            'tenant_id' => $tenantId,
                            'order_id' => $order->id,
                            'withdrawal_id' => null,
                            'bucket' => $bucket,
                            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
                            'credit_reference' => $creditRef,
                            'amount_gross' => $grossPart,
                            'amount_fee' => $feePart,
                            'amount_net' => $netPart,
                            'meta' => $meta,
                        ]);
                        $createdPendingIds[] = $tx->id;
                    } catch (QueryException $e) {
                        if (! self::isDuplicateCreditReference($e)) {
                            throw $e;
                        }
                    }
                }

                $wallet->save();
            });
        }

        foreach ($createdPendingIds as $pid) {
            $tx = WalletTransaction::query()->find($pid);
            if ($tx !== null) {
                $meta = is_array($tx->meta) ? $tx->meta : [];
                $when = isset($meta['clears_at']) ? Carbon::parse($meta['clears_at']) : null;
                if ($when !== null && ! $when->isFuture()) {
                    SettlementReleaseService::releaseOne($tx->fresh());
                }
            }
        }
    }

    /**
     * @param  array{fee: float, net: float, gross: float, percent: float, fixed: float}  $feeCalc
     * @param  array<string, mixed>  $baseMeta
     */
    private static function creditAvailableDirectly(Order $order, int $tenantId, string $bucket, array $feeCalc, float $gross, array $baseMeta): bool
    {
        $creditRef = WalletCreditReference::forDirectSale((int) $order->id, $tenantId);

        if (self::creditReferenceExists($creditRef)) {
            return false;
        }

        try {
            DB::transaction(function () use ($order, $tenantId, $bucket, $feeCalc, $gross, $baseMeta, $creditRef) {
                $wallet = TenantWallet::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    $wallet = TenantWallet::query()->create([
                        'tenant_id' => $tenantId,
                        'available_balance' => 0,
                        'pending_balance' => 0,
                        'currency' => 'BRL',
                        'available_pix' => 0,
                        'available_card' => 0,
                        'available_boleto' => 0,
                        'pending_pix' => 0,
                        'pending_card' => 0,
                        'pending_boleto' => 0,
                    ]);
                    $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
                }

                if (self::creditReferenceExists($creditRef)) {
                    return;
                }

                $col = 'available_'.$bucket;
                if (! in_array($col, ['available_pix', 'available_card', 'available_boleto'], true)) {
                    $col = 'available_pix';
                }

                $current = (float) ($wallet->{$col} ?? 0);
                $wallet->{$col} = round($current + $feeCalc['net'], 2);

                if (Schema::hasColumn('tenant_wallets', 'available_balance')) {
                    $wallet->available_balance = round(
                        (float) ($wallet->available_pix ?? 0)
                        + (float) ($wallet->available_card ?? 0)
                        + (float) ($wallet->available_boleto ?? 0),
                        2
                    );
                }

                $wallet->save();

                WalletTransaction::query()->create([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'withdrawal_id' => null,
                    'bucket' => $bucket,
                    'type' => WalletTransaction::TYPE_CREDIT_SALE,
                    'credit_reference' => $creditRef,
                    'amount_gross' => $gross,
                    'amount_fee' => $feeCalc['fee'],
                    'amount_net' => $feeCalc['net'],
                    'meta' => array_merge($baseMeta, [
                        'payment_method' => $order->payment_method,
                        'percent_applied' => $feeCalc['percent'] ?? null,
                        'fixed_applied' => $feeCalc['fixed'] ?? null,
                    ]),
                ]);
            });
        } catch (QueryException $e) {
            if (self::isDuplicateCreditReference($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private static function creditReferenceExists(string $creditRef): bool
    {
        if (! Schema::hasColumn('wallet_transactions', 'credit_reference')) {
            return false;
        }

        return WalletTransaction::query()
            ->where('credit_reference', $creditRef)
            ->exists();
    }

    private static function isDuplicateCreditReference(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        return in_array($code, ['1062', '23000', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
