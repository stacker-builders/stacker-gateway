<?php

namespace App\Http\Middleware;

use App\Services\SellerIntegrationVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerIntegrationEnabled
{
    public function handle(Request $request, Closure $next, string ...$integrationIds): Response
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id !== null ? (int) $user->tenant_id : null;

        foreach ($integrationIds as $integrationId) {
            if (SellerIntegrationVisibility::isKnown($integrationId)
                && SellerIntegrationVisibility::effectiveForTenant($integrationId, $tenantId)) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN, 'Esta integração não está disponível.');
    }
}
