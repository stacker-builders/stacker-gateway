<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_platform_settings_persist_hostinger_email_provider(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Setting::set('email_provider', 'smtp', null);
        Setting::set('smtp_host', 'smtp.old.example', null);

        $response = $this->actingAs($user)->put('/plataforma/configuracoes', [
            'email_provider' => 'hostinger',
            'hostinger_smtp_username' => 'contato@meudominio.com',
            'hostinger_mail_from_name' => 'Minha Loja',
            'kyc_notification_emails' => '',
        ]);

        $response->assertRedirect();
        $this->assertSame('hostinger', Setting::get('email_provider', 'smtp', null));
        $this->assertSame('contato@meudominio.com', Setting::get('hostinger_smtp_username', '', null));
        $this->assertSame('contato@meudominio.com', Setting::get('hostinger_mail_from_address', '', null));

        $page = $this->actingAs($user)->get('/plataforma/configuracoes?tab=email');
        $page->assertOk();
        $page->assertInertia(fn ($assert) => $assert
            ->component('Settings/Index')
            ->where('settings.email_provider', 'hostinger'));
    }

    public function test_platform_settings_can_switch_email_provider_via_post(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Setting::set('email_provider', 'hostinger', null);
        Setting::set('hostinger_smtp_username', 'old@meudominio.com', null);

        $response = $this->actingAs($user)->post('/plataforma/configuracoes', [
            'email_provider' => 'sendgrid',
            'sendgrid_mail_from_address' => 'noreply@meudominio.com',
            'sendgrid_mail_from_name' => 'Loja',
            'kyc_notification_emails' => 'ops@meudominio.com',
        ]);

        $response->assertRedirect();
        $this->assertSame('sendgrid', Setting::get('email_provider', 'smtp', null));
        $this->assertSame('noreply@meudominio.com', Setting::get('sendgrid_mail_from_address', '', null));
        $this->assertSame('ops@meudominio.com', Setting::get('kyc_notification_emails', '', null));
        // Credenciais do provedor anterior permanecem (troca só ativa o novo).
        $this->assertSame('old@meudominio.com', Setting::get('hostinger_smtp_username', '', null));
    }

    public function test_platform_settings_persist_resend_email_provider(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Setting::set('email_provider', 'smtp', null);

        $response = $this->actingAs($user)->put('/plataforma/configuracoes', [
            'email_provider' => 'resend',
            'resend_api_key' => 're_test_key',
            'resend_mail_from_address' => 'ola@meudominio.com',
            'resend_mail_from_name' => 'Minha Loja',
            'kyc_notification_emails' => '',
        ]);

        $response->assertRedirect();
        $this->assertSame('resend', Setting::get('email_provider', 'smtp', null));
        $this->assertSame('ola@meudominio.com', Setting::get('resend_mail_from_address', '', null));
        $this->assertSame('Minha Loja', Setting::get('resend_mail_from_name', '', null));
        $this->assertSame('re_test_key', decrypt(Setting::get('resend_api_key', '', null)));

        $page = $this->actingAs($user)->get('/plataforma/configuracoes?tab=email');
        $page->assertOk();
        $page->assertInertia(fn ($assert) => $assert
            ->component('Settings/Index')
            ->where('settings.email_provider', 'resend')
            ->where('settings.resend_mail_from_address', 'ola@meudominio.com')
            ->missing('settings.resend_api_key'));
    }

    public function test_resend_provider_applies_smtp_resend_host(): void
    {
        Setting::set('email_provider', 'resend', null);
        Setting::set('resend_api_key', encrypt('re_test_key'), null);
        Setting::set('resend_mail_from_address', 'hello@meudominio.com', null);
        Setting::set('resend_mail_from_name', 'Loja', null);

        app(\App\Services\TenantMailConfigService::class)->applyPlatformGlobalMailerConfig();

        $this->assertSame('smtp.resend.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, (int) config('mail.mailers.smtp.port'));
        $this->assertSame('tls', config('mail.mailers.smtp.encryption'));
        $this->assertSame('resend', config('mail.mailers.smtp.username'));
        $this->assertSame('re_test_key', config('mail.mailers.smtp.password'));
        $this->assertSame('hello@meudominio.com', config('mail.from.address'));
        $this->assertSame('Loja', config('mail.from.name'));
    }

    public function test_platform_email_settings_require_totp_when_enabled(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user->forceFill([
            'totp_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'totp_enabled_at' => now(),
        ])->save();

        Setting::set('email_provider', 'smtp', null);

        $response = $this->actingAs($user)->from('/plataforma/configuracoes?tab=email')->post('/plataforma/configuracoes', [
            'email_provider' => 'hostinger',
            'hostinger_smtp_username' => 'contato@meudominio.com',
        ]);

        $response->assertRedirect('/plataforma/configuracoes?tab=email');
        $response->assertSessionHasErrors('totp_code');
        $this->assertSame('smtp', Setting::get('email_provider', 'smtp', null));
    }

    public function test_email_test_endpoint_returns_success()
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Prepare some settings (global)
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('mail_from_address', 'noreply@example.com', null);
        Setting::set('mail_from_name', 'Example', null);

        Mail::fake();

        $response = $this->actingAs($user)->postJson('/plataforma/configuracoes/email/test', [
            'test_to' => 'test@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_email_send_test_endpoint_returns_success()
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('mail_from_address', 'noreply@example.com', null);
        Setting::set('mail_from_name', 'Example', null);

        Mail::fake();

        $response = $this->actingAs($user)->postJson('/plataforma/configuracoes/email/send-test', [
            'test_to' => 'test@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }
}
