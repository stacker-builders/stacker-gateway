<?php

namespace App\Support;

/**
 * Verifica se credenciais descriptografadas têm chaves mínimas para chamar a API do gateway.
 */
final class GatewayApiCredentials
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function isReadyForGateway(string $gatewaySlug, array $credentials): bool
    {
        if ($credentials === []) {
            return false;
        }

        return match ($gatewaySlug) {
            'cajupay' => trim((string) ($credentials['public_key'] ?? '')) !== ''
                && trim((string) ($credentials['secret_key'] ?? '')) !== '',
            'mercadopago' => trim((string) ($credentials['access_token'] ?? '')) !== '',
            'stripe' => trim((string) ($credentials['secret_key'] ?? '')) !== '',
            'pagarme' => trim((string) ($credentials['secret_key'] ?? '')) !== '',
            'pagbank' => trim((string) ($credentials['token'] ?? '')) !== '',
            'asaas' => trim((string) ($credentials['api_key'] ?? '')) !== '',
            'efi' => trim((string) ($credentials['client_id'] ?? '')) !== ''
                && trim((string) ($credentials['client_secret'] ?? '')) !== '',
            'pushinpay' => trim((string) ($credentials['api_key'] ?? '')) !== '',
            'woovi' => trim((string) ($credentials['app_id'] ?? '')) !== '',
            'spacepag' => trim((string) ($credentials['api_key'] ?? '')) !== '',
            'linaopenx' => trim((string) ($credentials['client_id'] ?? '')) !== ''
                && trim((string) ($credentials['client_secret'] ?? '')) !== '',
            // Cash In: client_id/secret/pix_key + mTLS. Cash Out não é exigido para cobrança.
            'versell' => \App\Gateways\Versell\VersellCredentials::isCashInReady($credentials),
            'cielo' => trim((string) ($credentials['merchant_id'] ?? '')) !== ''
                && trim((string) ($credentials['merchant_key'] ?? '')) !== '',
            'xflow' => trim((string) ($credentials['public_key'] ?? '')) !== ''
                && trim((string) ($credentials['secret_key'] ?? '')) !== '',
            'okto' => trim((string) ($credentials['access_token'] ?? '')) !== '',
            'xpag' => trim((string) ($credentials['client_id'] ?? '')) !== ''
                && trim((string) ($credentials['client_secret'] ?? '')) !== '',
            default => true,
        };
    }
}
