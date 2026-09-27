<?php

namespace Tests\Unit;

use App\Models\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use App\Services\Integrax\IntegraxMessageBuilder;
use Tests\TestCase;

class IntegraxMessageBuilderTest extends TestCase
{
    public function test_builds_vars_from_checkout_session(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'name' => 'Curso Pro',
            'checkout_slug' => 'curso-pro',
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'builder-test-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'name' => 'João',
            'email' => 'joao@example.com',
            'phone' => '11999999999',
        ]);

        $builder = app(IntegraxMessageBuilder::class);
        $vars = $builder->fromCheckoutSession($session);

        $this->assertSame('João', $vars['nome']);
        $this->assertSame('Curso Pro', $vars['produto']);
        $this->assertStringContainsString('/c/curso-pro', $vars['link']);
    }

    public function test_checkout_link_uses_public_url_not_localhost_app_url(): void
    {
        config([
            'app.url' => 'http://localhost',
            'getfy.webhook_public_url' => 'https://checkout.exemplo.com',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'name' => 'Curso Pro',
            'checkout_slug' => 'curso-pro',
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'builder-public-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'phone' => '11988887777',
        ]);

        $vars = app(IntegraxMessageBuilder::class)->fromCheckoutSession($session);

        $this->assertSame('https://checkout.exemplo.com/c/curso-pro', $vars['link']);
        $this->assertStringNotContainsString('localhost', $vars['link']);
    }
}
