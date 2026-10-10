<?php

namespace Tests\Unit;

use App\Models\GatewayCredential;
use App\Support\GatewayInboundWebhookAuth;
use Illuminate\Http\Request;
use Tests\TestCase;

class GatewayInboundWebhookAuthTest extends TestCase
{
    public function test_fail_closed_when_webhook_secret_missing(): void
    {
        $request = Request::create('/webhooks/gateways/asaas', 'POST', [], [], [], [], '{"payment":{"id":"pay_1"}}');

        $this->assertFalse(GatewayInboundWebhookAuth::verifyHmacSha256Body($request, 'asaas', 1, 'X-Webhook-Signature'));
        $this->assertFalse(GatewayInboundWebhookAuth::verifyAsaas($request, 1));
    }

    public function test_asaas_accepts_auth_token_header(): void
    {
        $secret = 'asaas-auth-token-32-chars-minimum';
        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'asaas',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['webhook_secret' => $secret]);
        $credential->save();

        $body = '{"event":"PAYMENT_RECEIVED","payment":{"id":"pay_1"}}';
        $request = Request::create(
            '/webhooks/gateways/asaas',
            'POST',
            [],
            [],
            [],
            ['HTTP_ASAAS_ACCESS_TOKEN' => $secret],
            $body
        );

        $this->assertTrue(GatewayInboundWebhookAuth::verifyAsaas($request, 1));
    }

    public function test_asaas_rejects_wrong_auth_token(): void
    {
        $secret = 'asaas-auth-token-32-chars-minimum';
        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'asaas',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['webhook_secret' => $secret]);
        $credential->save();

        $request = Request::create(
            '/webhooks/gateways/asaas',
            'POST',
            [],
            [],
            [],
            ['HTTP_ASAAS_ACCESS_TOKEN' => 'wrong-token'],
            '{"payment":{"id":"pay_1"}}'
        );

        $this->assertFalse(GatewayInboundWebhookAuth::verifyAsaas($request, 1));
    }

    public function test_accepts_valid_hmac_when_secret_configured(): void
    {
        $secret = 'test-webhook-secret';
        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'pushinpay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['webhook_secret' => $secret]);
        $credential->save();

        $body = '{"id":"tx_1","status":"paid"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
        $request = Request::create(
            '/webhooks/gateways/pushinpay',
            'POST',
            [],
            [],
            [],
            ['HTTP_X-Webhook-Signature' => $signature],
            $body
        );

        $this->assertTrue(GatewayInboundWebhookAuth::verifyHmacSha256Body($request, 'pushinpay', 1, 'X-Webhook-Signature'));
    }

    public function test_bspay_rejects_when_secret_missing(): void
    {
        $request = Request::create(
            '/webhooks/gateways/bspay',
            'POST',
            [],
            [],
            [],
            ['HTTP_X-BSPay-Event' => 'cashin.confirmed'],
            '{"data":{"transaction_id":"tx_1","status":"confirmed"}}'
        );

        $this->assertFalse(GatewayInboundWebhookAuth::verifyBspay($request, 1));
    }

    public function test_bspay_rejects_when_signature_header_missing(): void
    {
        $secret = 'bspay-callback-secret';
        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['webhook_secret' => $secret]);
        $credential->save();

        $request = Request::create(
            '/webhooks/gateways/bspay',
            'POST',
            [],
            [],
            [],
            ['HTTP_X-BSPay-Event' => 'cashin.confirmed'],
            '{"data":{"transaction_id":"tx_1","status":"confirmed"}}'
        );

        $this->assertFalse(GatewayInboundWebhookAuth::verifyBspay($request, 1));
    }

    public function test_bspay_accepts_valid_hmac_and_timestamp(): void
    {
        $secret = 'bspay-callback-secret';
        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['webhook_secret' => $secret]);
        $credential->save();

        $body = '{"data":{"transaction_id":"tx_1","status":"confirmed"}}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $body, $secret);
        $request = Request::create(
            '/webhooks/gateways/bspay',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X-BSPay-Signature' => $signature,
                'HTTP_X-BSPay-Timestamp' => $timestamp,
                'HTTP_X-BSPay-Event' => 'cashin.confirmed',
            ],
            $body
        );

        $this->assertTrue(GatewayInboundWebhookAuth::verifyBspay($request, 1));
    }

    public function test_bspay_rejects_stale_timestamp(): void
    {
        $secret = 'bspay-callback-secret';
        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['webhook_secret' => $secret]);
        $credential->save();

        $body = '{"data":{"transaction_id":"tx_1"}}';
        $signature = hash_hmac('sha256', $body, $secret);
        $request = Request::create(
            '/webhooks/gateways/bspay',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X-BSPay-Signature' => $signature,
                'HTTP_X-BSPay-Timestamp' => (string) (time() - 400),
            ],
            $body
        );

        $this->assertFalse(GatewayInboundWebhookAuth::verifyBspay($request, 1));
    }
}
