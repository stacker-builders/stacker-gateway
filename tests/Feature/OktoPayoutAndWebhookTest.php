<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Jobs\ReconcileOktoWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\OktoRsaFixture;
use Tests\TestCase;

class OktoPayoutAndWebhookTest extends TestCase
{
    /**
     * @var array{private: string, public: string}
     */
    private array $rsa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        $this->rsa = OktoRsaFixture::pair();
    }

    protected function tearDown(): void
    {
        Setting::set('platform_payout_gateway', null, null);
        GatewayCredential::query()->where('gateway_slug', 'okto')->delete();
        parent::tearDown();
    }

    public function test_auto_okto_persists_pending_and_dispatches_reconcile_job(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedOktoCredential();

        Queue::fake();

        Http::fake([
            'https://demo-pix.oktopay.eu/transactions/withdraw' => Http::response([
                'id' => 'okto-wd-99',
                'status' => 'created',
                'externalId' => '1',
            ], 200),
        ]);

        $w = $this->makeWithdrawal(100, 0, 100, [
            'payout_pix_key' => '11119854997',
            'payout_pix_key_type' => 'cpf',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);
        $this->assertTrue($auto['pending'] ?? false);

        $fresh = $w->fresh();
        $this->assertSame('okto', $fresh->payout_provider);
        $this->assertSame('okto-wd-99', $fresh->payout_external_id);

        Queue::assertPushed(ReconcileOktoWithdrawalJob::class);
    }

    public function test_auto_okto_transfer_value_includes_admin_fee_payout_brl(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedOktoCredential([
            'okto_admin_fee_payout_brl' => '2',
        ]);

        Queue::fake();

        Http::fake([
            'https://demo-pix.oktopay.eu/transactions/withdraw' => Http::response([
                'id' => 'okto-wd-fee',
                'status' => 'created',
            ], 200),
        ]);

        $w = $this->makeWithdrawal(20, 4, 16, [
            'payout_pix_key' => 'destino@pix.com',
            'payout_pix_key_type' => 'email',
            'key_owner_document' => '60418443734',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/transactions/withdraw') || $request->method() !== 'POST') {
                return false;
            }
            $data = $request->data();

            return ($data['amount'] ?? null) === 1800
                && ($data['dictKey'] ?? null) === 'destino@pix.com'
                && ($data['taxId'] ?? null) === '60418443734'
                && ! array_key_exists('bankAccount', $data);
        });
    }

    public function test_webhook_invoice_paid_completes_order(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/status' => Http::response([
                'id' => 'okto-dep-1',
                'status' => 'paid',
                'type' => 'deposit',
            ], 200),
        ]);

        $this->seedOktoCredential();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 13,
            'email' => 'buyer@test.com',
            'gateway' => 'okto',
            'gateway_id' => 'okto-dep-1',
            'payment_method' => 'pix',
        ]);

        $body = [
            'Invoice' => [
                'id' => 'okto-dep-1',
                'externalId' => (string) $order->id,
                'status' => 'paid',
                'amount' => 1300,
                'originalAmount' => 1300,
            ],
        ];

        $response = $this->postOktoWebhook($body);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_invoice_paid_finds_order_by_okto_external_id_before_gateway_is_set(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/status' => Http::response([
                'id' => 'okto-dep-race',
                'status' => 'paid',
                'type' => 'deposit',
            ], 200),
        ]);

        $this->seedOktoCredential();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 13,
            'email' => 'buyer@test.com',
            'gateway' => null,
            'gateway_id' => null,
            'payment_method' => 'pix',
            'metadata' => ['okto_external_id' => '516042b1-a804-49f9-a252-1222c8273b46'],
        ]);

        $response = $this->postOktoWebhook([
            'Invoice' => [
                'id' => 'okto-dep-race',
                'externalId' => '516042b1-a804-49f9-a252-1222c8273b46',
                'status' => 'paid',
                'amount' => 1300,
            ],
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_transfer_success_marks_withdrawal_paid(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedOktoCredential();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'okto',
            'payout_external_id' => 'okto-wd-paid',
        ]);

        $response = $this->postOktoWebhook([
            'Transfer' => [
                'id' => 'okto-wd-paid',
                'status' => 'success',
                'amount' => 10000,
                'externalId' => (string) $w->id,
            ],
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('paid', $w->fresh()->status);
    }

    public function test_connection_probe_returns_pix_failure_not_generic_success(): void
    {
        $this->seedOktoCredential();

        Http::fake([
            'demo-pix.oktopay.eu/operator/v2/balance' => Http::response([
                'availableBalance' => 10,
            ], 200),
            'demo-pix.oktopay.eu/operator' => Http::response(['name' => 'op'], 200),
            'demo-pix.oktopay.eu/transactions/deposit' => Http::response([
                'errorList' => [
                    ['code' => 2001, 'message' => 'The tax id is invalid.', 'detailedMessage' => 'taxId rejected'],
                ],
            ], 400),
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->postJson('/plataforma/financeiro/gateways/okto/test', [
            'access_token' => 'okto-token-test',
            'rsa_public_key' => $this->rsa['public'],
            'sandbox' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('pix_probe.balance_ok', true)
            ->assertJsonPath('pix_probe.pix_ok', false);
        $this->assertStringContainsString('2001', (string) $response->json('message'));
        $this->assertStringContainsString('PIX: falhou', (string) $response->json('message'));
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->seedOktoCredential();

        $json = json_encode(['Invoice' => ['id' => 'x', 'status' => 'paid']], JSON_THROW_ON_ERROR);
        $response = $this->call(
            'POST',
            '/webhooks/gateways/okto',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYLOAD_SIGNATURE' => base64_encode('not-a-signature'),
                'HTTP_GAMING_OPERATOR_TOKEN' => 'okto-token-test',
            ],
            $json
        );

        $response->assertStatus(401);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function seedOktoCredential(array $extra = []): void
    {
        Setting::set('platform_payout_gateway', 'okto', null);
        GatewayCredential::query()->whereIn('gateway_slug', [
            'cajupay', 'spacepag', 'woovi', 'bspay', 'versell', 'xflow', 'onlyup',
        ])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'okto',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(array_merge([
            'access_token' => 'okto-token-test',
            'rsa_public_key' => $this->rsa['public'],
            'sandbox' => true,
        ], $extra));
        $cred->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postOktoWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $rawSig = '';
        openssl_sign($json, $rawSig, $this->rsa['private'], OPENSSL_ALGO_SHA256);

        return $this->call(
            'POST',
            '/webhooks/gateways/okto',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYLOAD_SIGNATURE' => base64_encode($rawSig),
                'HTTP_GAMING_OPERATOR_TOKEN' => 'okto-token-test',
            ],
            $json
        );
    }

    /**
     * @param  array<string, mixed>  $payoutSettings
     */
    private function makeWithdrawal(float $amount, float $fee, float $net, array $payoutSettings): Withdrawal
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => $payoutSettings,
        ])->save();

        return Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $net,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);
    }
}
