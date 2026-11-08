<?php

namespace App\Services\Telegram;

use App\Models\Product;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Services\PlatformAuditService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Configuração de entrega Telegram vinculada a produtos (Etapa 3.1).
 * Convites reais, ativação e remoção entram nas etapas 3.2+.
 */
class TelegramProductDeliveryService
{
    public const ACCESS_PRESET_7 = '7';

    public const ACCESS_PRESET_15 = '15';

    public const ACCESS_PRESET_30 = '30';

    public const ACCESS_PRESET_90 = '90';

    public const ACCESS_PRESET_180 = '180';

    public const ACCESS_PRESET_365 = '365';

    public const ACCESS_PRESET_LIFETIME = 'lifetime';

    public const ACCESS_PRESET_CUSTOM = 'custom';

    /**
     * Venda/checkout Telegram controlados pelo painel admin (Plataforma → Telegram).
     * Global: toggle "Novas vendas". Por seller: política "Novas vendas" = Habilitada/Desabilitada/Herdar.
     * Env TELEGRAM_PRODUCT_SALES_OPERATIONAL=true permanece como override de emergência (liga global).
     */
    public function automaticDeliveryOperational(?int $tenantId = null): bool
    {
        $policy = app(TelegramPolicy::class);

        if (! $policy->autoDeliveryEnabled()) {
            return false;
        }

        // Override legado/emergência via env.
        if ((bool) config('telegram.product_sales_operational', false)) {
            if ($tenantId === null || $tenantId <= 0) {
                return true;
            }

            return $policy->canStartNewSale($tenantId);
        }

        $allowlist = config('telegram.product_sales_tenant_ids', []);
        if (is_array($allowlist) && $tenantId !== null && $tenantId > 0
            && in_array($tenantId, array_map('intval', $allowlist), true)) {
            return $policy->moduleAvailable($tenantId);
        }

        if ($tenantId === null || $tenantId <= 0) {
            return (bool) (TelegramModuleSettings::get()['sales_enabled'] ?? false);
        }

        return $policy->canStartNewSale($tenantId);
    }

    public function tablesReady(): bool
    {
        return Schema::hasTable('product_telegram_settings')
            && Schema::hasTable('product_telegram_communities');
    }

