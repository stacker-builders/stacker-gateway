<?php

namespace App\Services\Okto;

use App\Gateways\Okto\OktoDriver;
use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\EffectiveMerchantFees;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PayoutUserSettings;
use App\Services\Payout\WithdrawalPayoutDestination;
use App\Services\Withdrawal\WithdrawalMinimumService;

class OktoPayoutService
{
    public function __construct(
        private ?OktoDriver $driver = null,
    ) {
        $this->driver ??= new OktoDriver;
    }

    /**
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, error?: string}
     */
    public function sendWithdrawalToPix(Withdrawal $withdrawal, User $owner): array
    {
        $credential = GatewayCredential::resolveForPayment(null, 'okto');
        if ($credential === null || ! $credential->is_connected) {
            return ['ok' => false, 'error' => 'Saque automático não configurado pela plataforma (Okto).'];
        }
        $credentials = $credential->getDecryptedCredentials();
        if (trim((string) ($credentials['access_token'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Configure o access token da Okto nas adquirentes da plataforma.'];
        }

        $net = (float) $withdrawal->net_amount;
        if ($net <= 0) {
            return ['ok' => false, 'error' => 'Valor líquido do saque inválido.'];
        }

        $economics = GatewayPayoutEconomics::fromCredentialsArray('okto', $credentials);
        $requiredNet = WithdrawalMinimumService::effectiveRequiredMinNet($economics);
        $minCents = (int) max(1, (int) round($requiredNet * 100));
        $apiAmount = GatewayPayoutEconomics::transferAmountBrlForApi(
            $net,
            $economics['admin_fee_payout_brl'],
            $economics['admin_fee_payout_percent'] ?? 0.0,
        );
        $amountCents = (int) round($net * 100);
        if ($amountCents < $minCents) {
            $tenantId = (int) $withdrawal->tenant_id;
            $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $requiredNet);
            $msg = $minGross !== null
                ? 'O valor mínimo do saque é R$ '.number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                : 'O valor solicitado é inferior ao mínimo permitido.';

            return ['ok' => false, 'error' => $msg];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $fromWithdrawal = WithdrawalPayoutDestination::fromWithdrawal($withdrawal);
        $pixKey = $fromWithdrawal['pix_key'] ?? PayoutUserSettings::pixKey($settings);
        $pixKeyType = $fromWithdrawal['pix_key_type'] ?? PayoutUserSettings::pixKeyType($settings);
        $ownerDoc = $fromWithdrawal['key_owner_document'] ?? PayoutUserSettings::cajuPixOwnerDocument($settings);
        if ($pixKey === '') {
            return ['ok' => false, 'error' => 'Cadastre a chave PIX de destino no Financeiro antes de solicitar o saque.'];
        }

        $transferCents = (int) max(1, (int) round($apiAmount * 100));
        $result = $this->driver->createTransfer(
            $credentials,
            $transferCents,
            $pixKey,
            $pixKeyType,
            (string) $withdrawal->id,
            $ownerDoc !== '' ? $ownerDoc : null
        );
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Falha ao processar o saque na Okto.'];
        }

        $tid = $result['transaction_id'] ?? null;

        return [
            'ok' => true,
            'pending' => true,
            'transaction_id' => is_string($tid) && $tid !== '' ? $tid : (string) $withdrawal->id,
        ];
    }
}
