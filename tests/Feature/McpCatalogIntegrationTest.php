<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\McpCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use App\Support\ProductApprovalSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class McpCatalogIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
            ThrottleRequests::class,
        ]);
        Cache::forget('setting.global.'.SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::MCP));
    }

    private function makeSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller->fresh();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    public function test_mcp_is_hidden_by_default(): void
    {
        $this->assertFalse(SellerIntegrationVisibility::definition(SellerIntegrationVisibility::MCP)['default']);
        $this->assertFalse(SellerIntegrationVisibility::globalEnabled(SellerIntegrationVisibility::MCP));

        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visible_integrations', fn ($ids) => ! collect($ids)->contains(SellerIntegrationVisibility::MCP))
                ->where('mcp', null)
            );
    }

    public function test_seller_cannot_activate_mcp_when_hidden(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertForbidden();
    }

    public function test_merchant_override_shows_mcp_and_allows_activation(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $tenantId = (int) $seller->id;

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $seller), [
            'name' => $seller->name,
            'email' => $seller->email,
            'integration_modes' => [
                'mcp' => SellerIntegrationVisibility::MODE_ENABLED,
            ],
        ])->assertRedirect();

        $this->assertTrue(SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::MCP, $tenantId));

        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visible_integrations', fn ($ids) => collect($ids)->contains(SellerIntegrationVisibility::MCP))
                ->where('mcp.connected', false)
            );

        $response = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertCreated()
            ->assertJsonPath('mcp.connected', true);

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertStringStartsWith('mcp_live_', $token);
        $this->assertNotNull(McpCredential::parsePublicId($token));
        $this->assertDatabaseHas('mcp_credentials', [
            'tenant_id' => $tenantId,
            'is_active' => 1,
        ]);
    }

    public function test_mcp_endpoint_rejects_missing_token(): void
    {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ])->assertUnauthorized();
    }

    public function test_mcp_endpoint_rejects_when_flag_is_off_even_with_valid_token(): void
    {
        $seller = $this->makeSeller();
        $tenantId = (int) $seller->id;
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::MCP,
            $tenantId,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $token = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertCreated()
            ->json('token');

        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::MCP,
            $tenantId,
            SellerIntegrationVisibility::MODE_DISABLED
        );

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_mcp_initialize_and_ping_when_enabled(): void
    {
        $seller = $this->makeSeller();
        $tenantId = (int) $seller->id;
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::MCP,
            $tenantId,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $token = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertCreated()
            ->json('token');

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'getfy-catalog')
            ->assertJsonPath('result.serverInfo.version', '0.4.0')
            ->assertJsonPath('result.protocolVersion', '2025-03-26');

        $listed = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'catalog.ping');

        $toolNames = collect($listed->json('result.tools'))->pluck('name')->all();
        $this->assertContains('catalog.list_products', $toolNames);
        $this->assertContains('catalog.create_product', $toolNames);
        $this->assertContains('catalog.apply_plan', $toolNames);
        $this->assertContains('sales.summary', $toolNames);
        $this->assertContains('sales.list_orders', $toolNames);
        $this->assertContains('sales.get_order', $toolNames);
        $this->assertContains('members.get_structure', $toolNames);
        $this->assertContains('members.create_lesson', $toolNames);
        $this->assertContains('members.apply_plan', $toolNames);
        $this->assertContains('students.grant', $toolNames);
        $this->assertContains('students.list', $toolNames);
        $this->assertContains('finance.get_balance', $toolNames);
        $this->assertNotContains('sales.refund', $toolNames);
        $this->assertNotContains('finance.withdraw', $toolNames);

        $ping = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'catalog.ping', 'arguments' => []],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $pingPayload = $this->decodeTool($ping);
        $this->assertTrue($pingPayload['ok'] ?? false);
        $this->assertSame($tenantId, (int) $pingPayload['tenant_id']);

        $this->actingAs($seller)
            ->deleteJson(route('integrations.mcp.destroy'))
            ->assertOk()
            ->assertJsonPath('mcp.connected', false);

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'ping',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized();
    }

    public function test_admin_can_enable_mcp_globally_from_settings(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'integration_mcp_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertTrue(SellerIntegrationVisibility::globalEnabled(SellerIntegrationVisibility::MCP));

        $seller = $this->makeSeller();
        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visible_integrations', fn ($ids) => collect($ids)->contains(SellerIntegrationVisibility::MCP))
            );
    }

    public function test_list_and_get_product_stay_inside_tenant_and_omit_secrets(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $token = $this->enableMcp($seller);

        $own = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso próprio',
            'conversion_pixels' => [
                'meta' => ['enabled' => true, 'entries' => [['access_token' => 'secret-token']]],
            ],
            'checkout_config' => [
                'payment_gateways' => ['pix' => 'secret-gw'],
                'custom_script' => '<script>alert(1)</script>',
                'upsell' => ['enabled' => false, 'products' => []],
            ],
        ]);
        $this->createTestProduct([
            'tenant_id' => $other->id,
            'name' => 'Curso de outro seller',
        ]);

        $list = $this->decodeTool($this->callTool($token, 'catalog.list_products'));
        $names = collect($list['products'])->pluck('name')->all();
        $this->assertContains('Curso próprio', $names);
        $this->assertNotContains('Curso de outro seller', $names);

        $detail = $this->decodeTool($this->callTool($token, 'catalog.get_product', [
            'product_id' => (string) $own->id,
        ]));
        $this->assertSame('Curso próprio', $detail['name']);
        $this->assertArrayHasKey('funnel', $detail);
        $this->assertArrayNotHasKey('conversion_pixels', $detail);
        $this->assertArrayNotHasKey('checkout_config', $detail);
        $this->assertArrayNotHasKey('payment_gateways', $detail);
        $encoded = json_encode($detail);
        $this->assertStringNotContainsString('secret-token', (string) $encoded);
        $this->assertStringNotContainsString('secret-gw', (string) $encoded);

        $foreign = $this->callTool($token, 'catalog.get_product', [
            'product_id' => (string) $this->createTestProduct(['tenant_id' => $other->id, 'name' => 'Alheio'])->id,
        ]);
        $this->assertTrue($foreign->json('result.isError'));
        $this->assertSame('not_found', $this->decodeTool($foreign)['code']);
    }

    public function test_create_product_applies_when_pending_and_requires_preview_when_auto_approve(): void
    {
        $seller = $this->makeSeller();
        $token = $this->enableMcp($seller);
        Setting::set(ProductApprovalSettings::KEY, '0');

        $created = $this->decodeTool($this->callTool($token, 'catalog.create_product', [
            'name' => 'Mentoria MCP',
            'price' => 97,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]));
        $this->assertSame('create_product', $created['op']);
        $this->assertSame('Mentoria MCP', $created['product']['name']);
        $this->assertSame(Product::APPROVAL_PENDING, $created['product']['approval']['status']);
        $this->assertDatabaseHas('seller_activity_logs', [
            'tenant_id' => $seller->id,
            'action' => 'product.created',
            'source' => 'mcp',
        ]);

        Setting::set(ProductApprovalSettings::KEY, '1');
        $gated = $this->callTool($token, 'catalog.create_product', [
            'name' => 'Produto ao vivo',
            'price' => 197,
        ]);
        $this->assertTrue($gated->json('result.isError'));
        $payload = $this->decodeTool($gated);
        $this->assertSame('needs_confirmation', $payload['code']);
        $this->assertNotEmpty($payload['preview_id']);
        $this->assertDatabaseMissing('products', ['name' => 'Produto ao vivo']);

        $idempotencyKey = 'create-live-'.uniqid('', true);
        $applied = $this->decodeTool($this->callTool($token, 'catalog.apply_plan', [
            'preview_id' => $payload['preview_id'],
            'idempotency_key' => $idempotencyKey,
        ]));
        $this->assertTrue($applied['ok']);
        $this->assertArrayNotHasKey('idempotent_replay', $applied);
        $this->assertSame('Produto ao vivo', $applied['results'][0]['product']['name']);

        $replay = $this->decodeTool($this->callTool($token, 'catalog.apply_plan', [
            'preview_id' => $payload['preview_id'],
            'idempotency_key' => $idempotencyKey,
        ]));
        $this->assertTrue($replay['idempotent_replay'] ?? $replay['already_applied'] ?? false);
        $this->assertSame(1, Product::query()->where('tenant_id', $seller->id)->where('name', 'Produto ao vivo')->count());
    }

    public function test_risky_coupon_and_upsell_need_preview_then_apply(): void
    {
        $seller = $this->makeSeller();
        $token = $this->enableMcp($seller);
        $main = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Produto principal',
            'approval_status' => Product::APPROVAL_APPROVED,
            'checkout_config' => [
                'payment_gateways' => ['pix' => 'keep-me'],
                'upsell' => ['enabled' => false, 'products' => []],
            ],
        ]);
        $extra = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Oferta extra',
            'price' => 47,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $coupon = $this->callTool($token, 'catalog.create_coupon', [
            'code' => 'MEGA50',
            'type' => 'percent',
            'value' => 80,
            'product_ids' => [(string) $main->id],
            'max_uses' => 10,
        ]);
        $this->assertTrue($coupon->json('result.isError'));
        $couponPayload = $this->decodeTool($coupon);
        $this->assertSame('needs_confirmation', $couponPayload['code']);

        $couponApplied = $this->decodeTool($this->callTool($token, 'catalog.apply_plan', [
            'preview_id' => $couponPayload['preview_id'],
        ]));
        $this->assertSame('MEGA50', $couponApplied['results'][0]['coupon']['code']);

        $upsell = $this->callTool($token, 'catalog.set_upsell', [
            'product_id' => (string) $main->id,
            'enabled' => true,
            'products' => [
                ['product_id' => (string) $extra->id],
            ],
            'headline' => 'Leva também?',
        ]);
        $this->assertTrue($upsell->json('result.isError'));
        $upsellPayload = $this->decodeTool($upsell);
        $this->assertSame('needs_confirmation', $upsellPayload['code']);

        $this->decodeTool($this->callTool($token, 'catalog.apply_plan', [
            'preview_id' => $upsellPayload['preview_id'],
        ]));

        $detail = $this->decodeTool($this->callTool($token, 'catalog.get_product', [
            'product_id' => (string) $main->id,
        ]));
        $this->assertTrue($detail['funnel']['upsell']['enabled']);
        $this->assertSame((string) $extra->id, $detail['funnel']['upsell']['products'][0]['product_id']);

        $fresh = $main->fresh();
        $config = is_array($fresh?->checkout_config) ? $fresh->checkout_config : [];
        $this->assertTrue($config['upsell']['enabled'] ?? false);
        $this->assertSame('keep-me', $config['payment_gateways']['pix'] ?? null);
    }

    public function test_foreign_preview_id_is_rejected(): void
    {
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $tokenA = $this->enableMcp($sellerA);
        $tokenB = $this->enableMcp($sellerB);
        Setting::set(ProductApprovalSettings::KEY, '1');

        $gated = $this->decodeTool($this->callTool($tokenA, 'catalog.create_product', [
            'name' => 'Só do A',
            'price' => 47,
        ]));
        $this->assertSame('needs_confirmation', $gated['code']);

        $foreign = $this->callTool($tokenB, 'catalog.apply_plan', [
            'preview_id' => $gated['preview_id'],
        ]);
        $this->assertTrue($foreign->json('result.isError'));
        $this->assertSame('preview_expired', $this->decodeTool($foreign)['code']);
        $this->assertDatabaseMissing('products', ['name' => 'Só do A']);
    }

    public function test_sales_tools_are_read_only_and_tenant_scoped(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $token = $this->enableMcp($seller);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso relatório',
        ]);
        $foreignProduct = $this->createTestProduct([
            'tenant_id' => $other->id,
            'name' => 'Curso alheio',
        ]);

        $own = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 197,
            'email' => 'cliente@example.com',
            'cpf' => '52998224725',
            'phone' => '11999999999',
            'customer_ip' => '203.0.113.10',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => 'pay_secret_123',
            'metadata' => ['pix_copy' => '000201secret', 'card_last4' => '4242'],
        ]);
        OrderItem::query()->create([
            'order_id' => $own->id,
            'product_id' => $product->id,
            'amount' => 197,
            'position' => 0,
        ]);

        $foreign = Order::query()->create([
            'tenant_id' => $other->id,
            'user_id' => $other->id,
            'product_id' => $foreignProduct->id,
            'status' => 'completed',
            'amount' => 999,
            'email' => 'outro@example.com',
            'payment_method' => 'pix',
        ]);

        $summary = $this->decodeTool($this->callTool($token, 'sales.summary', [
            'period' => '7dias',
        ]));
        $this->assertTrue($summary['read_only']);
        $this->assertEquals(197, $summary['receita_total']);
        $this->assertSame(1, $summary['quantidade_vendas']);
        $this->assertSame('Curso relatório', $summary['por_produto'][0]['product_name']);
        $encodedSummary = json_encode($summary);
        $this->assertStringNotContainsString('cliente@example.com', (string) $encodedSummary);
        $this->assertStringNotContainsString('52998224725', (string) $encodedSummary);
        $this->assertStringNotContainsString('pay_secret_123', (string) $encodedSummary);

        $list = $this->decodeTool($this->callTool($token, 'sales.list_orders', [
            'period' => '7dias',
            'status' => 'completed',
        ]));
        $this->assertCount(1, $list['orders']);
        $this->assertSame($own->id, $list['orders'][0]['id']);
        $this->assertArrayNotHasKey('email', $list['orders'][0]);
        $this->assertArrayNotHasKey('cpf', $list['orders'][0]);
        $this->assertArrayNotHasKey('gateway_id', $list['orders'][0]);
        $this->assertArrayNotHasKey('metadata', $list['orders'][0]);

        $detail = $this->decodeTool($this->callTool($token, 'sales.get_order', [
            'order_id' => $own->id,
        ]));
        $this->assertEquals(197, $detail['amount']);
        $this->assertSame('main', $detail['items'][0]['kind']);
        $this->assertArrayNotHasKey('email', $detail);
        $this->assertArrayNotHasKey('cpf', $detail);
        $this->assertArrayNotHasKey('phone', $detail);
        $this->assertArrayNotHasKey('customer_ip', $detail);
        $this->assertArrayNotHasKey('gateway_id', $detail);
        $this->assertArrayNotHasKey('metadata', $detail);
        $this->assertArrayNotHasKey('shipping_address', $detail);
        $encodedDetail = json_encode($detail);
        $this->assertStringNotContainsString('cliente@example.com', (string) $encodedDetail);
        $this->assertStringNotContainsString('000201secret', (string) $encodedDetail);

        $hidden = $this->callTool($token, 'sales.get_order', [
            'order_id' => $foreign->id,
        ]);
        $this->assertTrue($hidden->json('result.isError'));
        $this->assertSame('not_found', $this->decodeTool($hidden)['code']);

        $unknown = $this->callTool($token, 'sales.refund', [
            'order_id' => $own->id,
        ]);
        $this->assertTrue($unknown->json('result.isError'));
        $this->assertSame('unknown_tool', $this->decodeTool($unknown)['code']);
        $this->assertSame('completed', $own->fresh()?->status);
    }

    private function enableMcp(User $seller): string
    {
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::MCP,
            (int) $seller->id,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $token = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertCreated()
            ->json('token');

        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $token, string $name, array $arguments = [])
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeTool($response): array
    {
        $decoded = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