    /**
     * @return list<array{value: string, label: string, days: int|null, lifetime: bool}>
     */
    public function accessDurationOptions(): array
    {
        return [
            ['value' => self::ACCESS_PRESET_7, 'label' => '7 dias', 'days' => 7, 'lifetime' => false],
            ['value' => self::ACCESS_PRESET_15, 'label' => '15 dias', 'days' => 15, 'lifetime' => false],
            ['value' => self::ACCESS_PRESET_30, 'label' => '30 dias', 'days' => 30, 'lifetime' => false],
            ['value' => self::ACCESS_PRESET_90, 'label' => '90 dias', 'days' => 90, 'lifetime' => false],
            ['value' => self::ACCESS_PRESET_180, 'label' => '180 dias', 'days' => 180, 'lifetime' => false],
            ['value' => self::ACCESS_PRESET_365, 'label' => '365 dias', 'days' => 365, 'lifetime' => false],
            ['value' => self::ACCESS_PRESET_LIFETIME, 'label' => 'Vitalício', 'days' => null, 'lifetime' => true],
            ['value' => self::ACCESS_PRESET_CUSTOM, 'label' => 'Personalizado', 'days' => null, 'lifetime' => false],
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function inviteExpirationOptions(): array
    {
        return [
            ['value' => 1, 'label' => '1 hora'],
            ['value' => 6, 'label' => '6 horas'],
            ['value' => 12, 'label' => '12 horas'],
            ['value' => 24, 'label' => '24 horas'],
            ['value' => 48, 'label' => '48 horas'],
            ['value' => 72, 'label' => '72 horas'],
            ['value' => 168, 'label' => '7 dias'],
        ];
    }

    /**
     * @return array<string, array{label: string, description: string, available: bool}>
     */
    public function filterTypeConfig(array $typeConfig, ?int $tenantId, ?Product $existing = null): array
    {
        $policy = app(TelegramPolicy::class);
        if ($policy->moduleAvailable($tenantId)) {
            return $typeConfig;
        }

        if ($existing !== null && $existing->isTelegram()) {
            return $typeConfig;
        }

        unset($typeConfig[Product::TYPE_TELEGRAM]);

        return $typeConfig;
    }

    /**
     * Payload seguro para a UI do seller (sem tokens).
     *
     * @return array<string, mixed>
     */
    public function sellerDeliveryPayload(int $tenantId, ?Product $product = null): array
    {
        $policy = app(TelegramPolicy::class);
        $moduleAvailable = $policy->moduleAvailable($tenantId);
        $bots = [];

        if ($moduleAvailable && Schema::hasTable('telegram_bots')) {
            $bots = TelegramBot::forTenant($tenantId)
                ->active()
                ->with(['communities' => fn ($q) => $q->active()->orderBy('title')])
                ->orderBy('username')
                ->get()
                ->map(fn (TelegramBot $bot) => $bot->toPublicArray(true))
                ->values()
                ->all();
        }

        $settingPayload = null;
        $salesOperational = $this->automaticDeliveryOperational($tenantId);
        $readiness = [
            'configured' => false,
            'ready_for_sale' => false,
            'blocking_reasons' => $salesOperational
                ? []
                : ['Novas vendas Telegram estão desabilitadas no painel da plataforma (Plataforma → Telegram).'],
            'sales_operational' => $salesOperational,
        ];

        if ($product !== null && $product->isTelegram() && $this->tablesReady()) {
            $setting = $product->telegramSetting()->with(['bot', 'communities.bot'])->first();
            if ($setting) {
                $settingPayload = $this->settingToArray($setting);
            }
            $readiness = $this->evaluateReadiness($tenantId, $product, $setting);
        }

        return [
            'module_available' => $moduleAvailable,
            'can_start_new_sale' => $policy->canStartNewSale($tenantId),
            'expiry_removal_enabled' => $policy->expiryRemovalEnabled(),
            'integrations_url' => '/integracoes/telegram',
            'access_duration_options' => $this->accessDurationOptions(),
            'invite_expiration_options' => $this->inviteExpirationOptions(),
            'bots' => $bots,
            'setting' => $settingPayload,
            'readiness' => $readiness,
            'sales_operational' => $salesOperational,
            'sales_blocked_message' => $salesOperational
                ? ''
                : 'Produtos Telegram podem ser configurados e salvos. Publicação e checkout liberam quando o admin ativar Novas vendas em Plataforma → Telegram (global ou só a sua conta).',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function syncForProduct(Product $product, int $tenantId, array $input, ?Request $request = null): ProductTelegramSetting
    {
        if (! $this->tablesReady()) {
            throw new InvalidArgumentException('Execute as migrações do Telegram para configurar a entrega.');
        }

        if ((int) $product->tenant_id !== $tenantId) {
            throw new InvalidArgumentException('Produto não pertence a este tenant.');
        }

        if (! $product->isTelegram()) {
            throw new InvalidArgumentException('Somente produtos do tipo Telegram aceitam esta configuração.');
        }

        $policy = app(TelegramPolicy::class);
        if (! $policy->moduleAvailable($tenantId)) {
            throw new InvalidArgumentException('O módulo Telegram não está disponível para esta conta.');
        }

        $botId = isset($input['bot_id']) && $input['bot_id'] !== '' && $input['bot_id'] !== null
            ? (int) $input['bot_id']
            : null;
        $communityIds = collect($input['community_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $bot = null;
        if ($botId !== null) {
            $bot = TelegramBot::forTenant($tenantId)->whereKey($botId)->first();
            if (! $bot) {
                throw new InvalidArgumentException('Bot Telegram inválido para este tenant.');
            }
            if ($bot->isDisconnected()) {
                throw new InvalidArgumentException('O bot selecionado está desconectado. Reconecte-o em Integrações > Telegram.');
            }
        }

        if ($communityIds !== []) {
            if ($bot === null) {
                throw new InvalidArgumentException('Selecione um bot antes de vincular comunidades.');
            }

            $communities = TelegramCommunity::forTenant($tenantId)
                ->active()
                ->where('telegram_bot_id', $bot->id)
                ->whereIn('id', $communityIds)
                ->get();

            if ($communities->count() !== count($communityIds)) {
                throw new InvalidArgumentException('Uma ou mais comunidades selecionadas são inválidas para este bot/tenant.');
            }
        }

        [$isLifetime, $accessDuration, $accessUnit, $preset] = $this->resolveAccessDuration($input);

        $inviteHours = isset($input['invite_expiration_hours']) && $input['invite_expiration_hours'] !== ''
            ? (int) $input['invite_expiration_hours']
            : 24;
        if ($inviteHours < 1 || $inviteHours > 720) {
            throw new InvalidArgumentException('Validade do convite deve ser entre 1 e 720 horas.');
        }

        $welcome = HtmlSanitizer::plainTextMultiline((string) ($input['welcome_message'] ?? ''), 4000) ?: null;

        $autoRemove = array_key_exists('auto_remove_on_expiry', $input)
            ? filter_var($input['auto_remove_on_expiry'], FILTER_VALIDATE_BOOLEAN)
            : true;
        if (! $policy->expiryRemovalEnabled()) {
            $autoRemove = false;
        }

        return DB::transaction(function () use (
            $product,
            $tenantId,
            $bot,
            $communityIds,
            $isLifetime,
            $accessDuration,
            $accessUnit,
            $inviteHours,
            $welcome,
            $autoRemove,
            $preset,
            $request
        ) {
            /** @var ProductTelegramSetting $setting */
            $setting = ProductTelegramSetting::query()->updateOrCreate(
                ['product_id' => (string) $product->id],
                [
                    'tenant_id' => $tenantId,
                    'telegram_bot_id' => $bot?->id,
                    'access_duration' => $isLifetime ? null : $accessDuration,
                    'access_duration_unit' => $accessUnit,
                    'is_lifetime' => $isLifetime,
                    'invite_expiration_hours' => $inviteHours,
                    'welcome_message' => $welcome,
                    'auto_remove_on_expiry' => $autoRemove,
                ]
            );

            DB::table('product_telegram_communities')->where('product_id', (string) $product->id)->delete();
            foreach ($communityIds as $communityId) {
                DB::table('product_telegram_communities')->insert([
                    'tenant_id' => $tenantId,
                    'product_id' => (string) $product->id,
                    'telegram_community_id' => $communityId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            PlatformAuditService::log('products.telegram_delivery_updated', [
                'product_id' => $product->id,
                'tenant_id' => $tenantId,
                'telegram_bot_id' => $bot?->id,
                'community_ids' => $communityIds,
                'access_preset' => $preset,
                'is_lifetime' => $isLifetime,
                'invite_expiration_hours' => $inviteHours,
                'auto_remove_on_expiry' => $autoRemove,
            ], $request);

            return $setting->fresh(['bot', 'communities.bot']);
        });
    }

    public function clearForProduct(Product $product): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::table('product_telegram_communities')->where('product_id', (string) $product->id)->delete();
        ProductTelegramSetting::query()->where('product_id', (string) $product->id)->delete();
    }

    /**
     * Bloqueia publicação/venda na Etapa 3.1 e valida pré-requisitos quando a entrega estiver pronta.
     *
     * @return array{configured: bool, ready_for_sale: bool, blocking_reasons: list<string>, sales_operational: bool}
     */
    public function evaluateReadiness(int $tenantId, Product $product, ?ProductTelegramSetting $setting = null): array
    {
        $reasons = [];
        $policy = app(TelegramPolicy::class);
        $operational = $this->automaticDeliveryOperational($tenantId);

        if (! $operational) {
            $reasons[] = 'Novas vendas Telegram estão desabilitadas no painel da plataforma (Plataforma → Telegram). Peça ao admin para liberar globalmente ou só a sua conta.';
        }

        if (! $policy->moduleAvailable($tenantId)) {
            $reasons[] = 'O módulo Telegram não está habilitado para esta conta.';
        }

        $setting = $setting ?? ($this->tablesReady()
            ? $product->telegramSetting()->with(['bot', 'communities'])->first()
            : null);

        $configured = false;

        if (! $setting || $setting->telegram_bot_id === null) {
            $reasons[] = 'Selecione um bot Telegram conectado.';
        } elseif (! $setting->bot || $setting->bot->isDisconnected()) {
            $reasons[] = 'O bot vinculado está desconectado ou inválido.';
        } else {
            $communities = $setting->relationLoaded('communities')
                ? $setting->communities
                : $setting->communities()->get();

            if ($communities->isEmpty()) {
                $reasons[] = 'Vincule ao menos um grupo ou canal.';
            } else {
                $configured = true;
                foreach ($communities as $community) {
                    /** @var TelegramCommunity $community */
                    if (! $community->ready_for_delivery) {
                        $reasons[] = 'A comunidade “'.($community->title ?: $community->id).'” não possui as permissões obrigatórias.';
                    }
                    if ((int) $community->telegram_bot_id !== (int) $setting->telegram_bot_id) {
                        $reasons[] = 'Há comunidade vinculada a outro bot.';
                    }
                    if ((int) $community->tenant_id !== $tenantId) {
                        $reasons[] = 'Há comunidade fora do tenant do produto.';
                    }
                }
            }

            if (! $setting->is_lifetime) {
                if ($setting->access_duration === null || $setting->access_duration < 1) {
                    $reasons[] = 'Defina a duração do direito de acesso.';
                    $configured = false;
                }
            }

            if ($setting->invite_expiration_hours === null || $setting->invite_expiration_hours < 1) {
                $reasons[] = 'Defina a validade do convite.';
                $configured = false;
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'configured' => $configured,
            'ready_for_sale' => $operational && $reasons === [],
            'blocking_reasons' => $reasons,
            'sales_operational' => $operational,
        ];
    }

    public function canSellProduct(Product $product): bool
    {
        if (! $product->isTelegram()) {
            return true;
        }

        $tenantId = (int) $product->tenant_id;
        $readiness = $this->evaluateReadiness($tenantId, $product);

        return $readiness['ready_for_sale'];
    }

    public function assertCanPublish(Product $product): void
    {
        if (! $product->isTelegram()) {
            return;
        }

        $readiness = $this->evaluateReadiness((int) $product->tenant_id, $product);
        if ($readiness['ready_for_sale']) {
            return;
        }

        $message = $readiness['blocking_reasons'][0]
            ?? 'Este produto Telegram ainda não pode ser publicado para venda.';

        throw new InvalidArgumentException($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function settingToArray(ProductTelegramSetting $setting): array
    {
        $preset = $this->detectPreset($setting);

        return [
            'bot_id' => $setting->telegram_bot_id,
            'community_ids' => $setting->relationLoaded('communities')
                ? $setting->communities->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : [],
            'communities' => $setting->relationLoaded('communities')
                ? $setting->communities->map(fn (TelegramCommunity $c) => $c->toPublicArray())->values()->all()
                : [],
            'bot' => $setting->relationLoaded('bot') && $setting->bot
                ? $setting->bot->toPublicArray()
                : null,
            'access_duration_preset' => $preset,
            'access_duration' => $setting->access_duration,
            'access_duration_unit' => $setting->access_duration_unit,
            'is_lifetime' => (bool) $setting->is_lifetime,
            'invite_expiration_hours' => $setting->invite_expiration_hours,
            'welcome_message' => $setting->welcome_message,
            'auto_remove_on_expiry' => (bool) $setting->auto_remove_on_expiry,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: bool, 1: int|null, 2: string, 3: string}
     */
    private function resolveAccessDuration(array $input): array
    {
        $preset = (string) ($input['access_duration_preset'] ?? self::ACCESS_PRESET_30);
        $valid = collect($this->accessDurationOptions())->pluck('value')->all();
        if (! in_array($preset, $valid, true)) {
            throw new InvalidArgumentException('Duração de acesso inválida.');
        }

        if ($preset === self::ACCESS_PRESET_LIFETIME || filter_var($input['is_lifetime'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [true, null, 'days', self::ACCESS_PRESET_LIFETIME];
        }

        if ($preset === self::ACCESS_PRESET_CUSTOM) {
            $days = (int) ($input['access_duration_days'] ?? $input['access_duration'] ?? 0);
            if ($days < 1 || $days > 3650) {
                throw new InvalidArgumentException('Duração personalizada deve ser entre 1 e 3650 dias.');
            }

            return [false, $days, 'days', self::ACCESS_PRESET_CUSTOM];
        }

        $days = (int) $preset;

        return [false, $days, 'days', $preset];
    }

    private function detectPreset(ProductTelegramSetting $setting): string
    {
        if ($setting->is_lifetime) {
            return self::ACCESS_PRESET_LIFETIME;
        }

        $days = (int) ($setting->access_duration ?? 0);
        $known = ['7', '15', '30', '90', '180', '365'];
        if (in_array((string) $days, $known, true)) {
            return (string) $days;
        }

        return self::ACCESS_PRESET_CUSTOM;
    }
}
