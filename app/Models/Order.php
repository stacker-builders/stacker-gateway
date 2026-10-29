<?php

namespace App\Models;

use App\Gateways\GatewayRegistry;
use App\Services\PlatformPaymentMethods;
use App\Support\PlatformGa4Settings;
use App\Support\SaleOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'public_reference',
        'tenant_id', 'user_id', 'product_id', 'product_offer_id', 'subscription_plan_id',
        'api_application_id', 'api_checkout_session_id',
        'affiliate_user_id', 'affiliate_enrollment_id', 'sale_origin',
        'status', 'amount', 'shipping_amount', 'shipping_store_id', 'shipping_rule_id', 'shipping_address',
        'email', 'cpf', 'phone', 'customer_ip', 'coupon_code',
        'gateway', 'gateway_id', 'cajupay_account_id', 'payment_method', 'approved_manually', 'metadata', 'period_start', 'period_end', 'is_renewal',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'shipping_address' => 'array',
            'metadata' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'is_renewal' => 'boolean',
            'approved_manually' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->public_reference === null || $order->public_reference === '') {
                $order->public_reference = static::newUniquePublicReference();
            }
            PlatformGa4Settings::stampClientId($order);
        });

        static::saving(function (Order $order) {
            $order->syncAffiliateColumnsFromMetadata();
            if ($order->sale_origin === null || $order->sale_origin === '') {
                $origin = SaleOrigin::resolveForOrder($order);
                $order->sale_origin = $origin;
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['sale_origin'] = $origin;
                $order->metadata = $meta;
            }
        });
    }

    /** Código público do pedido (não sequencial), para exibir ao cliente. */
    public static function newUniquePublicReference(): string
    {
        do {
            $ref = strtoupper(Str::random(10));
        } while (static::query()->where('public_reference', $ref)->exists());

        return $ref;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Dono do tenant (infoprodutor): `tenant_id` referencia `users.id` do infoprodutor. */
    public function tenantOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function apiApplication(): BelongsTo
    {
        return $this->belongsTo(ApiApplication::class);
    }

    public function cajupayAccount(): BelongsTo
    {
        return $this->belongsTo(CajuPayAccount::class, 'cajupay_account_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    public function checkoutSession(): HasOne
    {
        return $this->hasOne(CheckoutSession::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function medDisputes(): HasMany
    {
        return $this->hasMany(MedDispute::class);
    }

    public function hasOpenMedDispute(): bool
    {
        return $this->medDisputes()->open()->exists();
    }

    /**
     * Valor líquido exibido em relatórios: soma das linhas (produto + order bumps) ou, se não houver itens, orders.amount.
     */
    public function lineItemsTotalAmount(): float
    {
        $this->loadMissing('orderItems');

        if ($this->orderItems->isEmpty()) {
            return (float) $this->amount;
        }

        return round((float) $this->orderItems->sum(fn ($it) => (float) ($it->amount ?? 0)), 2);
    }

    /**
     * Valor cobrado do produto principal (posição 0). Order bump, upsell e frete ficam de fora.
     */
    public function mainProductAmount(): float
    {
        $this->loadMissing('orderItems');

        if ($this->orderItems->isNotEmpty()) {
            $main = $this->orderItems->first(fn (OrderItem $item) => (int) ($item->position ?? 0) === 0)
                ?? $this->orderItems->sortBy(fn (OrderItem $item) => (int) ($item->position ?? 0))->first();
            if ($main !== null) {
                return round((float) $main->amount, 2);
            }
        }

        $amount = (float) $this->amount - (float) ($this->shipping_amount ?? 0);

        return max(0.0, round($amount, 2));
    }

    /**
     * Soma do produto principal + order bumps (sem frete nem upsell).
     */
    public function mainPlusBumpsAmount(): float
    {
        $this->loadMissing('orderItems');

        if ($this->orderItems->isNotEmpty()) {
            return round((float) $this->orderItems->sum(fn (OrderItem $item) => (float) ($item->amount ?? 0)), 2);
        }

        return $this->mainProductAmount();
    }

    public function getCheckoutSlug(): string
    {
        if ($this->productOffer && $this->productOffer->checkout_slug) {
            return $this->productOffer->checkout_slug;
        }
        if ($this->subscriptionPlan && $this->subscriptionPlan->checkout_slug) {
            return $this->subscriptionPlan->checkout_slug;
        }
        return $this->product?->checkout_slug ?? '';
    }

    /**
     * Rótulo para UI (vendas, export): PIX / Cartão / Boleto conforme o fluxo do checkout,
     * não o slug do gateway (ex.: mercadopago).
     */
    public function paymentMethodDisplayLabel(): string
    {
        $meta = $this->metadata ?? [];
        $m = isset($meta['checkout_payment_method']) ? strtolower((string) $meta['checkout_payment_method']) : '';

        return match ($m) {
            'pix' => 'PIX',
            'pix_auto' => 'PIX automático',
            'open_finance' => 'Open Finance',
            'card' => 'Cartão',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'paypal' => 'PayPal',
            'boleto' => 'Boleto',
            default => self::gatewaySlugDisplayLabel($this->gateway),
        };
    }

    /**
     * Nome da adquirente (recebedor) usada nesta transação.
     */
    public function acquirerDisplayName(): string
    {
        $slug = strtolower(trim((string) ($this->gateway ?? '')));
        if ($slug === '') {
            return '—';
        }
        if ($slug === 'manual') {
            return 'Manual';
        }

        $def = GatewayRegistry::get($slug);
        $name = is_array($def) ? trim((string) ($def['name'] ?? '')) : '';
        if ($name !== '') {
            return $name;
        }

        return Str::headline(str_replace(['_', '-'], ' ', $slug));
    }

    /**
     * Rótulo amigável para push de venda (ex.: "Cartão de crédito", "PIX").
     */
    public function paymentMethodPushLabel(): string
    {
        $key = $this->resolveCheckoutPaymentMethodKey();
        if ($key !== null) {
            foreach (PlatformPaymentMethods::labelsForAdmin() as $row) {
                if ($row['key'] === $key) {
                    return $row['label'];
                }
            }
        }

        return $this->paymentMethodDisplayLabel();
    }

    public function saleApprovedPushTitle(bool $isOrderBump = false): string
    {
        $prefs = $this->salePushContentPreferences();
        $base = $isOrderBump ? 'Venda aprovada — Order bump' : 'Venda aprovada';
        if (! empty($prefs['show_payment_method'])) {
            return $base.' ('.$this->paymentMethodPushLabel().')';
        }

        return $base;
    }

    public function saleApprovedPushBody(?OrderItem $item = null): string
    {
        $lines = $this->salePushContentLines($item);

        if ($lines === []) {
            return 'Você recebeu uma nova venda aprovada.';
        }

        return implode("\n", $lines);
    }

    public function pixGeneratedPushTitle(): string
    {
        return $this->pendingPaymentPushTitle('PIX gerado');
    }

    public function pixGeneratedPushBody(): string
    {
        return $this->pendingPaymentPushBody('PIX gerado — aguardando pagamento.');
    }

    public function boletoGeneratedPushTitle(): string
    {
        return $this->pendingPaymentPushTitle('Boleto gerado');
    }

    public function boletoGeneratedPushBody(): string
    {
        return $this->pendingPaymentPushBody('Boleto gerado — aguardando pagamento.');
    }

    /**
     * Uma mensagem por produto principal e uma por cada order bump do pedido.
     *
     * @return list<array{title: string, body: string, event_key: string, is_order_bump: bool}>
     */
    public function saleApprovedPushMessages(): array
    {
        try {
            $this->loadMissing(['product', 'orderItems.product']);
        } catch (\Throwable) {
            // Pedidos em memória (ex.: testes unitários) podem não ter conexão.
        }

        $items = ($this->relationLoaded('orderItems') ? $this->orderItems : collect())
            ->sortBy(fn (OrderItem $item) => (int) ($item->position ?? 0))
            ->values();

        if ($items->isEmpty()) {
            return [[
                'title' => $this->saleApprovedPushTitle(false),
                'body' => $this->saleApprovedPushBody(null),
                'event_key' => 'sale_'.$this->id,
                'is_order_bump' => false,
            ]];
        }

        $messages = [];
        foreach ($items as $item) {
            if ($item->isBundleItem()) {
                continue;
            }
            $isBump = $item->source === OrderItem::SOURCE_ORDER_BUMP
                || ((int) ($item->position ?? 0) > 0 && $item->source !== OrderItem::SOURCE_BUNDLE);
            $eventKey = $isBump
                ? 'sale_'.$this->id.'_bump_'.$item->id
                : 'sale_'.$this->id;

            $messages[] = [
                'title' => $this->saleApprovedPushTitle($isBump),
                'body' => $this->saleApprovedPushBody($item),
                'event_key' => $eventKey,
                'is_order_bump' => $isBump,
            ];
        }

        return $messages;
    }

    /**
     * Preferências de conteúdo compartilhadas entre venda aprovada, PIX gerado e boleto gerado.
     *
     * @return array<string, bool|string>
     */
    private function salePushContentPreferences(): array
    {
        return \App\Support\UserPushPreferences::forTenantOwner((int) ($this->tenant_id ?? 0));
    }

    /**
     * Linhas de produto, valor e forma de pagamento — mesmas regras para os três tipos de push.
     *
     * @return list<string>
     */
    private function salePushContentLines(?OrderItem $item = null): array
    {
        $prefs = $this->salePushContentPreferences();
        $product = $item?->product ?? $this->product;
        $displayName = null;
        if ($product) {
            $custom = trim((string) ($product->notification_name ?? ''));
            $displayName = $custom !== '' ? $custom : (string) $product->name;
        }

        $lines = [];
        if (! empty($prefs['show_product_name']) && $displayName) {
            $lines[] = 'Produto: '.$displayName;
        }
        if (! empty($prefs['show_sale_amount'])) {
            $mode = UserPushPreference::normalizeSaleAmountMode($prefs['sale_amount_mode'] ?? null);
            $breakdown = $this->salePushAmountBreakdownForItem($item);
            $value = $mode === UserPushPreference::SALE_AMOUNT_MODE_NET
                ? (float) $breakdown['net']
                : (float) $breakdown['gross'];
            $amount = number_format($value, 2, ',', '.');
            $lines[] = 'Valor: R$ '.$amount;
        }
        if (! empty($prefs['show_payment_method'])) {
            $lines[] = 'Pagamento: '.$this->paymentMethodPushLabel();
        }

        return $lines;
    }

    private function pendingPaymentPushTitle(string $base): string
    {
        $prefs = $this->salePushContentPreferences();
        if (! empty($prefs['show_payment_method'])) {
            return $base.' ('.$this->paymentMethodPushLabel().')';
        }

        return $base;
    }

    private function pendingPaymentPushBody(string $emptyFallback): string
    {
        $lines = $this->salePushContentLines();
        if ($lines === []) {
            return $emptyFallback;
        }
        $lines[] = 'Aguardando pagamento';

        return implode("\n", $lines);
    }

    /**
     * @return array{gross: float, net: float}
     */
    private function salePushAmountBreakdown(): array
    {
        $fallback = (float) $this->amount;
        try {
            $breakdown = \App\Services\OrderFeeBreakdownService::forOrder($this);

            return [
                'gross' => (float) ($breakdown['gross'] ?? $fallback),
                'net' => (float) ($breakdown['net'] ?? $fallback),
            ];
        } catch (\Throwable) {
            return [
                'gross' => $fallback,
                'net' => $fallback,
            ];
        }
    }

    /**
     * @return array{gross: float, net: float}
     */
    private function salePushAmountBreakdownForItem(?OrderItem $item): array
    {
        if ($item === null) {
            return $this->salePushAmountBreakdown();
        }

        $itemGross = round((float) $item->amount, 2);
        $orderBreakdown = $this->salePushAmountBreakdown();
        $orderGross = (float) $orderBreakdown['gross'];
        if ($orderGross <= 0.0) {
            return [
                'gross' => $itemGross,
                'net' => $itemGross,
            ];
        }

        $ratio = $itemGross / $orderGross;

        return [
            'gross' => $itemGross,
            'net' => round((float) $orderBreakdown['net'] * $ratio, 2),
        ];
    }

    /**
     * Chave normalizada do método escolhido no checkout (metadata ou coluna payment_method).
     */
    public function resolveCheckoutPaymentMethodKey(): ?string
    {
        $meta = $this->metadata ?? [];
        $m = isset($meta['checkout_payment_method']) ? strtolower(trim((string) $meta['checkout_payment_method'])) : '';
        if ($m !== '') {
            return $m;
        }

        $column = strtolower(trim((string) ($this->payment_method ?? '')));
        if ($column === '') {
            return null;
        }

        return match ($column) {
            'credit_card', 'creditcard', 'card' => 'card',
            'pix', 'pix_auto', 'boleto', 'apple_pay', 'google_pay', 'open_finance', 'paypal' => $column,
            default => null,
        };
    }

    public static function gatewaySlugDisplayLabel(?string $gateway): string
    {
        if ($gateway === null || $gateway === '') {
            return 'Outro';
        }
        $g = strtolower($gateway);
        if ($g === 'linaopenx') {
            return 'Open Finance';
        }
        if (in_array($g, ['spacepag'], true) || str_contains($g, 'pix')) {
            return 'PIX';
        }
        if ($g === 'card' || str_contains($g, 'cartao') || str_contains($g, 'cartão') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if ($g === 'boleto' || str_contains($g, 'boleto')) {
            return 'Boleto';
        }
        if ($g === 'manual') {
            return 'Manual';
        }

        return 'Outro';
    }

    /**
     * Chave para agrupamento em relatórios do infoprodutor (PIX/cartão/boleto, sem expor gateway interno).
     */
    public function paymentMethodReportKey(): string
    {
        $meta = is_array($this->metadata) ? $this->metadata : [];
        $method = strtolower(trim((string) ($meta['checkout_payment_method'] ?? $this->payment_method ?? '')));
        if ($method === 'pix_auto') {
            $method = 'pix';
        }
        if ($method === 'open_finance') {
            return 'pix';
        }
        if (in_array($method, ['spacepag', 'woovi', 'pushinpay', 'cajupay', 'efi', 'linaopenx', 'xflow', 'okto', 'xpag'], true)) {
            $method = 'pix';
        }
        if (in_array($method, ['pix', 'card', 'boleto', 'paypal'], true)) {
            return $method === 'paypal' ? 'card' : $method;
        }

        $gateway = strtolower(trim((string) ($this->gateway ?? '')));
        if ($gateway === '') {
            return 'outro';
        }
        if ($gateway === 'paypal' || $gateway === 'stripe') {
            return 'card';
        }
        if ($gateway === 'linaopenx' || str_contains($gateway, 'pix') || in_array($gateway, ['spacepag', 'woovi', 'pushinpay', 'cajupay', 'efi', 'xflow', 'okto', 'xpag'], true)) {
            return 'pix';
        }
        if ($gateway === 'card' || str_contains($gateway, 'cartao') || str_contains($gateway, 'cartão') || str_contains($gateway, 'credito')) {
            return 'card';
        }
        if ($gateway === 'boleto' || str_contains($gateway, 'boleto')) {
            return 'boleto';
        }

        return 'outro';
    }

    public static function paymentMethodReportLabel(string $method): string
    {
        return match ($method) {
            'pix' => 'PIX',
            'card' => 'Cartão',
            'boleto' => 'Boleto',
            default => 'Outro',
        };
    }

    public static function paymentMethodReportSort(string $method): int
    {
        return match ($method) {
            'pix' => 1,
            'card' => 2,
            'boleto' => 3,
            default => 99,
        };
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }

    /**
     * Venda originada via PixGO (venda rápida PIX no painel).
     */
    public function isPixGoSale(): bool
    {
        $m = $this->metadata ?? [];

        return is_array($m) && ($m['source'] ?? null) === 'pixgo';
    }

    /**
     * Venda originada por afiliado (quando o módulo de afiliados gravar metadata).
     */
    public function isAffiliateSale(): bool
    {
        if ($this->affiliate_user_id) {
            return true;
        }

        $m = $this->metadata ?? [];

        return is_array($m) && ! empty($m['affiliate_user_id']);
    }

    public function affiliateCommission(): HasOne
    {
        return $this->hasOne(AffiliateCommission::class);
    }

    public function syncAffiliateColumnsFromMetadata(): void
    {
        $m = is_array($this->metadata) ? $this->metadata : [];
        if (! empty($m['affiliate_user_id'])) {
            $this->affiliate_user_id = (int) $m['affiliate_user_id'];
        }
        if (! empty($m['affiliate_enrollment_id'])) {
            $this->affiliate_enrollment_id = (int) $m['affiliate_enrollment_id'];
        }
    }

    /**
     * Attach buyer to main product, order bumps and included combo products (same rules as public checkout after payment).
     * syncWithoutDetaching: se o cliente já tinha o incluso por outra compra, só ganha o que faltava.
     */
    public function grantPurchasedProductAccessToBuyer(): void
    {
        $this->load(['orderItems.product', 'product']);
        if ($this->product && $this->product->type !== Product::TYPE_PRODUTO_FISICO) {
            $this->product->users()->syncWithoutDetaching([$this->user_id]);
        }
        foreach ($this->orderItems as $item) {
            if ($item->product && $item->product->type !== Product::TYPE_PRODUTO_FISICO) {
                $item->product->users()->syncWithoutDetaching([$this->user_id]);
            }
        }
    }

    /**
     * Remove acesso à área de membros concedido por este pedido (reembolso / chargeback).
     * Mantém o vínculo se o comprador ainda tiver outro pedido pago/MED do mesmo produto.
     */
    public function revokePurchasedProductAccessFromBuyer(): void
    {
        if (! $this->user_id) {
            return;
        }

        if (\App\Services\MemberModuleAccessService::isRenewalOrder($this)) {
            app(\App\Services\MemberModuleAccessService::class)->revokeFromOrder($this);

            return;
        }

        $this->load(['orderItems.product', 'product']);
        $productIds = [];
        if ($this->product && $this->product->type !== Product::TYPE_PRODUTO_FISICO) {
            $productIds[] = (string) $this->product->id;
        }
        foreach ($this->orderItems as $item) {
            if ($item->product && $item->product->type !== Product::TYPE_PRODUTO_FISICO) {
                $productIds[] = (string) $item->product_id;
            }
        }
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return;
        }

        $revoked = [];
        foreach ($productIds as $productId) {
            if ($this->buyerStillHasActivePurchaseForProduct($productId)) {
                continue;
            }
            $product = Product::query()->find($productId);
            if ($product === null) {
                continue;
            }
            $product->users()->detach([$this->user_id]);
            $revoked[] = $productId;
        }

        if ($revoked === []) {
            return;
        }

        if (Schema::hasTable('member_turmas') && Schema::hasTable('member_turma_user')) {
            $turmaIds = MemberTurma::query()->whereIn('product_id', $revoked)->pluck('id');
            if ($turmaIds->isNotEmpty()) {
                DB::table('member_turma_user')
                    ->whereIn('member_turma_id', $turmaIds)
                    ->where('user_id', $this->user_id)
                    ->delete();
            }
        }

        if (Schema::hasTable('subscriptions')) {
            Subscription::query()
                ->where('user_id', $this->user_id)
                ->whereIn('product_id', $revoked)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                ->update(['status' => Subscription::STATUS_CANCELLED]);
        }
    }

    private function buyerStillHasActivePurchaseForProduct(string|int $productId): bool
    {
        $productId = (string) $productId;

        return self::query()
            ->where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->whereIn('status', ['completed', 'disputed'])
            ->where(function ($q) use ($productId) {
                $q->where('product_id', $productId)
                    ->orWhereHas('orderItems', fn ($items) => $items->where('product_id', $productId));
            })
            ->exists();
    }

    public function shippingStore(): BelongsTo
    {
        return $this->belongsTo(ShippingStore::class, 'shipping_store_id');
    }

    public function shippingRule(): BelongsTo
    {
        return $this->belongsTo(ShippingRule::class, 'shipping_rule_id');
    }
}
