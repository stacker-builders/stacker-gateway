<?php

namespace Tests\Feature;

use App\Mail\ManualApprovalPinResetAdminMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Services\WithdrawalAutoPayoutService;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WithdrawalPolicyTest extends TestCase
{
    public function test_auto_withdrawal_disabled_skips_auto_payout(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        Setting::set('platform_auto_withdrawal_enabled', false, null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50,
            'fee_amount' => 0,
            'net_amount' => 50,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $result = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($withdrawal);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('auto_withdrawal_disabled', $result['reason'] ?? null);
    }

    public function test_withdrawal_hours_block_outside_window(): void
    {
        Setting::set('platform_withdrawal_hours_enabled', true, null);
        Setting::set('platform_withdrawal_hours_start', '06:00', null);
        Setting::set('platform_withdrawal_hours_end', '21:00', null);
        Setting::set('platform_withdrawal_timezone', 'America/Sao_Paulo', null);

        $blocked = Carbon::parse('2026-06-09 22:30:00', 'America/Sao_Paulo');
        $allowed = Carbon::parse('2026-06-09 10:00:00', 'America/Sao_Paulo');

        $this->assertFalse(WithdrawalPolicyService::allowsRequestAt($blocked));
        $this->assertTrue(WithdrawalPolicyService::allowsRequestAt($allowed));
    }

    public function test_withdrawal_days_block_unselected_weekdays(): void
    {
        Setting::set('platform_withdrawal_hours_enabled', false, null);
        Setting::set('platform_withdrawal_allowed_days', [1, 2, 3, 4, 5], null);
        Setting::set('platform_withdrawal_timezone', 'America/Sao_Paulo', null);

        $tuesday = Carbon::parse('2026-06-09 10:00:00', 'America/Sao_Paulo');
        $sunday = Carbon::parse('2026-06-14 10:00:00', 'America/Sao_Paulo');

        $this->assertTrue(WithdrawalPolicyService::allowsRequestAt($tuesday));
        $this->assertFalse(WithdrawalPolicyService::allowsRequestAt($sunday));
        $this->assertStringContainsString('sexta', WithdrawalPolicyService::requestBlockedMessage($sunday));
    }

    public function test_missing_allowed_days_keeps_every_day_open(): void
    {
        Setting::set('platform_withdrawal_hours_enabled', false, null);
        Setting::set('platform_withdrawal_timezone', 'America/Sao_Paulo', null);

        $sunday = Carbon::parse('2026-06-14 22:30:00', 'America/Sao_Paulo');

        $this->assertTrue(WithdrawalPolicyService::allowsRequestAt($sunday));
    }

    public function test_admin_can_save_allowed_withdrawal_days(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'allowed_days' => [1, 3, 5],
        ]);

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $this->assertSame([1, 3, 5], WithdrawalPolicyService::allowedDays());
    }

    public function test_admin_cannot_clear_every_withdrawal_day(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'allowed_days' => [],
        ]);

        $response->assertSessionHasErrors('allowed_days');
        $this->assertSame(WithdrawalPolicyService::ALL_WEEKDAYS, WithdrawalPolicyService::allowedDays());
    }

    public function test_interval_blocks_a_second_withdrawal_until_hours_pass(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        WithdrawalPolicyService::setInterval(true, 6);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 40,
            'fee_amount' => 0,
            'net_amount' => 40,
            'bucket' => 'pix',
            'status' => 'paid',
            'currency' => 'BRL',
        ]);

        try {
            WithdrawalPolicyService::assertIntervalAllows((int) $seller->id);
            $this->fail('O intervalo entre saques deveria bloquear a nova solicitação.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        Carbon::setTestNow(now()->addHours(6));
        WithdrawalPolicyService::assertIntervalAllows((int) $seller->id);
        Carbon::setTestNow();
    }

    public function test_rejected_withdrawal_does_not_start_the_interval(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        WithdrawalPolicyService::setInterval(true, 24);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 40,
            'fee_amount' => 0,
            'net_amount' => 40,
            'bucket' => 'pix',
            'status' => 'rejected',
            'currency' => 'BRL',
        ]);

        WithdrawalPolicyService::assertIntervalAllows((int) $seller->id);
    }

    public function test_platform_admin_can_update_withdrawal_policy(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'auto_withdrawal_enabled' => false,
            'hours_enabled' => true,
            'hours_start' => '07:00',
            'hours_end' => '20:00',
            'timezone' => 'America/Sao_Paulo',
            'manual_approval_pin' => '1234',
            'manual_approval_pin_confirmation' => '1234',
        ]);

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $this->assertFalse(WithdrawalPolicyService::autoWithdrawalEnabled());
        $this->assertTrue(WithdrawalPolicyService::verifyManualApprovalPin('1234'));
    }

    public function test_pin_change_requires_current_pin_when_pin_exists(): void
    {
        $admin = $this->platformAdmin();
        WithdrawalPolicyService::setManualApprovalPin('1234');

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'manual_approval_pin' => '5678',
            'manual_approval_pin_confirmation' => '5678',
        ]);

        $response->assertSessionHasErrors('current_manual_approval_pin');
        $this->assertTrue(WithdrawalPolicyService::verifyManualApprovalPin('1234'));
    }

    public function test_pin_change_with_wrong_current_pin_fails(): void
    {
        $admin = $this->platformAdmin();
        WithdrawalPolicyService::setManualApprovalPin('1234');

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'current_manual_approval_pin' => '9999',
            'manual_approval_pin' => '5678',
            'manual_approval_pin_confirmation' => '5678',
        ]);

        $response->assertSessionHasErrors('current_manual_approval_pin');
        $this->assertTrue(WithdrawalPolicyService::verifyManualApprovalPin('1234'));
    }

    public function test_pin_change_with_correct_current_pin_succeeds(): void
    {
        $admin = $this->platformAdmin();
        WithdrawalPolicyService::setManualApprovalPin('1234');

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'current_manual_approval_pin' => '1234',
            'manual_approval_pin' => '5678',
            'manual_approval_pin_confirmation' => '5678',
        ]);

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $this->assertTrue(WithdrawalPolicyService::verifyManualApprovalPin('5678'));
        $this->assertFalse(WithdrawalPolicyService::verifyManualApprovalPin('1234'));
    }

    public function test_pin_confirmation_mismatch_fails(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'manual_approval_pin' => '1234',
            'manual_approval_pin_confirmation' => '9999',
        ]);

        $response->assertSessionHasErrors('manual_approval_pin_confirmation');
        $this->assertFalse(WithdrawalPolicyService::hasManualApprovalPin());
    }

    public function test_pin_longer_than_six_digits_fails(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'manual_approval_pin' => '1234567',
            'manual_approval_pin_confirmation' => '1234567',
        ]);

        $response->assertSessionHasErrors('manual_approval_pin');
        $this->assertFalse(WithdrawalPolicyService::hasManualApprovalPin());
    }

    public function test_pin_reset_sends_admin_email_when_configured(): void
    {
        Mail::fake();

        $admin = $this->platformAdmin();
        WithdrawalPolicyService::setManualApprovalPin('1234');
        Setting::set('kyc_notification_emails', 'ops@example.com', null);

        $response = $this->actingAs($admin)->post(route('plataforma.financeiro.saques-politica.pin-reset'));

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $response->assertSessionHas('success');

        Mail::assertSent(ManualApprovalPinResetAdminMail::class, function (ManualApprovalPinResetAdminMail $mail) use ($admin) {
            return $mail->requestedBy->is($admin)
                && strlen($mail->pin) === 6
                && ctype_digit($mail->pin);
        });
    }

    public function test_pin_reset_without_admin_emails_fails(): void
    {
        Mail::fake();

        $admin = $this->platformAdmin();
        WithdrawalPolicyService::setManualApprovalPin('1234');
        Setting::set('kyc_notification_emails', '', null);

        $response = $this->actingAs($admin)->post(route('plataforma.financeiro.saques-politica.pin-reset'));

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $response->assertSessionHas('error');
        Mail::assertNothingSent();
    }

    public function test_pin_reset_respects_rate_limit(): void
    {
        Mail::fake();

        $admin = $this->platformAdmin();
        WithdrawalPolicyService::setManualApprovalPin('1234');
        Setting::set('kyc_notification_emails', 'ops@example.com', null);

        RateLimiter::clear('platform-pin-reset:'.$admin->id);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)->post(route('plataforma.financeiro.saques-politica.pin-reset'))
                ->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        }

        $response = $this->actingAs($admin)->post(route('plataforma.financeiro.saques-politica.pin-reset'));

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $response->assertSessionHas('error');
        Mail::assertSent(ManualApprovalPinResetAdminMail::class, 3);
    }

    public function test_payout_barrier_is_totp_or_operation_pin(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->assertTrue(WithdrawalPolicyService::requiresOperationPinFor($admin));
        $this->assertFalse(WithdrawalPolicyService::hasPayoutSecurityBarrier($admin));

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $this->assertTrue(WithdrawalPolicyService::hasPayoutSecurityBarrier($admin));
        $this->assertTrue(WithdrawalPolicyService::requiresOperationPinFor($admin));
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }
}
