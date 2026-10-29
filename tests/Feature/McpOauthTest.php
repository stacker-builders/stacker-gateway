<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\McpCredential;
use App\Models\McpOauthAccessToken;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class McpOauthTest extends TestCase
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

    public function test_well_known_metadata_is_public(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonPath('resource', 'https://example.test/mcp')
            ->assertJsonPath('authorization_servers.0', 'https://example.test');

        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', 'https://example.test/mcp');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://example.test')
            ->assertJsonPath('authorization_endpoint', 'https://example.test/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://example.test/oauth/token')
            ->assertJsonPath('registration_endpoint', 'https://example.test/oauth/register')
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');

        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://example.test');
    }

    public function test_mcp_get_without_token_advertises_oauth(): void
    {
        $response = $this->get('/mcp');
        $response->assertUnauthorized();
        $this->assertStringContainsString(
            'resource_metadata="https://example.test/.well-known/oauth-protected-resource"',
            (string) $response->headers->get('WWW-Authenticate')
        );
    }

    public function test_claude_bearer_still_works_after_chatgpt_oauth(): void
    {
        [$seller, $claudeToken] = $this->enableMcp();
        [$verifier, $challenge] = $this->pkcePair();
        $clientId = $this->registerClient();
        $redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';

        $this->actingAs($seller)
            ->get('/oauth/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirect,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'resource' => 'https://example.test/mcp',
                'state' => 'st-1',
            ]))
            ->assertOk()
            ->assertSee('Conectar MCP');

        $approve = $this->actingAs($seller)
            ->post('/oauth/authorize', ['decision' => 'approve']);
        $approve->assertRedirect();
        $location = (string) $approve->headers->get('Location');
        $this->assertStringStartsWith($redirect, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['code'] ?? null);
        $this->assertSame('st-1', $query['state'] ?? null);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $query['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
        ])->assertOk()->json();

        $this->assertStringStartsWith('mcp_at_', $tokens['access_token']);
        $this->assertStringStartsWith('mcp_rt_', $tokens['refresh_token']);
        $this->assertSame(1, McpCredential::query()->where('tenant_id', $seller->id)->count());
        $this->assertSame(
            McpCredential::parsePublicId($claudeToken),
            McpCredential::query()->where('tenant_id', $seller->id)->value('public_id')
        );

        $this->initializeMcp($claudeToken)
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'getfy-catalog');

        $this->initializeMcp($tokens['access_token'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'getfy-catalog');
    }

    public function test_rotating_claude_token_does_not_revoke_chatgpt(): void
    {
        [$seller, $claudeToken] = $this->enableMcp();
        $oauthAccess = $this->issueOauthAccess($seller);

        $newClaude = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.rotate'))
            ->assertOk()
            ->json('token');

        $this->assertStringStartsWith('mcp_live_', $newClaude);
        $this->assertNotSame($claudeToken, $newClaude);

        $this->initializeMcp($claudeToken)->assertUnauthorized();
        $this->initializeMcp($newClaude)->assertOk();
        $this->initializeMcp($oauthAccess)->assertOk();
    }

    public function test_revoking_mcp_stops_claude_and_chatgpt(): void
    {
        [$seller, $claudeToken] = $this->enableMcp();
        $oauthAccess = $this->issueOauthAccess($seller);

        $this->actingAs($seller)
            ->deleteJson(route('integrations.mcp.destroy'))
            ->assertOk();

        $this->assertSame(0, McpOauthAccessToken::query()->where('tenant_id', $seller->id)->count());
        $this->initializeMcp($claudeToken)->assertUnauthorized();
        $this->initializeMcp($oauthAccess)->assertUnauthorized();
    }

    public function test_pkce_mismatch_is_rejected_and_does_not_touch_claude_token(): void
    {
        [$seller, $claudeToken] = $this->enableMcp();
        [, $challenge] = $this->pkcePair();
        $clientId = $this->registerClient();
        $redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';

        $this->actingAs($seller)->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk();

        $approve = $this->actingAs($seller)->post('/oauth/authorize', ['decision' => 'approve']);
        parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $query['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => str_repeat('a', 43),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->initializeMcp($claudeToken)->assertOk();
    }

    public function test_login_keeps_oauth_authorize_intended(): void
    {
        [$seller] = $this->enableMcp();
        [$verifier, $challenge] = $this->pkcePair();
        $clientId = $this->registerClient();
        $qs = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'after-login',
        ]);

        $this->get('/oauth/authorize?'.$qs)->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $login = $this->post('/login', [
            'email' => $seller->email,
            'password' => 'password',
        ]);
        $login->assertRedirect();
        $location = (string) $login->headers->get('Location');
        $this->assertStringContainsString('/oauth/authorize?', $location);
        $this->assertStringContainsString('client_id='.$clientId, $location);
        $this->assertStringContainsString('state=after-login', $location);

        $this->get('/oauth/authorize?'.$qs)->assertOk()->assertSee('Conectar MCP');
        $this->assertNotEmpty($verifier);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function enableMcp(): array
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();
        $seller = $seller->fresh();

        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::MCP,
            (int) $seller->id,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $token = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertCreated()
            ->json('token');

        Auth::logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return [$seller, $token];
    }

    private function registerClient(): string
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'ChatGPT',
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    private function issueOauthAccess(User $seller): string
    {
        [$verifier, $challenge] = $this->pkcePair();
        $clientId = $this->registerClient();
        $redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';

        $this->actingAs($seller)->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk();

        $approve = $this->actingAs($seller)->post('/oauth/authorize', ['decision' => 'approve']);
        parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $query['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function initializeMcp(string $token)
    {
        return $this->postJson('/mcp', [
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
        ]);
    }
}
