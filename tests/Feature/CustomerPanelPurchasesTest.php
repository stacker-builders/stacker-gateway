<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPanelPurchasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_painel_cliente_lists_main_product_and_order_bumps(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'email' => 'buyer@test.com',
        ]);

        $main = $this->createTestProduct(['name' => 'Curso principal', 'tenant_id' => 1]);
        $bump = $this->createTestProduct(['name' => 'Bump extra', 'tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 150,
            'email' => $buyer->email,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bump->id,
            'amount' => 50,
            'position' => 1,
        ]);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 2)
            ->where('purchases.0.product_name', 'Curso principal')
            ->where('purchases.0.is_order_bump', false)
            ->where('purchases.0.purchased_at_label', $order->created_at->timezone(config('app.timezone'))->format('d/m/Y'))
            ->where('purchases.0.product_type_label', 'Link')
            ->where('purchases.1.product_name', 'Bump extra')
            ->where('purchases.1.is_order_bump', true)
        );
    }

    public function test_painel_cliente_lists_manual_grant_without_order(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Curso liberado',
            'tenant_id' => 1,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        $buyer->products()->attach($product->id);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 1)
            ->where('purchases.0.product_name', 'Curso liberado')
            ->where('purchases.0.is_manual_grant', true)
            ->where('purchases.0.order_id', null)
            ->where('purchases.0.can_request_refund', false)
            ->where('purchases.0.access_url', fn ($url) => is_string($url) && str_contains($url, '/m/'))
        );
    }

    public function test_painel_cliente_does_not_duplicate_product_from_order_and_manual_grant(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'email' => 'buyer@test.com',
        ]);

        $product = $this->createTestProduct(['name' => 'Curso único', 'tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => $buyer->email,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => 100,
            'position' => 0,
        ]);

        $buyer->products()->attach($product->id);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 1)
            ->where('purchases.0.product_name', 'Curso único')
            ->where('purchases.0.is_manual_grant', false)
            ->where('purchases.0.order_id', $order->id)
        );
    }

    public function test_painel_cliente_lists_purchase_and_separate_manual_grant(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'email' => 'buyer@test.com',
        ]);

        $purchased = $this->createTestProduct(['name' => 'Comprado', 'tenant_id' => 1]);
        $granted = $this->createTestProduct([
            'name' => 'Liberado manual',
            'tenant_id' => 1,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $purchased->id,
            'status' => 'completed',
            'amount' => 80,
            'email' => $buyer->email,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $purchased->id,
            'amount' => 80,
            'position' => 0,
        ]);

        $buyer->products()->attach([$purchased->id, $granted->id]);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 2)
            ->where('purchases.0.product_name', 'Comprado')
            ->where('purchases.0.is_manual_grant', false)
            ->where('purchases.1.product_name', 'Liberado manual')
            ->where('purchases.1.is_manual_grant', true)
            ->where('purchases.1.access_cta', 'Entrar na área de membros')
        );
    }

    public function test_painel_cliente_exposes_external_member_area_link_and_physical_hint(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'email' => 'buyer@test.com',
        ]);

        $external = $this->createTestProduct([
            'name' => 'Curso externo',
            'tenant_id' => 1,
            'type' => Product::TYPE_AREA_MEMBROS_EXTERNA,
            'checkout_config' => ['deliverable_link' => 'https://escola.example/acesso'],
        ]);
        $physical = $this->createTestProduct([
            'name' => 'Livro impresso',
            'tenant_id' => 1,
            'type' => Product::TYPE_PRODUTO_FISICO,
        ]);

        $externalOrder = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $external->id,
            'status' => 'completed',
            'amount' => 40,
            'email' => $buyer->email,
            'payment_method' => 'pix',
        ]);
        OrderItem::create([
            'order_id' => $externalOrder->id,
            'product_id' => $external->id,
            'amount' => 40,
            'position' => 0,
        ]);

        $physicalOrder = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $physical->id,
            'status' => 'completed',
            'amount' => 90,
            'email' => $buyer->email,
            'shipping_address' => [
                'street' => 'Rua das Flores',
                'number' => '10',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01000-000',
            ],
        ]);
        OrderItem::create([
            'order_id' => $physicalOrder->id,
            'product_id' => $physical->id,
            'amount' => 90,
            'position' => 0,
        ]);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 2)
            ->where('purchases.0.product_name', 'Livro impresso')
            ->where('purchases.0.access_url', null)
            ->where('purchases.0.access_hint', 'Produto físico. O envio segue para o endereço informado na compra.')
            ->where('purchases.0.shipping.lines.0', 'Rua das Flores, 10')
            ->where('purchases.1.product_name', 'Curso externo')
            ->where('purchases.1.access_url', 'https://escola.example/acesso')
            ->where('purchases.1.access_cta', 'Acessar plataforma')
            ->where('purchases.1.payment_method_label', 'Pix')
        );
    }

    public function test_painel_cliente_uses_product_support_or_seller_account(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'conta@loja.test',
            'phone' => '11987654321',
        ]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => $seller->id,
            'email' => 'buyer@test.com',
        ]);

        $custom = $this->createTestProduct([
            'name' => 'Com suporte do produto',
            'tenant_id' => $seller->id,
            'support_email' => 'suporte@curso.test',
            'support_whatsapp' => '5511911112222',
            'support_whatsapp_enabled' => true,
        ]);
        $fallback = $this->createTestProduct([
            'name' => 'Com suporte da conta',
            'tenant_id' => $seller->id,
        ]);

        foreach ([$fallback, $custom] as $product) {
            $order = Order::create([
                'tenant_id' => $seller->id,
                'user_id' => $buyer->id,
                'product_id' => $product->id,
                'status' => 'completed',
                'amount' => 40,
                'email' => $buyer->email,
            ]);
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => 40,
                'position' => 0,
            ]);
        }

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 2)
            ->where('purchases.0.product_name', 'Com suporte do produto')
            ->where('purchases.0.support_email', 'suporte@curso.test')
            ->where('purchases.0.support_whatsapp', '5511911112222')
            ->where('purchases.1.product_name', 'Com suporte da conta')
            ->where('purchases.1.support_email', 'conta@loja.test')
            ->where('purchases.1.support_whatsapp', null)
        );
    }
}
