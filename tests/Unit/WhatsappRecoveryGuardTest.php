<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\Whatsapp\WhatsappRecoveryGuard;
use Tests\TestCase;

class WhatsappRecoveryGuardTest extends TestCase
{
    public function test_already_owns_product_matches_phone_variants(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@example.com',
            'phone' => '(11) 98877-6655',
        ]);

        $this->assertTrue(WhatsappRecoveryGuard::alreadyOwnsProduct(1, '5511988776655', $product->id));
        $this->assertTrue(WhatsappRecoveryGuard::alreadyOwnsProduct(1, '11988776655', $product->id));
        $this->assertFalse(WhatsappRecoveryGuard::alreadyOwnsProduct(1, '5511999999999', $product->id));
    }

    public function test_already_owns_product_ignores_other_products(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $owned = $this->createTestProduct(['tenant_id' => 1, 'checkout_slug' => 'owned-ab']);
        $other = $this->createTestProduct(['tenant_id' => 1, 'checkout_slug' => 'other-xy']);

        Order::create([
            'tenant_id' => 1,
            'product_id' => $owned->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@example.com',
            'phone' => '11988776655',
        ]);

        $this->assertFalse(WhatsappRecoveryGuard::alreadyOwnsProduct(1, '5511988776655', $other->id));
    }
}
