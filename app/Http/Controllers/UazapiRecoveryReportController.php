<?php

namespace App\Http\Controllers;

use App\Jobs\EvolutionSendMessageJob;
use App\Jobs\UazapiSendMessageJob;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\UazapiCampaign;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Services\SellerIntegrationVisibility;
use App\Services\Uazapi\UazapiCampaignAudience;
use App\Services\Uazapi\UazapiRecoveryInsights;
use Illuminate\Http\JsonResponse;
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

    public function resendFailed(string $provider, int $dispatch, UazapiRecoveryInsights $insights): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $provider = strtolower(trim($provider));

        if (! in_array($provider, ['evolution', 'uazapi'], true)) {
            return response()->json(['message' => 'Provedor inválido.'], 422);
        }

        if ($provider === 'evolution') {
            $row = EvolutionMessageDispatch::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $dispatch)
                ->firstOrFail();

            if (! $insights->canResendFailed('evolution', (string) $row->event_type, (string) $row->status)) {
                return response()->json([
                    'message' => 'Só é possível reenviar recuperações com status falhou.',
                ], 422);
            }

            $row->update([
                'status' => EvolutionMessageDispatch::STATUS_PENDING,
                'error' => null,
                'wa_status' => null,
                'provider_message_id' => null,
                'sent_at' => null,
            ]);

            EvolutionSendMessageJob::dispatch($row->id);

            return response()->json([
                'ok' => true,
                'message' => 'Reenvio enfileirado.',
                'dispatch' => [
                    'id' => 'evolution-'.$row->id,
                    'status' => EvolutionMessageDispatch::STATUS_PENDING,
                ],
            ]);
        }

        $row = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $dispatch)
            ->firstOrFail();

        if (! $insights->canResendFailed('uazapi', (string) $row->event_type, (string) $row->status)) {
            return response()->json([
                'message' => 'Só é possível reenviar recuperações com status falhou.',
            ], 422);
        }

        $row->update([
            'status' => UazapiMessageDispatch::STATUS_PENDING,
            'error' => null,
            'wa_status' => null,
            'provider_message_id' => null,
            'sent_at' => null,
        ]);

        UazapiSendMessageJob::dispatch($row->id);

        return response()->json([
            'ok' => true,
            'message' => 'Reenvio enfileirado.',
            'dispatch' => [
                'id' => 'uazapi-'.$row->id,
                'status' => UazapiMessageDispatch::STATUS_PENDING,
            ],
        ]);
    }
}
