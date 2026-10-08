<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformWhatsappCampaign;
use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappDispatch;
use App\Models\PlatformWhatsappTemplate;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Services\Platform\PlatformTotpService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UazapiController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Platform/Uazapi/Index', [
            'uazapi' => [
                'docs_url' => (string) config('uazapi.docs_url'),
                'instances' => $this->uazapiInstances(),
                'recent_dispatches' => $this->uazapiDispatches(),
            ],
            'evolution' => [
                'docs_url' => (string) config('evolution.docs_url'),
                'instances' => $this->evolutionInstances(),
                'recent_dispatches' => $this->evolutionDispatches(),
            ],
            'platform' => $this->platformPayload($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function platformPayload(Request $request): array
    {
        PlatformWhatsappTemplate::ensureDefaults();
        $channel = PlatformWhatsappChannel::current();

        return [
            'uazapi_docs_url' => (string) config('uazapi.docs_url'),
            'evolution_docs_url' => (string) config('evolution.docs_url'),
            'channel' => $channel->toPublicArray(),
            'templates' => PlatformWhatsappTemplate::toPublicList(),
            'template_variables' => ['{nome}', '{primeiro_nome}', '{email}', '{motivo}', '{motivo_linha}', '{painel}', '{kyc_url}', '{plataforma}', '{status}'],
            'recent_dispatches' => PlatformWhatsappDispatch::query()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (PlatformWhatsappDispatch $d) => $d->toPublicArray())
                ->values()
                ->all(),
            'recent_campaigns' => PlatformWhatsappCampaign::query()
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (PlatformWhatsappCampaign $c) => $c->toPublicArray())
                ->values()
                ->all(),
            'campaign_defaults' => [
                'delay_seconds' => (int) config('platform_whatsapp.campaign.delay_seconds', 10),
                'max_recipients' => (int) config('platform_whatsapp.campaign.max_recipients', 500),
            ],
            'platform_totp_enabled' => PlatformTotpService::isEnabledFor($request->user()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function uazapiInstances(): array
    {
        return UazapiInstance::query()
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (UazapiInstance $instance) => [
                'id' => $instance->id,
                'tenant_id' => $instance->tenant_id,
                'name' => $instance->displayName(),
                'status' => $instance->status,
                'phone' => $instance->phone,
                'profile_name' => $instance->profile_name,
                'is_active' => $instance->is_active,
                'is_default' => $instance->is_default,
                'cart_recovery_enabled' => $instance->cart_recovery_enabled,
                'pix_recovery_enabled' => $instance->pix_recovery_enabled,
                'order_paid_enabled' => $instance->order_paid_enabled,
                'connected_at' => $instance->connected_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function uazapiDispatches(): array
    {
        return UazapiMessageDispatch::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (UazapiMessageDispatch $d) => $this->dispatchRow($d))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evolutionInstances(): array
    {
        return EvolutionInstance::query()
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (EvolutionInstance $instance) => [
                'id' => $instance->id,
                'tenant_id' => $instance->tenant_id,
                'name' => $instance->displayName(),
                'instance_name' => $instance->instance_name,
                'status' => $instance->status,
                'phone' => $instance->phone,
                'profile_name' => $instance->profile_name,
                'is_active' => $instance->is_active,
                'is_default' => $instance->is_default,
                'cart_recovery_enabled' => $instance->cart_recovery_enabled,
                'pix_recovery_enabled' => $instance->pix_recovery_enabled,
                'order_paid_enabled' => $instance->order_paid_enabled,
                'connected_at' => $instance->connected_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evolutionDispatches(): array
    {
        return EvolutionMessageDispatch::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (EvolutionMessageDispatch $d) => $this->dispatchRow($d))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchRow(UazapiMessageDispatch|EvolutionMessageDispatch $d): array
    {
        return [
            'id' => $d->id,
            'tenant_id' => $d->tenant_id,
            'event_type' => $d->event_type,
            'sequence_step' => $d->sequence_step,
            'phone' => $d->phone,
            'status' => $d->status,
            'wa_status' => $d->wa_status,
            'error' => $d->error,
            'sent_at' => $d->sent_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
