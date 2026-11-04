<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Services\PaymentService;
use Tests\TestCase;

class PaymentMethodsPerProductTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('gateway_order', [
            'pix' => ['efi'],
            'card' => [],
            'boleto' => [],
            'pix_auto' => [],
        ], null);
    }

    public function test_available_methods_excludes_pix_when_disabled_on_product(): void
    {
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'checkout_config' => [
                'payment_methods_enabled' => [
                    'pix' => false,
                    'card' => false,
                    'boleto' => false,
                    'pix_auto' => false,
                ],
            ],
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $cred->save();

        $methods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, null, null);
        $ids = array_column($methods, 'id');
        $this->assertNotContains('pix', $ids);
    }

    public function test_boleto_unavailable_when_platform_order_has_no_boleto_gateways(): void
    {
        Setting::set('gateway_order', [
            'pix' => ['efi'],
            'card' => [],
            'boleto' => [],
            'pix_auto' => [],
        ], null);

        $product = $this->createTestProduct(['tenant_id' => 1]);

        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $cred->save();

        $global = app(PaymentService::class)->globallyAvailablePaymentMethodKeys($product, null);

        $this->assertTrue($global['pix']);
        $this->assertFalse($global['boleto']);
    }

    public function test_available_methods_includes_pix_when_enabled_on_product(): void
    {
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'checkout_config' => [
                'payment_methods_enabled' => [
                    'pix' => true,
                    'card' => false,
                    'boleto' => false,
                    'pix_auto' => false,
                ],
            ],
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $cred->save();

        $methods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, null, null);
        $ids = array_column($methods, 'id');
        $this->assertContains('pix', $ids);
    }

    public function test_subscription_checkout_offers_pix_card_and_pix_auto_without_open_finance(): void
    {
        Setting::set('gateway_order', [
            'pix' => ['efi'],
            'card' => ['efi'],
            'boleto' => [],
            'pix_auto' => ['efi'],
            'open_finance' => ['linaopenx'],
        ], null);

        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'billing_type' => 'subscription',
            'checkout_config' => [
                'payment_methods_enabled' => [
                    'pix' => true,
                    'card' => true,
                    'boleto' => false,
                    'pix_auto' => true,
                    'open_finance' => true,
                ],
            ],
        ]);
        $plan = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 20,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'pl'.substr(uniqid(), -6),
            'position' => 0,
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $cred->save();

        $methods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, $plan, null);
        $byId = collect($methods)->keyBy('id');

        $this->assertTrue($byId->has('pix'));
        $this->assertTrue($byId->has('card'));
        $this->assertTrue($byId->has('pix_auto'));
        $this->assertFalse($byId->has('open_finance'));
        $this->assertSame('Avisamos para renovar no vencimento', $byId['pix']['hint'] ?? null);
        $this->assertSame('Assinatura no cartão', $byId['card']['hint'] ?? null);
        $this->assertSame('Débito automático no vencimento', $byId['pix_auto']['hint'] ?? null);

        $oneTime = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, null, null);
        $oneTimeIds = array_column($oneTime, 'id');
        $this->assertNotContains('pix_auto', $oneTimeIds);
        $this->assertArrayNotHasKey('hint', collect($oneTime)->firstWhere('id', 'pix') ?? []);
    }
}
