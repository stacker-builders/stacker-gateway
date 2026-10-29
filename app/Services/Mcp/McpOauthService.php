<?php

namespace App\Services\Mcp;

use App\Models\McpCredential;
use App\Models\McpOauthAccessToken;
use App\Models\McpOauthClient;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class McpOauthService
{
    public const SCOPE = 'mcp';

    private const CODE_TTL_SECONDS = 600;

    private const ACCESS_TTL_HOURS = 8;

    private const REFRESH_TTL_DAYS = 30;

    public function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function resourceUrl(): string
    {
        return rtrim(url('/mcp'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resourceUrl(),
            'authorization_servers' => [$this->issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE],
            'resource_documentation' => $this->issuer().'/integracoes',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationServerMetadata(): array
    {
        $issuer = $this->issuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
        ];
    }

    public function wwwAuthenticate(): string
    {
        $metadata = $this->issuer().'/.well-known/oauth-protected-resource';

        return 'Bearer realm="mcp", resource_metadata="'.$metadata.'"';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerClient(array $payload): array
    {
        $name = is_string($payload['client_name'] ?? null) ? trim($payload['client_name']) : null;
        $uris = $this->normalizeRedirectUris($payload['redirect_uris'] ?? null);
        if ($uris === []) {
            throw new McpOauthException('invalid_client_metadata', 'redirect_uris inválidos.');
        }

        $method = is_string($payload['token_endpoint_auth_method'] ?? null)
            ? strtolower(trim($payload['token_endpoint_auth_method']))
            : 'none';
        if ($method === '') {
            $method = 'none';
        }
        if (! in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            $method = 'none';
        }

        $client = McpOauthClient::query()->create([
            'client_id' => Str::lower(Str::random(32)),
            'client_name' => $name !== '' ? $name : null,
            'redirect_uris' => $uris,
            'token_endpoint_auth_method' => $method,
        ]);

        return [
            'client_id' => $client->client_id,
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $client->token_endpoint_auth_method,
            'client_id_issued_at' => $client->created_at?->timestamp ?? time(),
        ];
    }

    public function findClient(string $clientId): ?McpOauthClient
    {
        if ($clientId === '') {
            return null;
        }

        return McpOauthClient::query()->where('client_id', $clientId)->first();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: true, params: array<string, string>}|array{ok: false, error: string, description: string, redirect: bool, params?: array<string, string>}
     */
    public function validateAuthorizeRequest(array $query): array
    {
        $clientId = is_string($query['client_id'] ?? null) ? trim($query['client_id']) : '';
        $redirectUri = is_string($query['redirect_uri'] ?? null) ? trim($query['redirect_uri']) : '';
        $responseType = is_string($query['response_type'] ?? null) ? trim($query['response_type']) : '';
        $codeChallenge = is_string($query['code_challenge'] ?? null) ? trim($query['code_challenge']) : '';
        $method = is_string($query['code_challenge_method'] ?? null) ? trim($query['code_challenge_method']) : '';
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $resource = is_string($query['resource'] ?? null) ? rtrim(trim($query['resource']), '/') : '';
        $scope = is_string($query['scope'] ?? null) ? trim($query['scope']) : self::SCOPE;

        $client = $this->findClient($clientId);
        if ($client === null) {
            return ['ok' => false, 'error' => 'invalid_client', 'description' => 'client_id desconhecido.', 'redirect' => false];
        }

        if ($redirectUri === '' || ! $client->allowsRedirect($redirectUri) || ! $this->isSafeRedirectUri($redirectUri)) {
            return ['ok' => false, 'error' => 'invalid_request', 'description' => 'redirect_uri não autorizado.', 'redirect' => false];
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $method,
            'state' => $state,
            'resource' => $resource,
            'scope' => $scope,
            'client_name' => (string) ($client->client_name ?: 'ChatGPT'),
        ];

        if ($responseType !== 'code') {
            return ['ok' => false, 'error' => 'unsupported_response_type', 'description' => 'Use response_type=code.', 'redirect' => true, 'params' => $params];
        }

        if ($method !== 'S256' || ! $this->isValidCodeChallenge($codeChallenge)) {
            return ['ok' => false, 'error' => 'invalid_request', 'description' => 'PKCE S256 é obrigatório.', 'redirect' => true, 'params' => $params];
        }

        if ($resource !== '' && $resource !== $this->resourceUrl()) {
            return ['ok' => false, 'error' => 'invalid_target', 'description' => 'resource não corresponde a este MCP.', 'redirect' => true, 'params' => $params];
        }

        if ($scope !== '' && $scope !== self::SCOPE) {
            $parts = preg_split('/\s+/', $scope) ?: [];
            if (! in_array(self::SCOPE, $parts, true)) {
                return ['ok' => false, 'error' => 'invalid_scope', 'description' => 'Escopo não suportado.', 'redirect' => true, 'params' => $params];
            }
        }

        return ['ok' => true, 'params' => $params];
    }

    public function sellerCanAuthorize(User $user): ?string
    {
        if (! $user->canAccessSellerPanel()) {
            return 'Entre com a conta de infoprodutor desta plataforma.';
        }

        $tenantId = (int) $user->tenant_id;
        if ($tenantId < 1) {
            return 'Esta conta não tem tenant MCP.';
        }

        if (! SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::MCP, $tenantId)) {
            return 'MCP não está habilitado para esta conta.';
        }

        $credential = McpCredential::query()->forTenant($tenantId)->active()->first();
        if ($credential === null) {
            return 'Ative o MCP no painel (Integrações) antes de autorizar o ChatGPT. O token do Claude não é substituído neste login.';
        }

        return null;
    }

    /**
     * @param  array<string, string>  $params
     */
    public function issueAuthorizationCode(int $tenantId, int $userId, array $params): string
    {
        $code = Str::random(48);
        Cache::put($this->codeCacheKey($code), [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'client_id' => $params['client_id'],
            'redirect_uri' => $params['redirect_uri'],
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            'resource' => $params['resource'] ?? '',
            'scope' => self::SCOPE,
        ], self::CODE_TTL_SECONDS);

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(
        string $clientId,
        string $code,
        string $redirectUri,
        string $codeVerifier,
        ?string $resource = null,
    ): array {
        $payload = Cache::pull($this->codeCacheKey($code));
        if (! is_array($payload)) {
            throw new McpOauthException('invalid_grant', 'Código de autorização inválido ou expirado.');
        }

        if (($payload['client_id'] ?? '') !== $clientId) {
            throw new McpOauthException('invalid_grant', 'client_id não corresponde ao código.');
        }

        if (($payload['redirect_uri'] ?? '') !== $redirectUri) {
            throw new McpOauthException('invalid_grant', 'redirect_uri não corresponde ao código.');
        }

        if (! $this->verifyPkce((string) ($payload['code_challenge'] ?? ''), $codeVerifier)) {
            throw new McpOauthException('invalid_grant', 'code_verifier inválido.');
        }

        $expectedResource = rtrim((string) ($payload['resource'] ?? ''), '/');
        $givenResource = is_string($resource) ? rtrim($resource, '/') : '';
        if ($expectedResource !== '' && $givenResource !== '' && $expectedResource !== $givenResource) {
            throw new McpOauthException('invalid_grant', 'resource não corresponde ao código.');
        }

        return $this->issueTokens((int) $payload['tenant_id'], $clientId);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $clientId, string $refreshPlain): array
    {
        $publicId = McpOauthAccessToken::parseRefreshPublicId($refreshPlain);
        if ($publicId === null) {
            throw new McpOauthException('invalid_grant', 'refresh_token inválido.');
        }

        $row = McpOauthAccessToken::query()
            ->where('refresh_public_id', $publicId)
            ->where('client_id', $clientId)
            ->first();

        if ($row === null || $row->isRefreshExpired() || ! $row->verifyRefresh($refreshPlain)) {
            throw new McpOauthException('invalid_grant', 'refresh_token inválido ou expirado.');
        }

        return $this->rotateTokens($row);
    }

    public function findActiveAccessToken(string $plain): ?McpOauthAccessToken
    {
        $publicId = McpOauthAccessToken::parseAccessPublicId($plain);
        if ($publicId === null) {
            return null;
        }

        $row = McpOauthAccessToken::query()->where('public_id', $publicId)->first();
        if ($row === null || $row->isAccessExpired() || ! $row->verifyAccess($plain)) {
            return null;
        }

        return $row;
    }

    public function revokeTenantTokens(int $tenantId): void
    {
        McpOauthAccessToken::query()->where('tenant_id', $tenantId)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function issueTokens(int $tenantId, string $clientId): array
    {
        $access = McpOauthAccessToken::issueAccessParts();
        $refresh = McpOauthAccessToken::issueRefreshParts();

        McpOauthAccessToken::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'public_id' => $access['public_id'],
            'token_hash' => McpOauthAccessToken::hashToken($access['plain']),
            'token_suffix' => $access['suffix'],
            'refresh_public_id' => $refresh['public_id'],
            'refresh_hash' => McpOauthAccessToken::hashToken($refresh['plain']),
            'expires_at' => now()->addHours(self::ACCESS_TTL_HOURS),
            'refresh_expires_at' => now()->addDays(self::REFRESH_TTL_DAYS),
        ]);

        return $this->tokenResponse($access['plain'], $refresh['plain']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rotateTokens(McpOauthAccessToken $row): array
    {
        $access = McpOauthAccessToken::issueAccessParts();
        $refresh = McpOauthAccessToken::issueRefreshParts();

        $row->forceFill([
            'public_id' => $access['public_id'],
            'token_hash' => McpOauthAccessToken::hashToken($access['plain']),
            'token_suffix' => $access['suffix'],
            'refresh_public_id' => $refresh['public_id'],
            'refresh_hash' => McpOauthAccessToken::hashToken($refresh['plain']),
            'expires_at' => now()->addHours(self::ACCESS_TTL_HOURS),
            'refresh_expires_at' => now()->addDays(self::REFRESH_TTL_DAYS),
            'last_used_at' => null,
        ])->save();

        return $this->tokenResponse($access['plain'], $refresh['plain']);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenResponse(string $access, string $refresh): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_HOURS * 3600,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    private function verifyPkce(string $challenge, string $verifier): bool
    {
        if (! $this->isValidCodeVerifier($verifier) || $challenge === '') {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }

    private function isValidCodeChallenge(string $challenge): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge);
    }

    private function isValidCodeVerifier(string $verifier): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43,128}$/', $verifier);
    }

    public function isSafeRedirectUri(string $uri): bool
    {
        if ($uri === '' || str_contains($uri, "\n") || str_contains($uri, "\r")) {
            return false;
        }

        $parts = parse_url($uri);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @return list<string>
     */
    private function normalizeRedirectUris(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $uris = [];
        foreach ($raw as $uri) {
            if (! is_string($uri)) {
                continue;
            }
            $uri = trim($uri);
            if ($uri === '' || ! $this->isSafeRedirectUri($uri)) {
                return [];
            }
            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    private function codeCacheKey(string $code): string
    {
        return 'mcp_oauth.code.'.hash('sha256', $code);
    }
}