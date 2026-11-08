<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\CademiIntegration;
use App\Models\EvolutionInstance;
use App\Models\McpCredential;
use App\Models\Product;
use App\Models\SpedyIntegration;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\UazapiInstance;
use App\Models\UtmifyIntegration;
use App\Models\Webhook;
use App\Plugins\PluginRegistry;
use App\Services\Mcp\McpCredentialService;
use App\Services\SellerActivityLogService;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    use LogsSellerActivity;

    public function index(): Response
    {
        // Infoprodutor dono pode ter tenant_id null; o tenant efetivo é o próprio id.
        $user = auth()->user();
        $tenantId = (int) ($user?->tenant_id ?? $user?->id ?? 0);
        $tenantId = $tenantId > 0 ? $tenantId : null;

        $webhooks = Webhook::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Webhook $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'url' => $w->url,
                'has_bearer_token' => (bool) $w->bearer_token,
                'events' => $w->events ?? [],
                'is_active' => $w->is_active,
                'products' => $w->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $webhookEvents = config('webhook_events.events', []);

        $utmifyIntegrations = UtmifyIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (UtmifyIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'is_active' => $i->is_active,
                'configured' => $i->api_key !== null && $i->api_key !== '',
                'api_key' => $i->api_key ?? '',
                'product_ids' => $i->products->pluck('id')->values()->all(),
                'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $spedyIntegrations = SpedyIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (SpedyIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'is_active' => $i->is_active,
                'configured' => $i->api_key !== null && $i->api_key !== '',
                'api_key' => $i->api_key ?? '',
                'environment' => $i->environment ?? SpedyIntegration::ENVIRONMENT_PRODUCTION,
                'product_ids' => $i->products->pluck('id')->values()->all(),
                'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $cademiIntegrations = CademiIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (CademiIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'base_url' => $i->base_url,
                'is_active' => $i->is_active,
                'configured' => $i->api_key !== null && $i->api_key !== '',
                'api_key' => $i->api_key ?? '',
                'product_ids' => $i->products->pluck('id')->values()->all(),
                'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);
        $visibleIds = SellerIntegrationVisibility::visibleIdsForTenant($tenantId !== null ? (int) $tenantId : null);

        $uazapi = null;
        if (in_array(SellerIntegrationVisibility::UAZAPI, $visibleIds, true) && $tenantId !== null) {
            $instances = UazapiInstance::query()->where('tenant_id', (int) $tenantId)->get();
            $uazapi = [
                'configured' => $instances->contains(fn (UazapiInstance $instance) => $instance->hasCredentials()),
                'connected' => $instances->contains(fn (UazapiInstance $instance) => $instance->isConnected()),
                'is_active' => $instances->contains(fn (UazapiInstance $instance) => $instance->is_active && $instance->hasCredentials()),
                'accounts' => $instances->count(),
            ];
        }

        $evolution = null;
        if (in_array(SellerIntegrationVisibility::EVOLUTION, $visibleIds, true) && $tenantId !== null) {
            $instances = EvolutionInstance::query()->where('tenant_id', (int) $tenantId)->get();
            $evolution = [
                'configured' => $instances->contains(fn (EvolutionInstance $instance) => $instance->hasCredentials()),
                'connected' => $instances->contains(fn (EvolutionInstance $instance) => $instance->isConnected()),
                'is_active' => $instances->contains(fn (EvolutionInstance $instance) => $instance->is_active && $instance->hasCredentials()),
                'accounts' => $instances->count(),
            ];
        }

        $telegram = null;
        if (in_array(SellerIntegrationVisibility::TELEGRAM, $visibleIds, true) && $tenantId !== null) {
            $bots = TelegramBot::query()->forTenant((int) $tenantId)->active()->get();
            $communities = TelegramCommunity::query()->forTenant((int) $tenantId)->active()->get();
            $telegram = [
                'configured' => $bots->isNotEmpty(),
                'connected' => $bots->contains(fn (TelegramBot $bot) => $bot->isConnected()),
                'is_active' => $bots->contains(fn (TelegramBot $bot) => $bot->isConnected()),
                'bots' => $bots->count(),
                'communities' => $communities->count(),
                'ready_communities' => $communities->where('ready_for_delivery', true)->count(),
            ];
        }

        return Inertia::render('Integrations/Index', [
            'webhooks' => in_array(SellerIntegrationVisibility::WEBHOOK, $visibleIds, true) ? $webhooks : [],
            'webhook_events' => $webhookEvents,
            'utmify_integrations' => in_array(SellerIntegrationVisibility::UTMIFY, $visibleIds, true) ? $utmifyIntegrations : [],
            'spedy_integrations' => in_array(SellerIntegrationVisibility::SPEDY, $visibleIds, true) ? $spedyIntegrations : [],
            'cademi_integrations' => in_array(SellerIntegrationVisibility::CADEMI, $visibleIds, true) ? $cademiIntegrations : [],
            'uazapi' => $uazapi,
            'evolution' => $evolution,
            'telegram' => $telegram,
            'mcp' => $this->mcpPayload($visibleIds, $tenantId !== null ? (int) $tenantId : null),
            'products' => $products,
            'visible_integrations' => $visibleIds,
        ]);
    }

    /**
     * @param  list<string>  $visibleIds
     * @return array<string, mixed>|null
     */
    private function mcpPayload(array $visibleIds, ?int $tenantId): ?array
    {
        if (! in_array(SellerIntegrationVisibility::MCP, $visibleIds, true) || $tenantId === null) {
            return null;
        }

        $credential = McpCredential::query()->forTenant($tenantId)->first();

        return app(McpCredentialService::class)->toPublicArray($credential, url('/mcp'));
    }

    public function enablePlugin(string $slug): RedirectResponse
    {
        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        if (! $installed->has($slug)) {
            return back()->with('error', 'Plugin não encontrado.');
        }
        PluginRegistry::enable($slug);
        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_PLUGIN_ENABLED, null, [
            'name' => $slug,
        ]);

        return back()->with('success', 'Plugin ativado.');
    }

    public function disablePlugin(string $slug): RedirectResponse
    {
        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        if (! $installed->has($slug)) {
            return back()->with('error', 'Plugin não encontrado.');
        }
        PluginRegistry::disable($slug);
        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_PLUGIN_DISABLED, null, [
            'name' => $slug,
        ]);

        return back()->with('success', 'Plugin desativado.');
    }

    public function uninstallPlugin(string $slug): RedirectResponse
    {
        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        $plugin = $installed->get($slug);
        if (! $plugin) {
            return back()->with('error', 'Plugin não encontrado.');
        }
        $pluginPath = $plugin['path'] ?? null;
        if (! PluginRegistry::uninstall($slug, $pluginPath)) {
            return back()->with('error', 'Não foi possível excluir o plugin. Verifique permissões da pasta plugins.');
        }
        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_PLUGIN_UNINSTALLED, null, [
            'name' => $slug,
        ]);

        return back()->with('success', 'Plugin excluído.');
    }
}
