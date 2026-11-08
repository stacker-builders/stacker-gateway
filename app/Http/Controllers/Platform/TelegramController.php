<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramCommercialReadiness;
use App\Services\Telegram\TelegramLifecycleMetricsService;
use App\Services\Telegram\TelegramModuleSettings;
use App\Services\Telegram\TelegramPolicy;
use App\Services\Telegram\TelegramSellerPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TelegramController extends Controller
{
    public function __construct(private TelegramPolicy $policy) {}

    public function index(Request $request): Response
    {
        TelegramModuleSettings::ensureStored();

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) > 100) {
            $term = mb_substr($term, 0, 100);
        }

        return Inertia::render('Platform/Telegram/Index', [
            'settings' => $this->policy->configuration(),
            'filters' => ['q' => $term],
            'sellers' => $term === '' ? [] : $this->searchSellers($term),
            'monitoring' => app(TelegramLifecycleMetricsService::class)->platform(),
            'commercial_readiness' => app(TelegramCommercialReadiness::class)->checklist(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->mergeBlankLimits($request);

        $validated = $request->validate([
            'module_enabled' => ['required', 'boolean'],
            'commercial_model' => ['required', Rule::in([TelegramModuleSettings::COMMERCIAL_FREE])],
            'bot_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'community_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'campaigns_enabled' => ['required', 'boolean'],
            'auto_delivery_enabled' => ['required', 'boolean'],
            'expiry_control_enabled' => ['required', 'boolean'],
            'expiry_removal_enabled' => ['required', 'boolean'],
            'sales_enabled' => ['required', 'boolean'],
            'auto_compensation_enabled' => ['required', 'boolean'],
        ]);

        $before = $this->policy->configuration();
        $saved = TelegramModuleSettings::save([
            'commercial_model' => TelegramModuleSettings::COMMERCIAL_FREE,
            'bot_limit' => $validated['bot_limit'],
            'community_limit' => $validated['community_limit'],
            'campaigns_enabled' => $request->boolean('campaigns_enabled'),
            'auto_delivery_enabled' => $request->boolean('auto_delivery_enabled'),
            'expiry_control_enabled' => $request->boolean('expiry_control_enabled'),
            'expiry_removal_enabled' => $request->boolean('expiry_removal_enabled'),
            'sales_enabled' => $request->boolean('sales_enabled'),
            'auto_compensation_enabled' => $request->boolean('auto_compensation_enabled'),
        ]);

        $moduleEnabled = $request->boolean('module_enabled');
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::TELEGRAM, $moduleEnabled);

        $after = array_merge($saved, ['module_enabled' => $moduleEnabled]);
        if ($before != $after) {
            PlatformAuditService::log('telegram.settings.updated', [
                'from' => $before,
                'to' => $after,
            ], $request);
        }

        return redirect()
            ->route('plataforma.telegram.index')
            ->with('success', 'Configuração do Telegram salva.');
    }

    public function updateSeller(Request $request, User $user): RedirectResponse
    {
        if (! $user->isInfoprodutor()) {
            throw ValidationException::withMessages([
                'seller' => 'A política do Telegram vale para a conta do infoprodutor.',
            ]);
        }

        $validated = $request->validate([
            'visibility' => ['required', Rule::in([
                SellerIntegrationVisibility::MODE_INHERIT,
                SellerIntegrationVisibility::MODE_ENABLED,
                SellerIntegrationVisibility::MODE_DISABLED,
            ])],
            'campaigns' => ['required', Rule::in([
                SellerIntegrationVisibility::MODE_INHERIT,
                SellerIntegrationVisibility::MODE_ENABLED,
                SellerIntegrationVisibility::MODE_DISABLED,
            ])],
            'sales' => ['required', Rule::in([
                SellerIntegrationVisibility::MODE_INHERIT,
                SellerIntegrationVisibility::MODE_ENABLED,
                SellerIntegrationVisibility::MODE_DISABLED,
            ])],
        ]);

        $tenantId = (int) ($user->tenant_id ?? $user->id);
        if ($tenantId <= 0) {
            throw ValidationException::withMessages([
                'seller' => 'Infoprodutor sem tenant para receber a política.',
            ]);
        }

        $before = [
            'visibility' => SellerIntegrationVisibility::tenantMode(SellerIntegrationVisibility::TELEGRAM, $tenantId),
            'campaigns' => TelegramSellerPolicy::forTenant($tenantId)['campaigns'],
            'sales' => TelegramSellerPolicy::forTenant($tenantId)['sales'],
        ];

        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::TELEGRAM,
            $tenantId,
            $validated['visibility']
        );
        $saved = TelegramSellerPolicy::save($tenantId, $validated['campaigns'], $validated['sales']);

        $after = [
            'visibility' => $validated['visibility'],
            'campaigns' => $saved['campaigns'],
            'sales' => $saved['sales'],
        ];

        if ($before != $after) {
            PlatformAuditService::log('telegram.seller_policy.updated', [
                'merchant_user_id' => $user->id,
                'tenant_id' => $tenantId,
                'from' => $before,
                'to' => $after,
            ], $request);
        }

        return redirect()
            ->back()
            ->with('success', 'Política do infoprodutor atualizada.');
    }

    private function mergeBlankLimits(Request $request): void
    {
        foreach (['bot_limit', 'community_limit'] as $field) {
            $value = $request->input($field);
            if ($value === '' || $value === false) {
                $request->merge([$field => null]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchSellers(string $term): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(function (User $user) {
                $tenantId = (int) ($user->tenant_id ?? $user->id);
                $modes = $tenantId > 0
                    ? TelegramSellerPolicy::forTenant($tenantId)
                    : TelegramSellerPolicy::defaults();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'tenant_id' => $tenantId > 0 ? $tenantId : null,
                    'visibility' => $tenantId > 0
                        ? SellerIntegrationVisibility::tenantMode(SellerIntegrationVisibility::TELEGRAM, $tenantId)
                        : SellerIntegrationVisibility::MODE_INHERIT,
                    'campaigns' => $modes['campaigns'],
                    'sales' => $modes['sales'],
                ];
            })
            ->values()
            ->all();
    }
}
