<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class GatewayStepUpTest extends TestCase
{
    use GeneratesTotpCodes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function platformAdminWithTotp(): array
    {
        $admin = $this->platformAdmin();
        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        PlatformTotpService::confirmEnrollment(
            $admin->fresh(),
            $this->totpCodeForSecret($setup['secret'])
        );

        return [$admin->fresh(), $setup['secret']];
    }

    public function test_stripe_gateway_show_includes_webhook_url(): void
    {
        config([
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
            'app.url' => 'http://localhost',
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->getJson('/plataforma/financeiro/gateways/stripe')
            ->assertOk()
            ->assertJsonPath('webhook_url', 'https://pay.exemplo.com/webhooks/gateways/stripe')
            ->assertJsonPath('slug', 'stripe');
    }

    public function test_gateway_update_without_totp_when_not_enabled(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->putJson('/plataforma/financeiro/gateways/woovi', [
            'app_id' => 'test-app-id',
            'from_pix_key' => 'pix@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_gateway_update_requires_totp_when_enabled(): void
    {
        [$admin] = $this->platformAdminWithTotp();

        $response = $this->actingAs($admin)->putJson('/plataforma/financeiro/gateways/woovi', [
            'app_id' => 'test-app-id',
            'from_pix_key' => 'pix@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['totp_code']);
    }

    public function test_gateway_update_accepts_valid_totp(): void
    {
        [$admin, $secret] = $this->platformAdminWithTotp();

        $response = $this->actingAs($admin)->putJson('/plataforma/financeiro/gateways/woovi', [
            'app_id' => 'test-app-id',
            'from_pix_key' => 'pix@example.com',
            'totp_code' => $this->totpCodeForSecret($secret),
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_gateway_enabled_toggle_requires_totp_when_enabled(): void
    {
        [$admin] = $this->platformAdminWithTotp();

        $response = $this->actingAs($admin)->putJson('/plataforma/financeiro/gateways/woovi/enabled', [
            'is_enabled' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['totp_code']);
    }

    public function test_cajupay_account_update_requires_totp_when_enabled(): void
    {
        [$admin] = $this->platformAdminWithTotp();

        $account = \App\Models\CajuPayAccount::query()->create([
            'name' => 'Conta teste',
            'is_default' => true,
            'is_connected' => false,
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($admin)->putJson('/plataforma/financeiro/cajupay-contas/'.$account->id, [
            'name' => 'Conta atualizada',
            'is_enabled' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['totp_code']);
    }
}
