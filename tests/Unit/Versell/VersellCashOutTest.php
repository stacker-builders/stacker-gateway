<?php

namespace Tests\Unit\Versell;

use App\Gateways\Versell\VersellCredentials;
use App\Http\Controllers\Webhooks\VersellPayoutWebhookController;
use App\Jobs\ProcessWithdrawalPayoutJob;
use App\Jobs\ReconcileVersellWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\Versell\VersellPayoutService;
use App\Services\Versell\VersellPayoutStatuses;
use App\Services\WithdrawalAutoPayoutService;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VersellCashOutTest extends TestCase
{
    private string $tmpDir;

    private string $cashInCert;

    private string $cashInKey;

    private string $cashOutCert;

    private string $cashOutKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_co_'.uniqid('', true);
        mkdir($this->tmpDir, 0700, true);

        $this->cashInCert = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_in.crt';
        $this->cashInKey = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_in.key';
        $this->cashOutCert = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_out.crt';
        $this->cashOutKey = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_out.key';

        file_put_contents($this->cashInCert, "-----BEGIN CERTIFICATE-----\nIN\n-----END CERTIFICATE-----\n");
        file_put_contents($this->cashInKey, "-----BEGIN PRIVATE KEY-----\nIN\n-----END PRIVATE KEY-----\n");
        file_put_contents($this->cashOutCert, "-----BEGIN CERTIFICATE-----\nOUT\n-----END CERTIFICATE-----\n");
        file_put_contents($this->cashOutKey, "-----BEGIN PRIVATE KEY-----\nOUT\n-----END PRIVATE KEY-----\n");

        Cache::flush();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        GatewayCredential::query()->where('gateway_slug', 'versell')->delete();
        Setting::set('platform_payout_gateway', null, null);

        foreach ([$this->cashInCert, $this->cashInKey, $this->cashOutCert, $this->cashOutKey] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleCredentials(): array
    {
        return [
            'cash_in' => [
                'client_id' => 'ci_client',
                'client_secret' => 'ci_secret',
                'certificate_path' => $this->cashInCert,
                'private_key_path' => $this->cashInKey,
                'pix_key' => 'pix@versell.test',
            ],
            'cash_out' => [
                'client_id' => 'co_client',
                'client_secret' => 'co_secret',
                'certificate_path' => $this->cashOutCert,
                'private_key_path' => $this->cashOutKey,
            ],
            'versell_payout_min_brl' => '5',
            'versell_admin_fee_payout_brl' => '1.5',
        ];
    }

    public function test_status_mapping(): void
    {
        $this->assertSame('paid', VersellPayoutStatuses::mapToLocal('SETTLED'));
        $this->assertSame('paid', VersellPayoutStatuses::mapToLocal('LIQUIDATED'));
        $this->assertSame('failed', VersellPayoutStatuses::mapToLocal('CANCELED'));
        $this->assertSame('pending', VersellPayoutStatuses::mapToLocal('ON_QUEUE'));
        $this->assertSame('refunded', VersellPayoutStatuses::mapToLocal('REFUNDED'));
    }

    public function test_webhook_urls(): void
    {
        config([
            'app.url' => 'https://pay.exemplo.com',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/transfer',
            GatewayWebhookUrl::forGateway('versell.transfer')
        );
        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/cashout',
            GatewayWebhookUrl::forGateway('versell.cashout')
        );
    }

    public function test_economics_from_credentials(): void
    {
        $e = GatewayPayoutEconomics::fromCredentialsArray('versell', $this->sampleCredentials());
        $this->assertSame(5.0, $e['payout_min_brl']);
        $this->assertSame(1.5, $e['admin_fee_payout_brl']);
    }

    public function test_nest_from_flat_preserves_fees(): void
    {
        $nested = VersellCredentials::nestFromFlat([
            'cash_in_client_id' => 'a',
            'cash_in_client_secret' => 'b',
            'cash_in_pix_key' => 'p@x.com',
            'cash_out_client_id' => 'c',
            'cash_out_client_secret' => 'd',
            'versell_payout_min_brl' => '9.5',
            'versell_admin_fee_payout_brl' => '0.4',
        ], []);

        $this->assertSame('9.5', $nested['versell_payout_min_brl']);
        $this->assertSame('0.4', $nested['versell_admin_fee_payout_brl']);
    }

    public function test_platform_payout_prefers_versell_when_forced(): void
    {
        GatewayCredential::query()->whereIn('gateway_slug', ['cajupay', 'woovi', 'bspay', 'onlyup', 'versell'])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        Setting::set('platform_payout_gateway', 'versell', null);

        $this->assertSame('versell', PlatformPayoutGateway::activeSlug());
    }

    public function test_send_persists_idempotency_and_external_id(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        Http::fake([
            'https://pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'co-token',
                'expires_in' => 3600,
            ], 200),
            'https://pagamentos.basspago.com.br/api/v2/pix/payments/dict' => Http::response([
                'endToEndId' => 'E1234567890123456789012345678901',
                'status' => 'ON_QUEUE',
            ], 202),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50,
            'fee_amount' => 0,
            'net_amount' => 50,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
        ]);

        $result = app(VersellPayoutService::class)->sendWithdrawalToPixKey(
            $w,
            'destino@pix.com',
            'email',
            '52998224725'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('E1234567890123456789012345678901', $result['external_id'] ?? null);

        $fresh = $w->fresh();
        $meta = is_array($fresh->payout_meta) ? $fresh->payout_meta : [];
        $this->assertNotEmpty($meta['idempotency_key'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{1,50}$/', $meta['idempotency_key']);

        Http::assertSent(function ($request) use ($meta) {
            return str_contains($request->url(), '/pix/payments/dict')
                && ($request->header('x-idempotency-key')[0] ?? null) === $meta['idempotency_key']
                && ($request['pixKeyType'] ?? null) === 'EMAIL';
        });
    }

    public function test_retry_reuses_same_idempotency_key_without_new_post_when_external_exists(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        Http::fake();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50,
            'fee_amount' => 0,
            'net_amount' => 50,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
            'payout_external_id' => 'EEXISTING',
            'payout_meta' => ['idempotency_key' => 'W9VABCDEF0123456'],
        ]);

        $result = app(VersellPayoutService::class)->sendWithdrawalToPixKey(
            $w,
            'destino@pix.com',
            'email',
            '52998224725'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('EEXISTING', $result['external_id'] ?? null);
        Http::assertNothingSent();
    }

    public function test_auto_payout_dispatches_reconcile(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        GatewayCredential::query()->whereIn('gateway_slug', ['cajupay', 'spacepag', 'woovi', 'bspay', 'onlyup'])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        Setting::set('platform_payout_gateway', 'versell', null);

        Http::fake([
            'https://pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'co-token',
                'expires_in' => 3600,
            ], 200),
            'https://pagamentos.basspago.com.br/api/v2/pix/payments/dict' => Http::response([
                'endToEndId' => 'EAUTO123456789012345678901234567',
                'status' => 'ON_QUEUE',
            ], 202),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key' => 'destino@pix.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false, json_encode($auto));
        $this->assertTrue($auto['pending'] ?? false);

        $fresh = $w->fresh();
        $this->assertSame('versell', $fresh->payout_provider);
        $this->assertSame('EAUTO123456789012345678901234567', $fresh->payout_external_id);
        Queue::assertPushed(ReconcileVersellWithdrawalJob::class);
    }

    public function test_transfer_webhook_marks_paid(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 40,
            'fee_amount' => 0,
            'net_amount' => 40,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
            'payout_provider' => 'versell',
            'payout_external_id' => 'EWH123',
            'payout_meta' => ['idempotency_key' => 'WIDEM1'],
        ]);

        $request = Request::create('/webhooks/gateways/versell/transfer', 'POST', [
            'endToEndId' => 'EWH123',
            'status' => 'SETTLED',
        ]);

        $response = app(VersellPayoutWebhookController::class)->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('paid', $w->fresh()->status);
    }

    public function test_transfer_webhook_liquidated_nested_payload_marks_paid(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 52.97,
            'fee_amount' => 0,
            'net_amount' => 52.97,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
            'payout_provider' => 'versell',
            'payout_external_id' => 'W67VC6321EA65F8A8D8D',
            'payout_meta' => ['idempotency_key' => 'W67VC6321EA65F8A8D8D'],
        ]);

        $request = Request::create('/webhooks/gateways/versell/transfer', 'POST', [
            'type' => 'TRANSFER',
            'data' => [
                'id' => 33580434,
                'status' => 'LIQUIDATED',
                'endToEndId' => 'E372939302026090500152188817ca3d',
                'idempotencyKey' => 'W67VC6321EA65F8A8D8D',
                'remittanceInformation' => 'Saque #67',
            ],
        ]);

        $response = app(VersellPayoutWebhookController::class)->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $fresh = $w->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('E372939302026090500152188817ca3d', $fresh->payout_external_id);
    }

    public function test_cashout_webhook_marks_failed(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 40,
            'fee_amount' => 0,
            'net_amount' => 40,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
            'payout_provider' => 'versell',
            'payout_external_id' => 'EFAIL1',
            'payout_meta' => ['idempotency_key' => 'WIDEMFAIL'],
        ]);

        $request = Request::create('/webhooks/gateways/versell/cashout', 'POST', [
            'endToEndId' => 'EFAIL1',
            'status' => 'FAILED',
        ]);

        $response = app(VersellPayoutWebhookController::class)->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('failed', $w->fresh()->status);
    }

    public function test_process_job_skips_when_external_id_already_set(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 20,
            'fee_amount' => 0,
            'net_amount' => 20,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_external_id' => 'EALREADY',
        ]);

        Http::fake();
        (new ProcessWithdrawalPayoutJob($w->id))->handle(
            app(WithdrawalAutoPayoutService::class),
            app(\App\Services\Api\ApiWithdrawalWebhookService::class)
        );

        Http::assertNothingSent();
        $this->assertSame('pending', $w->fresh()->status);
    }
}
