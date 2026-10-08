<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\Product;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Services\SellerActivityLogService;
use App\Services\Uazapi\UazapiAccountResolver;
use App\Services\Uazapi\UazapiCampaignService;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiInstanceService;
use App\Support\UazapiCartRecoverySteps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UazapiIntegrationController extends Controller
{
    use LogsSellerActivity;

    public function show(UazapiInstanceService $instanceService, UazapiAccountResolver $resolver): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;

        return response()->json($this->payload($instanceService, $resolver, $tenantId));
    }

    public function store(UazapiInstanceService $instanceService, UazapiAccountResolver $resolver): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $template = UazapiInstance::forTenant($tenantId);
        $instance = UazapiInstance::newForTenant($tenantId);
        $instance->save();
        if ($template) {
            $instance->products()->sync($template->linkedProductIds());
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_UAZAPI_UPDATED, $instance, [
            'name' => $instance->displayName(),
            'created' => true,
        ]);

        return response()->json($this->payload($instanceService, $resolver, $tenantId, $instance));
    }

    public function connect(
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        ?UazapiInstance $instance = null
    ): JsonResponse {
        $tenantId = (int) auth()->user()->tenant_id;
        $instance = $this->ownedInstance($tenantId, $instance) ?? UazapiInstance::forTenant($tenantId);

        if (! $instance || ! $instance->hasCredentials()) {
            return response()->json([
                'message' => 'Informe a Server URL e o token da sua instância uazapi antes de conectar.',
            ], 422);
        }

        try {
            $instance = $instanceService->connect($instance);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_UAZAPI_CONNECTED, $instance, [
            'name' => $instance->displayName(),
            'status' => $instance->status,
        ]);

        return response()->json($this->payload($instanceService, $resolver, $tenantId, $instance));
    }

    public function status(
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        ?UazapiInstance $instance = null
    ): JsonResponse {
        $tenantId = (int) auth()->user()->tenant_id;
        $instance = $this->ownedInstance($tenantId, $instance) ?? UazapiInstance::forTenant($tenantId);

        if ($instance && is_string($instance->instance_token) && $instance->instance_token !== '') {
            $instance = $instanceService->refreshStatus($instance);
        }

        return response()->json($this->payload($instanceService, $resolver, $tenantId, $instance));
    }

    public function disconnect(
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        ?UazapiInstance $instance = null
    ): JsonResponse {
        $tenantId = (int) auth()->user()->tenant_id;
        $instance = $this->ownedInstance($tenantId, $instance) ?? UazapiInstance::forTenant($tenantId);
        if (! $instance) {
            return response()->json(['message' => 'Nenhuma instância conectada.'], 422);
        }

        $instance = $instanceService->disconnect($instance);

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_UAZAPI_DISCONNECTED, $instance, [
            'name' => $instance->displayName(),
        ]);

        return response()->json($this->payload($instanceService, $resolver, $tenantId, $instance));
    }

    public function update(
        Request $request,
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        ?UazapiInstance $instance = null
    ): JsonResponse {
        $tenantId = (int) auth()->user()->tenant_id;
        $instance = $this->ownedInstance($tenantId, $instance);
        if (! $instance) {
            $instance = UazapiInstance::firstOrNewForTenant($tenantId);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'server_url' => ['nullable', 'url', 'max:255'],
            'instance_token' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['required', 'exists:products,id'],
            'cart_recovery_enabled' => ['nullable', 'boolean'],
            'pix_recovery_enabled' => ['nullable', 'boolean'],
            'order_paid_enabled' => ['nullable', 'boolean'],
            'send_product_image' => ['nullable', 'boolean'],
            'message_pix' => ['nullable', 'string', 'max:'.(int) config('uazapi.max_message_length', 1000)],
            'message_order_paid' => ['nullable', 'string', 'max:'.(int) config('uazapi.max_message_length', 1000)],
            'cart_recovery_steps' => ['nullable', 'array', 'max:10'],
            'cart_recovery_steps.*.delay_value' => ['required_with:cart_recovery_steps', 'integer', 'min:1', 'max:9999'],
            'cart_recovery_steps.*.delay_unit' => ['required_with:cart_recovery_steps', 'string', 'in:minutes,hours,days'],
            'cart_recovery_steps.*.message' => ['required_with:cart_recovery_steps', 'string', 'max:'.(int) config('uazapi.max_message_length', 1000)],
            'pix_recovery_steps' => ['nullable', 'array', 'max:10'],
            'pix_recovery_steps.*.delay_value' => ['required_with:pix_recovery_steps', 'integer', 'min:1', 'max:9999'],
            'pix_recovery_steps.*.delay_unit' => ['required_with:pix_recovery_steps', 'string', 'in:minutes,hours,days'],
            'pix_recovery_steps.*.message' => ['required_with:pix_recovery_steps', 'string', 'max:'.(int) config('uazapi.max_message_length', 1000)],
        ]);

        try {
            $steps = UazapiCartRecoverySteps::fromUiInput(
                is_array($validated['cart_recovery_steps'] ?? null) ? $validated['cart_recovery_steps'] : []
            );
            $pixSteps = UazapiCartRecoverySteps::fromUiInput(
                is_array($validated['pix_recovery_steps'] ?? null) ? $validated['pix_recovery_steps'] : []
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'cart_recovery_steps' => $e->getMessage(),
            ]);
        }

        if ($request->boolean('cart_recovery_enabled') && $steps === []) {
            throw ValidationException::withMessages([
                'cart_recovery_steps' => 'Adicione ao menos uma mensagem de recuperação de carrinho.',
            ]);
        }

        $name = trim((string) ($validated['name'] ?? ''));
        if ($name !== '') {
            $instance->name = $name;
        } elseif (! is_string($instance->name) || trim($instance->name) === '') {
            $instance->name = $instance->is_default ? 'Conta principal' : 'Nova conta';
        }

        $instance->is_active = $request->boolean('is_active', true);
        $instance->cart_recovery_enabled = $request->boolean('cart_recovery_enabled');
        $instance->pix_recovery_enabled = $request->boolean('pix_recovery_enabled');
        $instance->order_paid_enabled = $request->boolean('order_paid_enabled');
        $instance->send_product_image = $request->boolean('send_product_image', true);
        $instance->message_pix = $validated['message_pix'] ?? $instance->message_pix;
        $instance->message_order_paid = $validated['message_order_paid'] ?? $instance->message_order_paid;
        $instance->cart_recovery_steps = $steps !== [] ? $steps : UazapiCartRecoverySteps::defaults();
        $instance->pix_recovery_steps = $pixSteps;
        $instance->save();

        if (array_key_exists('product_ids', $validated)) {
            $this->ensureProductIdsBelongToTenant($tenantId, $validated['product_ids'] ?? []);
            $instance->products()->sync(array_map(strval(...), $validated['product_ids'] ?? []));
            $instance->unsetRelation('products');
        }

        if ($request->boolean('is_default')) {
            $resolver->makeDefault($instance);
        }

        $serverUrl = trim((string) ($validated['server_url'] ?? ''));
        $token = trim((string) ($validated['instance_token'] ?? ''));
        $hasStoredToken = is_string($instance->instance_token) && trim($instance->instance_token) !== '';
        if ($token !== '' || ($serverUrl !== '' && $hasStoredToken)) {
            try {
                $instance = $instanceService->saveCredentials(
                    $instance,
                    $serverUrl !== '' ? $serverUrl : (string) ($instance->server_url ?? ''),
                    $token !== '' ? $token : null
                );
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    ...$this->payload($instanceService, $resolver, $tenantId, $instance->fresh() ?? $instance),
                ], 422);
            }
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_UAZAPI_UPDATED, $instance, [
            'name' => $instance->displayName(),
            'cart_recovery_enabled' => $instance->cart_recovery_enabled,
            'pix_recovery_enabled' => $instance->pix_recovery_enabled,
            'order_paid_enabled' => $instance->order_paid_enabled,
        ]);

        return response()->json($this->payload($instanceService, $resolver, $tenantId, $instance));
    }

    public function destroy(
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        UazapiInstance $instance
    ): JsonResponse {
        $tenantId = (int) auth()->user()->tenant_id;
        $instance = $this->ownedInstance($tenantId, $instance);
        if (! $instance) {
            abort(404);
        }

        $wasDefault = (bool) $instance->is_default;
        if ($instance->hasCredentials()) {
            try {
                $instanceService->disconnect($instance);
            } catch (\Throwable) {
                // exclusão segue mesmo se a API remota falhar
            }
        }

        $instance->delete();

        if ($wasDefault) {
            $next = UazapiInstance::forTenant($tenantId);
            if ($next) {
                $resolver->makeDefault($next);
            }
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_UAZAPI_DISCONNECTED, null, [
            'name' => 'WhatsApp',
            'deleted' => true,
        ]);

        return response()->json($this->payload($instanceService, $resolver, $tenantId));
    }

    public function setDefault(
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        UazapiInstance $instance
    ): JsonResponse {
        $tenantId = (int) auth()->user()->tenant_id;
        $instance = $this->ownedInstance($tenantId, $instance);
        if (! $instance) {
            abort(404);
        }

        $resolver->makeDefault($instance);

        return response()->json($this->payload($instanceService, $resolver, $tenantId, $instance));
    }

    public function test(Request $request, UazapiClient $client, UazapiAccountResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:'.(int) config('uazapi.max_message_length', 1000)],
            'instance_id' => ['nullable', 'integer'],
        ]);

        $tenantId = (int) auth()->user()->tenant_id;
        $instance = null;
        if (! empty($validated['instance_id'])) {
            $instance = UazapiInstance::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $validated['instance_id'])
                ->first();
        }
        $instance ??= $resolver->resolveForTenant($tenantId);
        if (! $instance || ! $instance->canSendRecovery()) {
            return response()->json([
                'success' => false,
                'message' => 'Conecte o WhatsApp e mantenha a conta ativa para testar.',
            ], 422);
        }

        $phone = $client->normalizePhone($validated['phone']);
        if ($phone === null) {
            return response()->json([
                'success' => false,
                'message' => 'Telefone inválido.',
            ], 422);
        }

        $message = trim((string) ($validated['message'] ?? ''));
        if ($message === '') {
            $message = 'Teste Stacker — recuperação de vendas via WhatsApp.';
        }

        try {
            $client->using($instance)->sendText((string) $instance->instance_token, [
                'number' => $phone,
                'text' => $message,
                'track_source' => 'stacker-recovery',
                'track_id' => 'uazapi-test-'.$instance->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    public function storeCampaign(Request $request, UazapiCampaignService $campaigns, UazapiAccountResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:abandoned_cart,pending_pix,buyers'],
            'message' => ['required', 'string', 'max:'.(int) config('uazapi.max_message_length', 1000)],
            'include_image' => ['nullable', 'boolean'],
            'instance_id' => ['nullable', 'integer'],
        ]);

        $tenantId = (int) auth()->user()->tenant_id;
        $instance = null;
        if (! empty($validated['instance_id'])) {
            $instance = UazapiInstance::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $validated['instance_id'])
                ->first();
        }
        $instance ??= $resolver->resolveForTenant($tenantId);
        if (! $instance) {
            return response()->json(['message' => 'Conecte o WhatsApp antes de disparar.'], 422);
        }

        try {
            $campaign = $campaigns->launch(
                $instance,
                $validated['audience'],
                $validated['message'],
                $request->boolean('include_image', true)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_UAZAPI_CAMPAIGN, $campaign, [
            'name' => $instance->displayName(),
            'audience' => $campaign->audience,
            'queued_count' => $campaign->queued_count,
        ]);

        return response()->json([
            'campaign' => $campaign->toPublicArray(),
        ]);
    }

    private function ownedInstance(int $tenantId, ?UazapiInstance $instance): ?UazapiInstance
    {
        if (! $instance) {
            return null;
        }

        abort_unless((int) $instance->tenant_id === $tenantId, 404);

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        UazapiInstanceService $instanceService,
        UazapiAccountResolver $resolver,
        int $tenantId,
        ?UazapiInstance $instance = null
    ): array {
        $accounts = $resolver->allForTenant($tenantId);
        $instance ??= $accounts->first(fn (UazapiInstance $row) => $row->is_default) ?? $accounts->first();

        if ($instance && $instance->hasCredentials()) {
            try {
                $instanceService->ensureWebhook($instance);
            } catch (\Throwable) {
                // sidebar ainda carrega mesmo se o webhook remoto falhar
            }
        }

        $recent = [];
        $recentQuery = UazapiMessageDispatch::query()->where('tenant_id', $tenantId);
        if ($instance?->id) {
            $recentQuery->where('uazapi_instance_id', $instance->id);
        }
        $recent = $recentQuery
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (UazapiMessageDispatch $d) => [
                'id' => $d->id,
                'event_type' => $d->event_type,
                'status' => $d->status,
                'wa_status' => $d->wa_status,
                'phone' => $d->phone,
                'error' => $d->error,
                'sent_at' => $d->sent_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'signup_url' => (string) config('uazapi.signup_url', 'https://uazapi.dev/'),
            'products' => Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name'])->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values()->all(),
            'accounts' => $accounts->map(fn (UazapiInstance $row) => $row->toSummaryArray())->values()->all(),
            'credentials_configured' => $instance?->hasCredentials() ?? false,
            'instance' => $instance?->toPublicArray() ?? $this->emptyInstance(),
            'recent_dispatches' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyInstance(): array
    {
        return [
            'id' => null,
            'name' => 'Conta principal',
            'is_default' => true,
            'status' => 'disconnected',
            'phone' => null,
            'profile_name' => null,
            'qrcode' => null,
            'paircode' => null,
            'server_url' => '',
            'has_token' => false,
            'has_credentials' => false,
            'is_active' => true,
            'product_ids' => [],
            'cart_recovery_enabled' => false,
            'pix_recovery_enabled' => false,
            'order_paid_enabled' => false,
            'send_product_image' => true,
            'cart_recovery_steps' => UazapiCartRecoverySteps::toUiSteps(null),
            'pix_recovery_steps' => UazapiCartRecoverySteps::toUiPixSteps(null),
            'message_pix' => (string) (config('uazapi.defaults.messages.pix_generated') ?? ''),
            'message_order_paid' => (string) (config('uazapi.defaults.messages.order_paid') ?? ''),
            'last_error' => null,
            'connected_at' => null,
            'connected' => false,
            'has_instance' => false,
        ];
    }

    /**
     * @param  array<int, string|int>  $productIds
     */
    private function ensureProductIdsBelongToTenant(int $tenantId, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $ids = array_map(strval(...), $productIds);
        $count = Product::forTenant($tenantId)->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages([
                'product_ids' => 'Selecione apenas produtos da sua conta.',
            ]);
        }
    }
}
