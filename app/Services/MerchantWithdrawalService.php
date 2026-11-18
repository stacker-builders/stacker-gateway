<?php

namespace App\Services;

use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWalletAdminBlockService;
use App\Services\PanelPushService;
use App\Services\Withdrawal\WithdrawalMinimumService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MerchantWithdrawalService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /**
     * Reserva o saque para aprovação/envio PIX (evita duplo clique / requests paralelos).
     */
    public static function beginPayoutApproval(int $withdrawalId): ?Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId) {
            $withdrawal = Withdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->first();
            if ($withdrawal === null || $withdrawal->status !== self::STATUS_PENDING) {
                return null;
            }

            $withdrawal->status = self::STATUS_PROCESSING;
            $withdrawal->save();

            try {
                app(\App\Services\Api\ApiWithdrawalWebhookService::class)
                    ->dispatchForWithdrawal($withdrawal->fresh(), 'withdrawal.processing');
            } catch (\Throwable $e) {
                Log::warning('MerchantWithdrawalService: falha webhook withdrawal.processing', [
                    'withdrawal_id' => $withdrawal->id,
                    'message' => $e->getMessage(),
                ]);
            }

            return $withdrawal->fresh();
        });
    }

    /**
     * Reverte para pendente quando o envio ao gateway falha antes de concluir o saque.
     */
    public static function releasePayoutApproval(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== self::STATUS_PROCESSING) {
                return;
            }

            $locked->status = self::STATUS_PENDING;
            $locked->save();
        });
    }
    /**
     * Valor solicitado é o bruto debitado da carteira; a taxa incide sobre esse valor.
     *
     * @throws ValidationException
     */
    public static function requestWithdrawal(User $user, float $amount, string $bucket, ?string $notes = null): Withdrawal
    {
        MerchantOperationalGuard::assertCanRequestWithdrawal($user);

        $tenantId = (int) ($user->tenant_id ?? $user->id);
        if ($tenantId < 1) {
            throw ValidationException::withMessages(['amount' => 'Conta inválida.']);
        }

        WithdrawalPolicyService::assertIntervalAllows($tenantId);
        WithdrawalPolicyService::assertRequestAllowed();

        $bucket = in_array($bucket, ['pix', 'card', 'boleto'], true) ? $bucket : 'pix';
        $col = 'available_'.$bucket;
        if (! Schema::hasColumn('tenant_wallets', $col)) {
            throw ValidationException::withMessages(['amount' => 'Carteiras indisponíveis.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }

        $feeCalc = EffectiveMerchantFees::calculateWithdrawalFee($tenantId, $amount);
        if ($feeCalc['net'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'O valor líquido após taxas deve ser maior que zero.']);
        }

        $required = WithdrawalMinimumService::effectiveRequiredMinNet();
        if ($required > 0 && $feeCalc['net'] + 0.0001 < $required) {
            $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $required);
            $msg = $minGross !== null
                ? 'O valor mínimo do saque é R$ '
                    .number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                : 'O valor solicitado é inferior ao mínimo permitido. Aumente o valor ou contate o suporte.';

            throw ValidationException::withMessages(['amount' => $msg]);
        }

        $withdrawal = DB::transaction(function () use ($user, $tenantId, $amount, $feeCalc, $bucket, $col, $notes) {
            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($wallet === null) {
                throw ValidationException::withMessages(['amount' => 'Carteira não encontrada.']);
            }

            $available = MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, $bucket);
            if ($available + 0.0001 < $amount) {
                if (Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked') && filter_var($wallet->admin_withdrawal_blocked ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $msg = 'Saques bloqueados pela plataforma. Contate o suporte.';
                } elseif (Schema::hasColumn('tenant_wallets', 'admin_blocked_amount') && (float) ($wallet->admin_blocked_amount ?? 0) > 0) {
                    $msg = 'Saldo disponível insuficiente após reserva administrativa nesta carteira.';
                } else {
                    $msg = self::insufficientBucketBalanceMessage($wallet, $bucket, $available);
                }
                throw ValidationException::withMessages(['amount' => $msg]);
            }

            // Debita do saldo bruto da carteira; `effectiveAvailableForWithdrawal` pode descontar bloqueios administrativos,
            // mas o valor bloqueado deve permanecer em `available_*` até ser liberado/alterado pela plataforma.
            $rawBucket = (float) ($wallet->{$col} ?? 0);
            $wallet->{$col} = round($rawBucket - $amount, 2);
            self::syncAggregateBalance($wallet);
            $wallet->save();

            $withdrawal = Withdrawal::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'amount' => $amount,
                'fee_amount' => $feeCalc['fee'],
                'net_amount' => $feeCalc['net'],
                'bucket' => $bucket,
                'status' => 'pending',
                'notes' => $notes,
                'currency' => 'BRL',
            ]);

            WalletTransaction::query()->create([
                'tenant_id' => $tenantId,
                'order_id' => null,
                'withdrawal_id' => $withdrawal->id,
                'bucket' => $bucket,
                'type' => WalletTransaction::TYPE_WITHDRAWAL_REQUEST,
                'amount_gross' => $amount,
                'amount_fee' => $feeCalc['fee'],
                'amount_net' => $feeCalc['net'],
                'meta' => [
                    'phase' => 'request',
                ],
            ]);

            return $withdrawal->fresh();
        });

        SellerActivityLogService::record(
            actor: $user,
            action: SellerActivityLogService::WITHDRAWAL_REQUESTED,
            targetType: Withdrawal::class,
            targetId: $withdrawal->id,
            metadata: [
                'amount' => (float) $withdrawal->amount,
                'fee_amount' => (float) $withdrawal->fee_amount,
                'net_amount' => (float) $withdrawal->net_amount,
                'bucket' => $withdrawal->bucket,
            ],
            tenantId: $tenantId,
        );

        return $withdrawal;
    }

    public static function markPaid(Withdrawal $withdrawal): void
    {
        if (! in_array($withdrawal->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return;
        }

        $shouldNotify = false;

        DB::transaction(function () use ($withdrawal, &$shouldNotify) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
                return;
            }

            $alreadyComplete = WalletTransaction::query()
                ->where('withdrawal_id', $locked->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
                ->exists();

            if ($locked->status !== self::STATUS_PAID) {
                $locked->status = self::STATUS_PAID;
                $locked->save();
                $shouldNotify = true;
            }

            if ($alreadyComplete) {
                return;
            }

            try {
                WalletTransaction::query()->create([
                    'tenant_id' => (int) $locked->tenant_id,
                    'order_id' => null,
                    'withdrawal_id' => $locked->id,
                    'bucket' => (string) $locked->bucket,
                    'type' => WalletTransaction::TYPE_WITHDRAWAL_COMPLETE,
                    'amount_gross' => (float) $locked->amount,
                    'amount_fee' => (float) $locked->fee_amount,
                    'amount_net' => (float) $locked->net_amount,
                    'meta' => ['phase' => 'paid'],
                ]);
            } catch (QueryException $e) {
                if (! self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        });

        if ($shouldNotify) {
            try {
                app(\App\Services\Api\ApiWithdrawalWebhookService::class)
                    ->dispatchForWithdrawal($withdrawal->fresh(), 'withdrawal.completed');
            } catch (\Throwable $e) {
                Log::warning('MerchantWithdrawalService: falha webhook withdrawal.completed', [
                    'withdrawal_id' => $withdrawal->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (! $shouldNotify) {
            return;
        }

        try {
            $freshForReceipt = $withdrawal->fresh();
            if ($freshForReceipt !== null && $freshForReceipt->payout_provider === 'cajupay') {
                $meta = is_array($freshForReceipt->payout_meta) ? $freshForReceipt->payout_meta : [];
                if (! is_array($meta['cajupay_receipt'] ?? null) || $meta['cajupay_receipt'] === []) {
                    app(WithdrawalPixReceiptService::class)->enrichFromCajuPay($freshForReceipt);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MerchantWithdrawalService: falha ao enriquecer comprovante PIX', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $fresh = $withdrawal->fresh();
            if ($fresh === null) {
                return;
            }

            $net = number_format((float) $fresh->net_amount, 2, ',', '.');
            app(PanelPushService::class)->sendAndPersistToTenant(
                (int) $fresh->tenant_id,
                'withdrawal_paid',
                'Saque concluído',
                "R$ {$net} enviado para sua conta PIX",
                url('/financeiro'),
                'withdrawal_'.$fresh->id
            );
        } catch (\Throwable $e) {
            Log::warning('MerchantWithdrawalService: falha ao enviar push de saque concluído', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function markFailed(Withdrawal $withdrawal, string $reason, bool $refundWallet = true): void
    {
        if (! in_array($withdrawal->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return;
        }

        $marked = false;

        DB::transaction(function () use ($withdrawal, $reason, $refundWallet, &$marked) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
                return;
            }

            if ($refundWallet) {
                self::creditWalletRefund($locked, 'failed', ['failure_reason' => $reason]);
            }

            $meta = is_array($locked->payout_meta) ? $locked->payout_meta : [];
            $meta['failed_at'] = now()->toIso8601String();
            $meta['failure_reason'] = $reason;

            $locked->payout_meta = $meta;
            $locked->failed_reason = Str::limit($reason, 500, '');
            $locked->status = self::STATUS_FAILED;
            $locked->save();
            $marked = true;
        });

        if ($marked) {
            try {
                app(\App\Services\Api\ApiWithdrawalWebhookService::class)
                    ->dispatchForWithdrawal($withdrawal->fresh(), 'withdrawal.failed');
            } catch (\Throwable $e) {
                Log::warning('MerchantWithdrawalService: falha webhook withdrawal.failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'message' => $e->getMessage(),
                ]);
            }
            app(PlatformEmailNotifications::class)->withdrawalFailed($withdrawal->fresh(), $reason);
        }
    }

    public static function reject(Withdrawal $withdrawal, ?string $adminNote = null): void
    {
        if (! in_array($withdrawal->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return;
        }

        DB::transaction(function () use ($withdrawal, $adminNote) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
                return;
            }

            self::creditWalletRefund($locked, 'rejected', ['admin_note' => $adminNote]);

            $meta = is_array($locked->payout_meta) ? $locked->payout_meta : [];
            if (trim((string) ($locked->payout_external_id ?? '')) !== '') {
                $meta['cancelled_by_admin_at'] = now()->toIso8601String();
            }

            $note = trim((string) ($locked->notes ?? ''));
            if ($adminNote !== null && $adminNote !== '') {
                $note .= ($note !== '' ? "\n\n" : '').'[Rejeitado] '.$adminNote;
            }
            $locked->notes = $note !== '' ? $note : null;
            $locked->payout_meta = $meta !== [] ? $meta : null;
            $locked->status = self::STATUS_REJECTED;
            $locked->save();
        });

        try {
            app(\App\Services\Api\ApiWithdrawalWebhookService::class)
                ->dispatchForWithdrawal($withdrawal->fresh(), 'withdrawal.rejected');
        } catch (\Throwable $e) {
            Log::warning('MerchantWithdrawalService: falha webhook withdrawal.rejected', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metaExtra
     */
    private static function creditWalletRefund(Withdrawal $withdrawal, string $phase, array $metaExtra = []): void
    {
        $alreadyRefunded = WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_REFUND)
            ->exists();

        if ($alreadyRefunded) {
            return;
        }

        $tenantId = (int) $withdrawal->tenant_id;
        $bucket = (string) $withdrawal->bucket;
        $col = 'available_'.$bucket;
        if (! in_array($col, ['available_pix', 'available_card', 'available_boleto'], true)) {
            $col = 'available_pix';
        }

        $gross = (float) $withdrawal->amount;

        $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
        if ($wallet !== null && Schema::hasColumn('tenant_wallets', $col)) {
            $wallet->{$col} = round((float) ($wallet->{$col} ?? 0) + $gross, 2);
            self::syncAggregateBalance($wallet);
            $wallet->save();
        }

        WalletTransaction::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => null,
            'withdrawal_id' => $withdrawal->id,
            'bucket' => (string) $withdrawal->bucket,
            'type' => WalletTransaction::TYPE_WITHDRAWAL_REFUND,
            'amount_gross' => $gross,
            'amount_fee' => 0,
            'amount_net' => $gross,
            'meta' => array_merge(['phase' => $phase], $metaExtra),
        ]);
    }

    private static function syncAggregateBalance(TenantWallet $wallet): void
    {
        if (! Schema::hasColumn('tenant_wallets', 'available_balance')) {
            return;
        }
        $wallet->available_balance = round(
            (float) ($wallet->available_pix ?? 0)
            + (float) ($wallet->available_card ?? 0)
            + (float) ($wallet->available_boleto ?? 0),
            2
        );
    }

    /**
     * Mensagem clara quando o seller tenta sacar de uma carteira (método) sem saldo,
     * enquanto outra (ex.: cartão) ainda tem fundos.
     */
    private static function insufficientBucketBalanceMessage(TenantWallet $wallet, string $bucket, float $available): string
    {
        $labels = ['pix' => 'PIX', 'card' => 'Cartão', 'boleto' => 'Boleto'];
        $bucketLabel = $labels[$bucket] ?? $bucket;

        $pix = round((float) ($wallet->available_pix ?? 0), 2);
        $card = round((float) ($wallet->available_card ?? 0), 2);
        $boleto = round((float) ($wallet->available_boleto ?? 0), 2);

        // Usa o saldo efetivo já calculado para a carteira selecionada.
        if ($bucket === 'pix') {
            $pix = $available;
        } elseif ($bucket === 'card') {
            $card = $available;
        } elseif ($bucket === 'boleto') {
            $boleto = $available;
        }

        $fmt = static fn (float $v): string => 'R$ '.number_format($v, 2, ',', '.');

        return sprintf(
            'Saldo insuficiente na carteira %s (disponível: %s). PIX: %s · Cartão: %s · Boleto: %s. Escolha a carteira correta ou um valor menor.',
            $bucketLabel,
            $fmt($available),
            $fmt($pix),
            $fmt($card),
            $fmt($boleto)
        );
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        return in_array($code, ['1062', '23000', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
