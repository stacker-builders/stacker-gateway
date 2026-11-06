<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\PlatformTransactionsListing;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformTransactionsListingBulkTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(string $name = 'Seller Alpha', string $email = 'seller.alpha@example.com'): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => $name,
            'email' => $email,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function makeOrder(User $seller, array $overrides = []): Order
    {
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => $overrides['product_name'] ?? 'Produto TX',
        ]);
        unset($overrides['product_name']);

        $customer = null;
        if (! array_key_exists('user_id', $overrides)) {
            $customer = User::factory()->create([
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'name' => 'Cliente TX',
                'email' => 'cliente.tx.'.uniqid().'@example.com',
            ]);
            $overrides['user_id'] = $customer->id;
            $overrides['email'] = $overrides['email'] ?? $customer->email;
        }

        return Order::create(array_merge([
            'tenant_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'gateway' => 'demo',
            'payment_method' => 'pix',
            'approved_manually' => false,
        ], $overrides));
    }

    public function test_search_finds_order_by_infoprodutor_beyond_first_page(): void
    {
        $admin = $this->platformAdmin();
        $sellerTarget = $this->seller('Vendedor Unico XYZ', 'vendedor.unico@example.com');
        $sellerOther = $this->seller('Outro Seller', 'outro.seller@example.com');

        for ($i = 0; $i < 30; $i++) {
            $this->makeOrder($sellerOther, [
                'status' => 'completed',
                'amount' => 10 + $i,
                'email' => "other{$i}@example.com",
            ]);
        }

        $target = $this->makeOrder($sellerTarget, [
            'status' => 'completed',
            'amount' => 99,
            'email' => 'alvo@example.com',
            'gateway' => 'efi',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'q' => 'Vendedor Unico XYZ',
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Transactions/Index')
                ->where('filters.per_page', 25)
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $target->id)
                ->where('orders.data.0.recebedor', 'Efí')
            );
    }

    public function test_transaction_row_links_to_customer_profile(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller('Seller Cliente Link', 'seller.cliente.link@example.com');
        $order = $this->makeOrder($seller, [
            'status' => 'pending',
            'email' => 'comprador.link@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['q' => 'comprador.link@example.com']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.customer_url', route('plataforma.clientes.show', ['user' => $order->user_id]))
            );
    }

    public function test_transaction_without_customer_account_has_no_profile_url(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller('Seller Sem Conta', 'seller.sem.conta@example.com');
        $order = $this->makeOrder($seller, [
            'user_id' => null,
            'email' => 'sem.conta@example.com',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['q' => 'sem.conta@example.com']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.customer_url', null)
            );
    }

    public function test_per_page_whitelist_and_invalid_fallback(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        for ($i = 0; $i < 30; $i++) {
            $this->makeOrder($seller, ['status' => 'pending', 'email' => "p{$i}@example.com"]);
        }

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['per_page' => 50]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.per_page', 50)
                ->where('orders.per_page', 50)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['per_page' => 999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.per_page', PlatformTransactionsListing::DEFAULT_PER_PAGE)
            );
    }

    public function test_gateway_and_payment_method_filters(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller('Seller Filtro Adquirente', 'seller.filtro.adquirente@example.com');

        $pix = $this->makeOrder($seller, [
            'status' => 'completed',
            'gateway' => 'efi',
            'payment_method' => 'pix',
            'email' => 'pix.filtro@example.com',
        ]);
        $card = $this->makeOrder($seller, [
            'status' => 'completed',
            'gateway' => 'cielo',
            'payment_method' => 'pix',
            'email' => 'card.filtro@example.com',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);
        $this->makeOrder($seller, [
            'status' => 'completed',
            'gateway' => 'mercadopago',
            'payment_method' => 'boleto',
            'email' => 'boleto.filtro@example.com',
        ]);

        GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'cielo',
            'credentials' => 'x',
            'is_connected' => false,
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'gateway' => 'cielo',
                'method' => 'card',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.gateway', 'cielo')
                ->where('filters.method', 'card')
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $card->id)
                ->where('gateway_options', fn ($options) => collect($options)->contains(
                    fn ($opt) => ($opt['value'] ?? '') === 'cielo' && str_contains((string) ($opt['label'] ?? ''), 'desligada')
                ))
                ->where('payment_method_options', fn ($options) => collect($options)->contains(
                    fn ($opt) => ($opt['value'] ?? '') === 'pix' && ($opt['label'] ?? '') === 'PIX'
                ))
            );

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'gateway' => 'efi',
                'method' => 'pix',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $pix->id)
            );
    }

    public function test_status_filter_combined_with_search(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller('Seller Filter', 'seller.filter@example.com');
        $pending = $this->makeOrder($seller, [
            'status' => 'pending',
            'email' => 'pendente.filter@example.com',
        ]);
        $this->makeOrder($seller, [
            'status' => 'completed',
            'email' => 'pago.filter@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'status' => 'pending',
                'q' => 'filter@example.com',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $pending->id)
            );
    }

    public function test_bulk_delete_pending_all_or_nothing_success(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $a = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'a@example.com']);
        $b = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'b@example.com']);

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$a->id, $b->id],
                'reason' => 'Testes gerados incorretamente',
            ])
            ->assertRedirect(route('plataforma.transacoes.index'));

        $this->assertDatabaseMissing('orders', ['id' => $a->id]);
        $this->assertDatabaseMissing('orders', ['id' => $b->id]);

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertTrue(
                PlatformAuditLog::query()->where('action', 'platform.order.bulk_deleted')->exists()
            );
        }
    }

    public function test_bulk_delete_rolls_back_when_any_not_pending(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $pending = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'ok@example.com']);
        $paid = $this->makeOrder($seller, ['status' => 'completed', 'email' => 'paid@example.com']);

        $this->actingAs($admin)
            ->from(route('plataforma.transacoes.index'))
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$pending->id, $paid->id],
            ])
            ->assertRedirect(route('plataforma.transacoes.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $paid->id, 'status' => 'completed']);
    }

    public function test_bulk_delete_rejects_non_admin_and_over_limit(): void
    {
        $seller = $this->seller();
        $order = $this->makeOrder($seller, ['status' => 'pending']);

        $this->actingAs($seller)
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$order->id],
            ])
            ->assertForbidden();

        $admin = $this->platformAdmin();
        $ids = range(1, 101);
        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => $ids,
            ])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_delete_blocks_when_status_changed_before_delete(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $order = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'race@example.com']);

        // Simula mudança de status antes da exclusão (já não pendente).
        $order->forceFill(['status' => 'completed'])->save();

        $this->actingAs($admin)
            ->from(route('plataforma.transacoes.index'))
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$order->id],
            ])
            ->assertRedirect(route('plataforma.transacoes.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);
    }

    public function test_refund_requests_filter_includes_refund_pending_orders(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller('Seller Await Caju', 'seller.await.caju@example.com');
        $pendingAcquirer = $this->makeOrder($seller, [
            'status' => 'refund_pending',
            'email' => 'await.caju@example.com',
            'gateway' => 'cajupay',
            'payment_method' => 'card',
        ]);
        $this->makeOrder($seller, [
            'status' => 'completed',
            'email' => 'pago.await@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'status' => PlatformTransactionsListing::STATUS_REFUND_REQUESTS,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $pendingAcquirer->id)
                ->where('orders.data.0.status', 'refund_pending')
            );
    }
}
