<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Services\SellerActivityLogService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramCommunityService;
use App\Services\Telegram\TelegramLifecycleMetricsService;
use App\Services\Telegram\TelegramPolicy;
use App\Services\Telegram\TelegramWizardProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TelegramIntegrationController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        private TelegramPolicy $policy,
        private TelegramBotService $bots,
        private TelegramCommunityService $communities,
        private TelegramWizardProgress $wizard,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = $this->tenantId();
        $bots = TelegramBot::query()
            ->forTenant($tenantId)
            ->active()
            ->with(['communities' => fn ($q) => $q->active()])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TelegramBot $bot) => $bot->toPublicArray(true))
            ->values()
            ->all();

        $communities = TelegramCommunity::query()
            ->forTenant($tenantId)
            ->active()
            ->with('bot')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TelegramCommunity $community) => $community->toPublicArray())
            ->values()
            ->all();

        $discoveredCommunities = TelegramCommunity::query()
            ->forTenant($tenantId)
            ->discovered()
            ->with('bot')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TelegramCommunity $community) => $community->toPublicArray())
            ->values()
            ->all();

        $access = app(TelegramLifecycleMetricsService::class)->forTenant(
            $tenantId,
            $request->query('buyer') ? trim((string) $request->query('buyer')) : null,
            $request->query('product_id') ? (string) $request->query('product_id') : null,
            $request->query('community_id') ? (int) $request->query('community_id') : null,
        );

        return Inertia::render('Integrations/Telegram/Index', [
            'bots' => $bots,
            'communities' => $communities,
            'discovered_communities' => $discoveredCommunities,
            'wizard' => $this->wizard->forTenant($tenantId),
            'policy' => [
                'module_available' => $this->policy->moduleAvailable($tenantId),
                'can_create_bot' => $this->policy->canCreateBot(
                    $tenantId,
                    TelegramBot::query()->forTenant($tenantId)->active()->count()
                ),
                'can_create_community' => $this->policy->canCreateCommunity(
                    $tenantId,
                    TelegramCommunity::query()->forTenant($tenantId)->active()->count()
                ),
                'limits' => $this->policy->limits(),
            ],
            'meta' => [
                'botfather_url' => (string) config('telegram.botfather_url'),
            ],
            'open_wizard' => $request->boolean('wizard'),
            'wizard_step' => max(1, min(4, (int) $request->query('step', 0) ?: 0)),
            'access' => $access,
        ]);
    }

    public function previewBot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
            $preview = $this->bots->previewToken($this->tenantId(), $validated['token']);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'token' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'bot' => $preview['bot_preview'],
        ]);
    }

    public function storeBot(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
            $bot = $this->bots->connect($this->tenantId(), $validated['token']);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'token' => $e->getMessage(),
            ]);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_TELEGRAM_BOT_CONNECTED, null, [
            'bot_id' => $bot->id,
            'telegram_bot_id' => $bot->telegram_bot_id,
            'username' => $bot->username,
        ]);

        return redirect()
            ->route('integrations.telegram.index', ['wizard' => 1, 'step' => 2])
            ->with('success', 'Bot conectado com sucesso.');
    }

    public function testBot(TelegramBot $bot): RedirectResponse
    {
        $this->bots->testConnection($this->tenantId(), $bot);

        return back()->with('success', 'Conexão do bot verificada.');
    }

    public function updateBotToken(Request $request, TelegramBot $bot): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
            $this->bots->updateToken($this->tenantId(), $bot, $validated['token']);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'token' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Token do bot atualizado.');
    }

    public function destroyBot(TelegramBot $bot): RedirectResponse
    {
        $this->bots->disconnect($this->tenantId(), $bot);

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_TELEGRAM_BOT_DISCONNECTED, null, [
            'bot_id' => $bot->id,
            'telegram_bot_id' => $bot->telegram_bot_id,
        ]);

        return back()->with('success', 'Bot desconectado. O histórico foi preservado.');
    }

    public function storeCommunity(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_bot_id' => ['required', 'integer'],
            'chat_id' => ['required', 'string', 'max:128'],
        ]);

        $bot = TelegramBot::query()
            ->forTenant($this->tenantId())
            ->whereKey($validated['telegram_bot_id'])
            ->firstOrFail();

        try {
            $community = $this->communities->link($this->tenantId(), $bot, $validated['chat_id']);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'chat_id' => $e->getMessage(),
            ]);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_TELEGRAM_COMMUNITY_LINKED, null, [
            'community_id' => $community->id,
            'telegram_chat_id' => $community->telegram_chat_id,
            'bot_id' => $community->telegram_bot_id,
        ]);

        $step = $community->ready_for_delivery ? 4 : 3;

        return redirect()
            ->route('integrations.telegram.index', ['wizard' => 1, 'step' => $step])
            ->with('success', 'Comunidade vinculada.');
    }

    public function confirmDiscoveredCommunity(TelegramCommunity $community): RedirectResponse
    {
        try {
            $community = $this->communities->confirmDiscovered($this->tenantId(), $community);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'community' => $e->getMessage(),
            ]);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_TELEGRAM_COMMUNITY_LINKED, null, [
            'community_id' => $community->id,
            'telegram_chat_id' => $community->telegram_chat_id,
            'bot_id' => $community->telegram_bot_id,
            'source' => 'discovered',
        ]);

        $step = $community->ready_for_delivery ? 4 : 3;

        return redirect()
            ->route('integrations.telegram.index', ['wizard' => 1, 'step' => $step])
            ->with('success', 'Comunidade vinculada a partir da lista detectada.');
    }

    public function refreshCommunity(TelegramCommunity $community): RedirectResponse
    {
        try {
            $this->communities->refreshInfo($this->tenantId(), $community);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'community' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Informações da comunidade atualizadas.');
    }

    public function verifyCommunity(TelegramCommunity $community): RedirectResponse
    {
        try {
            $updated = $this->communities->refreshPermissions($this->tenantId(), $community);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'community' => $e->getMessage(),
            ]);
        }

        $message = $updated->ready_for_delivery
            ? 'Permissões confirmadas. Comunidade pronta para entrega automática (quando disponível).'
            : 'Verificação concluída. Ainda faltam permissões essenciais.';

        return back()->with($updated->ready_for_delivery ? 'success' : 'error', $message);
    }

    public function destroyCommunity(TelegramCommunity $community): RedirectResponse
    {
        $this->communities->unlink($this->tenantId(), $community);

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_TELEGRAM_COMMUNITY_UNLINKED, null, [
            'community_id' => $community->id,
            'telegram_chat_id' => $community->telegram_chat_id,
        ]);

        return back()->with('success', 'Comunidade desvinculada.');
    }

    private function tenantId(): int
    {
        $user = auth()->user();
        $tenantId = (int) ($user?->tenant_id ?? $user?->id ?? 0);
        if ($tenantId <= 0) {
            abort(403);
        }

        return $tenantId;
    }
}
