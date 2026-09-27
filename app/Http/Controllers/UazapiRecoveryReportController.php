<?php

namespace App\Http\Controllers;

use App\Models\EvolutionInstance;
use App\Models\UazapiCampaign;
use App\Models\UazapiInstance;
use App\Services\SellerIntegrationVisibility;
use App\Services\Uazapi\UazapiCampaignAudience;
use App\Services\Uazapi\UazapiRecoveryInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UazapiRecoveryReportController extends Controller
{
    public function index(Request $request, UazapiRecoveryInsights $insights, UazapiCampaignAudience $audiences): Response
    {
        $period = $request->query('period', '7dias');
        if (! in_array($period, ['7dias', '30dias'], true)) {
            $period = '7dias';
        }

        $tenantId = (int) auth()->user()->tenant_id;
        $days = $period === '30dias' ? 30 : 7;

        $uazapiAvailable = SellerIntegrationVisibility::effectiveForTenant(
            SellerIntegrationVisibility::UAZAPI,
            $tenantId
        );

        $uazapiInstances = $uazapiAvailable
            ? UazapiInstance::query()
                ->where('tenant_id', $tenantId)
                ->with('products')
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
            : collect();

        $uazapiInstance = $uazapiInstances->first(fn (UazapiInstance $row) => $row->isConnected())
            ?? $uazapiInstances->first();

        $evolutionInstance = EvolutionInstance::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (EvolutionInstance $row) => $row->isConnected())
            ?? EvolutionInstance::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

        $displayInstance = $uazapiInstance?->toPublicArray(false)
            ?? $evolutionInstance?->toPublicArray(false);

        $campaignsAvailable = $uazapiAvailable && $uazapiInstance !== null;
        $campaigns = [];
        $audienceCounts = [
            'abandoned_cart' => 0,
            'pending_pix' => 0,
            'buyers' => 0,
        ];

        if ($campaignsAvailable) {
            $campaigns = UazapiCampaign::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (UazapiCampaign $campaign) => $campaign->toPublicArray())
                ->values()
                ->all();
            $audienceCounts = $audiences->counts($tenantId, $uazapiInstance);
        }

        return Inertia::render('Relatorios/Whatsapp', [
            'period' => $period,
            'instance' => $displayInstance,
            'metrics' => $insights->forTenant($tenantId, $days),
            'recent' => $insights->recentDispatches($tenantId),
            'audience_counts' => $audienceCounts,
            'campaigns' => $campaigns,
            'campaign_defaults' => config('uazapi.campaign.defaults', []),
            'campaigns_available' => $campaignsAvailable,
        ]);
    }
}
