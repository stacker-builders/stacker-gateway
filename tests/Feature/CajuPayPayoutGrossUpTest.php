<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutGrossUp;
use App\Services\CajuPay\CajuPayPayoutService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayPayoutGrossUpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_default_sends_exact_net_even_when_payout_fee_is_configured(): void
    {
        $this->fakePayoutApi();
        $this->seedCajuCredential(2.0);
        $withdrawal = $this->makeWithdrawal(50.00);

        $result = (new CajuPayPayoutService)->sendWithdrawalToPixKey(
            $withdrawal->fresh(),
            null,
            'aluno@example.com',
            'email',
            '52998224725'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(5000, $this->sentAmountCents());
    }

    public function test_enabled_gross_up_adds_fixed_payout_fee(): void
    {
        $this->fakePayoutApi();
        $this->seedCajuCredential(2.0);
        CajuPayPayoutGrossUp::setEnabled(true);
        $withdrawal = $this->makeWithdrawal(50.00);

        $result = (new CajuPayPayoutService)->sendWithdrawalToPixKey(
            $withdrawal->fresh(),
            null,
            'aluno@example.com',
            'email',
            '52998224725'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(5200, $this->sentAmountCents());
    }

    public function test_platform_admin_can_toggle_gross_up_and_it_defaults_off(): void
    {
        $this->assertFalse(CajuPayPayoutGrossUp::enabled());

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.financeiro.cajupay.gross-up'), ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->assertTrue(CajuPayPayoutGrossUp::enabled());

        $this->actingAs($admin)
            ->putJson(route('plataforma.financeiro.cajupay.gross-up'), ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertFalse(CajuPayPayoutGrossUp::enabled());
    }

    private function fakePayoutApi(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response(['id' => 'payout-gross-up'], 200),
        ]);
    }

    private function seedCajuCredential(float $payoutFeeBrl): void
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => (string) $payoutFeeBrl,
            'cajupay_admin_fee_payout_percent' => '0',
        ]);
        $cred->save();
    }

    private function makeWithdrawal(float $net): Withdrawal
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key' => 'aluno@example.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        return Withdrawal::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => $net,
            'fee_amount' => 0,
            'net_amount' => $net,
            'bucket' => 'pix',
            'status' => 'pending',
        ]);
    }

    private function sentAmountCents(): int
    {
        $amount = null;
        Http::assertSent(function ($request) use (&$amount) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if (! str_ends_with($path, '/api/payouts')) {
                return false;
            }
            $json = json_decode($request->body(), true);
            $amount = is_array($json) ? ($json['amount_cents'] ?? null) : null;

            return true;
        });

        return (int) $amount;
    }
}
