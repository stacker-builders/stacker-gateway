<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\McpCredential;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpOauthService;
use App\Services\SellerActivityLogService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class McpIntegrationController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        private readonly McpCredentialService $credentials,
        private readonly McpOauthService $oauth,
    ) {}

    public function store(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $existing = McpCredential::query()->forTenant($tenantId)->first();
        $issued = $this->credentials->activate($tenantId);

        $this->logSellerActivity(
            $existing === null
                ? SellerActivityLogService::INTEGRATION_MCP_ACTIVATED
                : SellerActivityLogService::INTEGRATION_MCP_ROTATED,
            $issued['credential'],
            ['name' => 'MCP']
        );

        return response()->json([
            'mcp' => $this->credentials->toPublicArray($issued['credential'], $this->serverUrl()),
            'token' => $issued['token'],
            'server_url' => $this->serverUrl(),
        ], $existing === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function rotate(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $credential = McpCredential::query()->forTenant($tenantId)->first();
        if ($credential === null) {
            return response()->json(['message' => 'Ative o MCP antes de rotacionar o token.'], Response::HTTP_NOT_FOUND);
        }

        $issued = $this->credentials->rotate($credential);
        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_MCP_ROTATED, $issued['credential'], [
            'name' => 'MCP',
        ]);

        return response()->json([
            'mcp' => $this->credentials->toPublicArray($issued['credential'], $this->serverUrl()),
            'token' => $issued['token'],
            'server_url' => $this->serverUrl(),
        ]);
    }

    public function destroy(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $credential = McpCredential::query()->forTenant($tenantId)->first();
        if ($credential === null) {
            return response()->json(['message' => 'MCP não está ativo.'], Response::HTTP_NOT_FOUND);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_MCP_REVOKED, $credential, [
            'name' => 'MCP',
        ]);
        $this->oauth->revokeTenantTokens($tenantId);
        $credential->delete();

        return response()->json([
            'mcp' => $this->credentials->toPublicArray(null, $this->serverUrl()),
        ]);
    }

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function serverUrl(): string
    {
        return url('/mcp');
    }
}
