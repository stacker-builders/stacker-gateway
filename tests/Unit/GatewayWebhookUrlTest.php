<?php

namespace Tests\Unit;

use App\Support\GatewayWebhookUrl;
use App\Support\PublicAppUrl;
use Tests\TestCase;

class GatewayWebhookUrlTest extends TestCase
{
    public function test_prefers_webhook_public_url_over_localhost_app_url(): void
    {
        config([
            'app.url' => 'http://localhost:8085',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $url = GatewayWebhookUrl::forGateway('cajupay');

        $this->assertSame('https://pay.exemplo.com/webhooks/gateways/cajupay', $url);
        $this->assertStringNotContainsString('localhost', $url);
    }

    public function test_uses_public_app_url_when_webhook_public_missing(): void
    {
        config([
            'app.url' => 'https://gateway.loja.com',
            'getfy.webhook_public_url' => null,
        ]);

        $this->assertSame(
            'https://gateway.loja.com/webhooks/gateways/mercadopago',
            GatewayWebhookUrl::forGateway('mercadopago')
        );
        $this->assertSame(
            'https://gateway.loja.com/webhooks/gateways/pagarme',
            GatewayWebhookUrl::forGateway('pagarme')
        );
        $this->assertSame(
            'https://gateway.loja.com/webhooks/gateways/paypal',
            GatewayWebhookUrl::forGateway('paypal')
        );
        $this->assertSame(
            'https://gateway.loja.com/webhooks/gateways/stripe',
            GatewayWebhookUrl::forGateway('stripe')
        );
        $this->assertSame('https://gateway.loja.com', PublicAppUrl::base());
    }
}
