<?php

namespace App\Support;

use App\Models\GatewayCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verificação de webhooks inbound de gateways (fail-closed sem webhook_secret).
 */
final class GatewayInboundWebhookAuth
{
    /**
     * HMAC SHA-256 no corpo (header sha256=... ou valor bruto).
     */
    public static function verifyHmacSha256Body(Request $request, string $gatewaySlug, ?int $tenantId, string ...$headerNames): bool
    {
        $secret = self::webhookSecret($gatewaySlug, $tenantId);
        if ($secret === null) {
            Log::warning('GatewayInboundWebhookAuth: webhook_secret não configurado', [
                'gateway' => $gatewaySlug,
                'tenant_id' => $tenantId,
            ]);

            return false;
        }

        $signature = null;
        foreach ($headerNames as $name) {
            $v = $request->header($name);
            if (is_string($v) && $v !== '') {
                $signature = $v;
                break;
            }
        }
        if ($signature === null) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        $alt = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature) || hash_equals($alt, $signature);
    }

    /**
     * Asaas: authToken configurado no webhook, enviado no header asaas-access-token.
     */
    public static function verifyAsaas(Request $request, ?int $tenantId): bool
    {
        $secret = self::webhookSecret('asaas', $tenantId);
        if ($secret === null) {
            Log::warning('GatewayInboundWebhookAuth: webhook_secret não configurado', [
                'gateway' => 'asaas',
                'tenant_id' => $tenantId,
            ]);

            return false;
        }

        $token = $request->header('asaas-access-token') ?? $request->header('Asaas-Access-Token');
        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    /**
     * OpenPix/Woovi: Authorization header com valor igual ao webhook_secret ou HMAC.
     */
    public static function verifyWoovi(Request $request, ?int $tenantId): bool
    {
        $secret = self::webhookSecret('woovi', $tenantId);
        if ($secret === null) {
            Log::warning('GatewayInboundWebhookAuth: woovi webhook_secret não configurado', [
                'tenant_id' => $tenantId,
            ]);

            return false;
        }

        $auth = $request->header('Authorization');
        if (is_string($auth) && $auth !== '') {
            $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : $auth;
            if (hash_equals($secret, trim($token))) {
                return true;
            }
        }

        $signature = $request->header('X-OpenPix-Signature') ?? $request->header('X-Webhook-Signature');
        if (is_string($signature) && $signature !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            return hash_equals($expected, $signature) || hash_equals($secret, $signature);
        }

        return false;
    }

    /**
     * BSPay: HMAC SHA-256 do raw body em X-Webhook-Signature / X-BSPay-Signature + timestamp ±300s.
     * Sem segredo ou sem assinatura o aviso é recusado. A liquidação de pedido e saque
     * ainda exige confirmação na API (extrato), além desta assinatura.
     */
    public static function verifyBspay(Request $request, ?int $tenantId): bool
    {
        $secret = self::webhookSecret('bspay', $tenantId);
        if ($secret === null) {
            Log::warning('GatewayInboundWebhookAuth: webhook_secret não configurado', [
                'gateway' => 'bspay',
                'tenant_id' => $tenantId,
            ]);

            return false;
        }

        $signature = $request->header('X-Webhook-Signature') ?: $request->header('X-BSPay-Signature');
        if (! is_string($signature) || trim($signature) === '') {
            Log::warning('GatewayInboundWebhookAuth: assinatura BSPay ausente', [
                'tenant_id' => $tenantId,
            ]);

            return false;
        }

        $timestamp = $request->header('X-Webhook-Timestamp') ?: $request->header('X-BSPay-Timestamp');
        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        $given = strtolower(trim($signature));
        if (str_starts_with($given, 'sha256=')) {
            $given = substr($given, 7);
        }

        return hash_equals($expected, $given);
    }

    public static function webhookSecret(string $gatewaySlug, ?int $tenantId): ?string
    {
        $credential = GatewayCredential::resolveForPayment($tenantId, $gatewaySlug);
        if (! $credential) {
            return null;
        }
        $credentials = $credential->getDecryptedCredentials();
        $secret = $credentials['webhook_secret'] ?? null;
        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        return trim($secret);
    }
}
