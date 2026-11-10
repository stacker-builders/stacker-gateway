<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Platform\PlatformAiReportService;
use App\Support\PlatformAiReportSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformAiReportTest extends TestCase
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

    public function test_admin_saves_provider_and_encrypted_key(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->put('/plataforma/configuracoes', [
            'platform_ai_report_enabled' => '1',
            'platform_ai_report_provider' => 'openai',
            'platform_ai_report_api_key' => 'sk-test-chave-123456',
        ])->assertRedirect();

        $this->assertTrue(PlatformAiReportSettings::isReady());
        $this->assertSame('openai', PlatformAiReportSettings::provider());
        $this->assertSame('sk-test-chave-123456', PlatformAiReportSettings::apiKey());
        $stored = (string) Setting::get(PlatformAiReportSettings::KEY_API_KEY, '', null);
        $this->assertNotSame('sk-test-chave-123456', $stored);
        $this->assertSame('sk-test-chave-123456', Crypt::decryptString($stored));
    }

    public function test_settings_page_hides_the_api_key(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'gemini', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('AIza-chave-secreta-123'), null);

        $this->actingAs($this->platformAdmin())
            ->get('/plataforma/configuracoes?tab=relatorio_ia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('settings.platform_ai_report_enabled', '1')
                ->where('settings.platform_ai_report_provider', 'gemini')
                ->where('settings.platform_ai_report_api_key_configured', true)
                ->missing('settings.platform_ai_report_api_key'));
    }

    public function test_blank_key_keeps_the_saved_one(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'gemini', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('AIza-chave-antiga-123'), null);

        $this->actingAs($this->platformAdmin())->put('/plataforma/configuracoes', [
            'platform_ai_report_enabled' => '1',
            'platform_ai_report_provider' => 'gemini',
            'platform_ai_report_api_key' => '',
        ])->assertRedirect();

        $this->assertSame('AIza-chave-antiga-123', PlatformAiReportSettings::apiKey());
    }

    public function test_enabling_without_a_key_is_rejected(): void
    {
        $response = $this->actingAs($this->platformAdmin())
            ->from('/plataforma/configuracoes?tab=relatorio_ia')
            ->put('/plataforma/configuracoes', [
                'platform_ai_report_enabled' => '1',
                'platform_ai_report_provider' => 'gemini',
                'platform_ai_report_api_key' => '',
            ]);

        $response->assertRedirect('/plataforma/configuracoes?tab=relatorio_ia');
        $response->assertSessionHasErrors('platform_ai_report_api_key');
        $this->assertFalse(PlatformAiReportSettings::isEnabled());
    }

    public function test_switching_provider_without_a_new_key_is_rejected(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'gemini', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('AIza-chave-antiga-123'), null);

        $response = $this->actingAs($this->platformAdmin())
            ->from('/plataforma/configuracoes?tab=relatorio_ia')
            ->put('/plataforma/configuracoes', [
                'platform_ai_report_enabled' => '1',
                'platform_ai_report_provider' => 'openai',
                'platform_ai_report_api_key' => '',
            ]);

        $response->assertSessionHasErrors('platform_ai_report_api_key');
        $this->assertSame('gemini', PlatformAiReportSettings::provider());
        $this->assertSame('AIza-chave-antiga-123', PlatformAiReportSettings::apiKey());
    }

    public function test_dashboard_hides_the_button_until_the_report_is_ready(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ai_report_available', false));

        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'openai', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('sk-test-chave-123456'), null);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ai_report_available', true));
    }

    public function test_report_sends_totals_without_the_buyer_email(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'openai', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('sk-test-chave-123456'), null);

        $seller = $this->infoprodutor();
        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'email' => 'comprador-secreto-ia@example.com',
        ]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Curso de teste']);
        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 80,
            'email' => 'comprador-secreto-ia@example.com',
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Resumo\nVolume de R$ 80 em 1 venda."]],
                ],
            ]),
        ]);

        $this->actingAs($this->platformAdmin())
            ->postJson('/plataforma/dashboard/relatorio-ia', ['period' => 'hoje'])
            ->assertOk()
            ->assertJsonPath('analysis', "Resumo\nVolume de R$ 80 em 1 venda.")
            ->assertJsonPath('provider', 'openai')
            ->assertJsonPath('provider_label', 'OpenAI');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.openai.com/v1/chat/completions')
                && str_contains($request->body(), 'Curso de teste')
                && str_contains($request->body(), '"model":"'.PlatformAiReportService::OPENAI_MODEL.'"')
                && ! str_contains($request->body(), 'comprador-secreto-ia@example.com')
                && ! str_contains($request->body(), 'sk-test-chave-123456');
        });
    }

    public function test_gemini_report_uses_the_gemini_endpoint(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'gemini', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('AIza-chave-gemini-123'), null);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Resumo do Gemini']]]],
                ],
            ]),
        ]);

        $this->actingAs($this->platformAdmin())
            ->postJson('/plataforma/dashboard/relatorio-ia', ['period' => 'hoje'])
            ->assertOk()
            ->assertJsonPath('analysis', 'Resumo do Gemini')
            ->assertJsonPath('provider_label', 'Gemini');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), PlatformAiReportService::GEMINI_MODEL.':generateContent')
                && $request->hasHeader('x-goog-api-key', 'AIza-chave-gemini-123')
                && ! str_contains($request->url(), 'AIza-chave-gemini-123');
        });
    }

    public function test_rejected_key_returns_a_safe_message(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'openai', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('sk-test-chave-123456'), null);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'error' => ['message' => 'Incorrect API key sk-test-chave-123456'],
            ], 401),
        ]);

        $this->actingAs($this->platformAdmin())
            ->postJson('/plataforma/dashboard/relatorio-ia', ['period' => 'hoje'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A OpenAI recusou a chave. Confira o token em Configurações.');
    }

    public function test_seller_cannot_generate_the_report(): void
    {
        Setting::set(PlatformAiReportSettings::KEY_ENABLED, '1', null);
        Setting::set(PlatformAiReportSettings::KEY_PROVIDER, 'gemini', null);
        Setting::set(PlatformAiReportSettings::KEY_API_KEY, Crypt::encryptString('AIza-chave-gemini-123'), null);

        $this->actingAs($this->infoprodutor())
            ->postJson('/plataforma/dashboard/relatorio-ia', ['period' => 'hoje'])
            ->assertForbidden();
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function infoprodutor(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $attrs = ['tenant_id' => $seller->id, 'account_status' => 'approved'];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        $seller->forceFill($attrs)->save();

        return $seller;
    }
}
