<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class PlatformCustomerAccountAccessTest extends TestCase
{
    use GeneratesTotpCodes;

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function customerWithOrder(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Produto Acesso',
            'price' => 50,
        ]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'name' => 'Cliente Acesso',
            'email' => 'cliente.acesso@example.com',
            'password' => 'senha-antiga',
        ]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'digitado-na-compra@example.com',
            'payment_method' => 'pix',
            'gateway' => 'demo',
            'approved_manually' => false,
        ]);

        return $customer->fresh();
    }

    public function test_email_and_password_changes_are_refused_without_2fa_or_pin(): void
    {
        $admin = $this->platformAdmin();
        $customer = $this->customerWithOrder();

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.email.update', $customer), [
                'email' => 'corrigido@example.com',
            ])
            ->assertSessionHasErrors('totp_code');

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.password.reset', $customer))
            ->assertSessionHasErrors('totp_code');

        $this->assertSame('cliente.acesso@example.com', $customer->fresh()->email);
        $this->assertTrue(Hash::check('senha-antiga', $customer->fresh()->password));
    }

    public function test_admin_updates_login_email_with_operation_pin_and_keeps_order_email(): void
    {
        WithdrawalPolicyService::setManualApprovalPin('1234');
        $admin = $this->platformAdmin();
        $customer = $this->customerWithOrder();

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.email.update', $customer), [
                'email' => 'Corrigido@example.com',
                'manual_approval_pin' => '1234',
            ])
            ->assertRedirect(route('plataforma.clientes.show', $customer));

        $customer->refresh();
        $this->assertSame('corrigido@example.com', $customer->email);
        $this->assertNull($customer->email_verified_at);
        $this->assertSame(
            'digitado-na-compra@example.com',
            Order::query()->where('user_id', $customer->id)->value('email')
        );
    }

    public function test_wrong_pin_does_not_change_email(): void
    {
        WithdrawalPolicyService::setManualApprovalPin('1234');
        $admin = $this->platformAdmin();
        $customer = $this->customerWithOrder();

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.email.update', $customer), [
                'email' => 'outro@example.com',
                'manual_approval_pin' => '0000',
            ])
            ->assertSessionHasErrors('totp_code');

        $this->assertSame('cliente.acesso@example.com', $customer->fresh()->email);
    }

    public function test_duplicate_email_is_rejected_after_pin(): void
    {
        WithdrawalPolicyService::setManualApprovalPin('1234');
        $admin = $this->platformAdmin();
        $customer = $this->customerWithOrder();
        User::factory()->create([
            'email' => 'ocupado@example.com',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.email.update', $customer), [
                'email' => 'ocupado@example.com',
                'manual_approval_pin' => '1234',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('cliente.acesso@example.com', $customer->fresh()->email);
    }

    public function test_admin_with_2fa_resets_password_without_pin(): void
    {
        $admin = $this->platformAdmin();
        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        PlatformTotpService::confirmEnrollment(
            $admin->fresh(),
            $this->totpCodeForSecret($setup['secret'])
        );
        $customer = $this->customerWithOrder();

        $response = $this->actingAs($admin->fresh())
            ->post(route('plataforma.clientes.password.reset', $customer), [
                'totp_code' => $this->totpCodeForSecret($setup['secret']),
            ]);

        $response->assertRedirect(route('plataforma.clientes.show', $customer));
        $response->assertSessionHas('customer_access_password');
        $plain = (string) session('customer_access_password');
        $this->assertNotSame('', $plain);
        $this->assertTrue(Hash::check($plain, $customer->fresh()->password));
        $this->assertFalse(Hash::check('senha-antiga', $customer->fresh()->password));
    }
}
