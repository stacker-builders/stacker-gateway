<?php

namespace App\Http\Controllers;

use App\Events\ProductBeforeSave;
use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductDuplicated;
use App\Events\ProductIndexLoading;
use App\Events\ProductUpdated;
use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\CademiIntegration;
use App\Models\GatewayCredential;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\ProductCoproducer;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\ShippingStore;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MemberAccessGrantService;
use App\Services\MinimumChargeService;
use App\Services\PaymentService;
use App\Services\PhysicalProductAccess;
use App\Services\PlatformCardInstallments;
use App\Services\ProductApprovalService;
use App\Services\SellerActivityLogService;
use App\Services\SellerIntegrationVisibility;
use App\Services\StorageService;
use App\Services\TeamAccessService;
use App\Support\CardInstallments;
use App\Support\HtmlSanitizer;
use App\Support\MoneyDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ProdutosController extends Controller
{
    use LogsSellerActivity;

    /**
     * @return list<string>
     */
    private static function allowedProductTypes(?Product $existing = null): array
    {
        $types = [
            Product::TYPE_APLICATIVO,
            Product::TYPE_AREA_MEMBROS,
            Product::TYPE_AREA_MEMBROS_EXTERNA,
            Product::TYPE_LINK,
            Product::TYPE_LINK_PAGAMENTO,
            Product::TYPE_PRODUTO_FISICO,
        ];

        if (! PhysicalProductAccess::globalEnabled()) {
            $types = array_values(array_filter(
                $types,
                fn (string $t) => $t !== Product::TYPE_PRODUTO_FISICO
            ));
            if ($existing !== null && $existing->isPhysical()) {
                $types[] = Product::TYPE_PRODUTO_FISICO;
            }
        }

        return array_values(array_unique($types));
    }

    private const BILLING_TYPES = [
        Product::BILLING_ONE_TIME,
        Product::BILLING_SUBSCRIPTION,
    ];

    public function index(Request $request): Response
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id;
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        $query = Product::forTenant($tenantId)->orderBy('name');
        if ($user->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            $query->whereIn('id', $allowed ?: ['__none__']);
        }

        $coproIds = [];
        $coproPercents = [];
        if (! $user->isTeam()) {
            ProductCoproducer::expireOverdue();
            $coproRows = ProductCoproducer::query()
                ->where('co_producer_user_id', $user->id)
                ->where('status', ProductCoproducer::STATUS_ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->get(['product_id', 'commission_percent']);
            $coproIds = $coproRows->pluck('product_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
            foreach ($coproRows as $row) {
                $coproPercents[(string) $row->product_id] = (float) $row->commission_percent;
            }
        }

        if ($coproIds !== []) {
            $ownIds = (clone $query)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $allIds = array_values(array_unique(array_merge($ownIds, $coproIds)));
            $query = Product::query()->whereIn('id', $allIds ?: ['__none__'])->orderBy('name');
        }

        $products = $query->paginate(20)->withQueryString()->through(function (Product $p) use ($rates, $tenantId, $coproPercents) {
            $arr = $this->productToArray($p, $rates);
            $isCoproduction = (int) $p->tenant_id !== (int) $tenantId;
            $arr['is_coproduction'] = $isCoproduction;
            $arr['coproduction_percent'] = $isCoproduction
                ? ($coproPercents[(string) $p->id] ?? null)
                : null;

            return $arr;
        });

        $productTypes = collect(PhysicalProductAccess::filterTypeConfig(Product::typeConfig()))->map(fn ($config, $value) => [
            'value' => $value,
            'label' => $config['label'],
            'description' => $config['description'],
            'available' => $config['available'],
        ])->values()->all();

        $billingTypes = collect(Product::billingTypeLabels())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();

        $data = new \ArrayObject([
            'produtos' => $products,
            'productTypes' => $productTypes,
            'billingTypes' => $billingTypes,
            'productCategories' => Product::categoriesForSelect(),
            'exchange_rates' => $rates,
            'plugin_card_actions' => [],
            'plugin_form_sections' => [],
            'checkout_gateway_ui' => $this->checkoutGatewayUiForTenant($tenantId),
        ]);
        event(new ProductIndexLoading($data));
        $payload = $data->getArrayCopy();

        return Inertia::render('Produtos/Index', $payload);
    }

    /**
     * Co-produções do infoprodutor logado: convites pendentes (mesmo e-mail) e vínculos ativos.
     */
    public function coproductionIndex(): Response
    {
        $user = auth()->user();
        $email = ProductCoproducer::normalizeEmail((string) $user->email);

        $pending = ProductCoproducer::query()
            ->where('status', ProductCoproducer::STATUS_PENDING)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->with(['product', 'inviter:id,name,email'])
            ->orderByDesc('updated_at')
            ->get();

        $active = ProductCoproducer::query()
            ->where('status', ProductCoproducer::STATUS_ACTIVE)
            ->where('co_producer_user_id', $user->id)
            ->with(['product', 'inviter:id,name,email'])
            ->orderByDesc('accepted_at')
            ->get();

        return Inertia::render('Produtos/Coproducao', [
            'coproduction_pending' => $pending->map(fn (ProductCoproducer $row) => $this->coproductionRowToArray($row))->values()->all(),
            'coproduction_active' => $active->map(fn (ProductCoproducer $row) => $this->coproductionRowToArray($row))->values()->all(),
        ]);
    }

    public function create()
    {
        return redirect()->route('produtos.index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'notification_name' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'in:'.implode(',', Product::categoryKeys())],
            'type' => ['required', 'string', 'in:'.implode(',', self::allowedProductTypes())],
            'billing_type' => ['required', 'string', 'in:'.implode(',', self::BILLING_TYPES)],
            'price' => $this->platformPriceRules(),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'deliverable_link' => ['nullable', 'string', 'url', 'max:500'],
            'card_installments' => ['nullable', 'array'],
            'card_installments.enabled' => ['nullable', 'boolean'],
            'card_installments.max' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        // Texto puro (evita XSS armazenado em nome/descrição)
        $validated['name'] = HtmlSanitizer::plainText($validated['name'] ?? '', 255);
        if (array_key_exists('notification_name', $validated)) {
            $nn = HtmlSanitizer::plainText($validated['notification_name'] ?? '', 80);
            $validated['notification_name'] = $nn !== '' ? $nn : null;
        }
        if (array_key_exists('support_email', $validated)) {
            $supportEmail = strtolower(trim((string) ($validated['support_email'] ?? '')));
            $validated['support_email'] = ($supportEmail !== '' && filter_var($supportEmail, FILTER_VALIDATE_EMAIL))
                ? $supportEmail
                : null;
        }
        if (array_key_exists('description', $validated)) {
            $validated['description'] = HtmlSanitizer::plainTextMultiline($validated['description'], 20000) ?: null;
        }

        $tenantId = auth()->user()->tenant_id;
        if ($tenantId === null) {
            return back()->with('error', 'Conta sem tenant configurado. Atualize a página ou entre em contato com o suporte.')->withInput();
        }

        $validated['tenant_id'] = $tenantId;
        $baseSlug = trim((string) ($validated['slug'] ?? '')) !== ''
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);
        $validated['slug'] = Product::uniqueSlugForTenant($tenantId, $baseSlug !== '' ? $baseSlug : 'produto');
        $validated['currency'] = $validated['currency'] ?? config('products.currency_default', 'BRL');
        $validated['price'] = MoneyDecimal::storageFromBrl(
            (float) $validated['price'],
            (string) $validated['currency'],
            $this->productRates()
        );
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['refund_policy_days'] = 7;
        $this->assertPhysicalProductRules($validated['type'] ?? '', $validated['billing_type'] ?? '');

        $approvalService = app(ProductApprovalService::class);
        $approvalAttrs = $approvalService->attributesForNewProduct((bool) $validated['is_active']);
        if ($approvalAttrs !== []) {
            $validated = array_merge($validated, $approvalAttrs);
        }

        $product = new Product($validated);
        $beforeEvent = new ProductBeforeSave($product, $validated, true);
        event($beforeEvent);
        if ($beforeEvent->abort !== null) {
            return back()->with('error', $beforeEvent->abort)->withInput();
        }

        unset($validated['image']);
        $deliverableLink = $validated['deliverable_link'] ?? null;
        unset($validated['deliverable_link']);
        $cardInstallmentsInput = $validated['card_installments'] ?? null;
        unset($validated['card_installments']);

        try {
            $product = DB::transaction(function () use ($request, $validated, $deliverableLink, $cardInstallmentsInput) {
                $product = Product::create($validated);

                if ($request->has('deliverable_link')) {
                    $config = $product->checkout_config ?? [];
                    $config['deliverable_link'] = $deliverableLink ?? '';
                    $product->update(['checkout_config' => $config]);
                }

                $this->applyCardInstallmentsToProduct($product, $cardInstallmentsInput);

                if ($request->hasFile('image')) {
                    $path = app(StorageService::class)->putFile('products', $request->file('image'));
                    $product->update(['image' => $path]);
                }

                return $product;
            });
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['image' => $e->getMessage()])
                ->with('error', $e->getMessage())
                ->withInput();
        } catch (QueryException $e) {
            if ($this->isDuplicateProductSlugException($e)) {
                $validated['slug'] = Product::uniqueSlugForTenant($tenantId, $baseSlug !== '' ? $baseSlug : 'produto');
                try {
                    $product = DB::transaction(function () use ($request, $validated, $deliverableLink, $cardInstallmentsInput) {
                        $product = Product::create($validated);

                        if ($request->has('deliverable_link')) {
                            $config = $product->checkout_config ?? [];
                            $config['deliverable_link'] = $deliverableLink ?? '';
                            $product->update(['checkout_config' => $config]);
                        }

                        $this->applyCardInstallmentsToProduct($product, $cardInstallmentsInput);

                        if ($request->hasFile('image')) {
                            $path = app(StorageService::class)->putFile('products', $request->file('image'));
                            $product->update(['image' => $path]);
                        }

                        return $product;
                    });
                } catch (QueryException) {
                    return back()
                        ->withErrors(['name' => 'Já existe um produto com este nome. Escolha outro nome.'])
                        ->withInput();
                }
            } else {
                throw $e;
            }
        }

        event(new ProductCreated($product));

        app(ProductApprovalService::class)->afterCreated($product, $request);

        $success = 'Produto criado.';
        if (($product->approval_status ?? null) === Product::APPROVAL_PENDING) {
            $success = 'Produto em análise. Seu produto foi enviado para análise das regras internas da plataforma. Após a avaliação, você será informado sobre a aprovação ou sobre eventuais ajustes necessários.';
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_CREATED, $product, [
            'name' => $product->name,
        ]);

        return redirect()->route('produtos.index')->with('success', $success);
    }

    public function edit(Product $produto): Response
    {
        $this->authorizeProduct($produto, manage: false);
        $produto->load('users:id,name,email', 'offers', 'subscriptionPlans', 'orderBumps');

        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        $productTypes = collect(PhysicalProductAccess::filterTypeConfig(Product::typeConfig()))->map(fn ($config, $value) => [
            'value' => $value,
            'label' => $config['label'],
            'description' => $config['description'],
            'available' => $config['available'],
        ])->values()->all();

        $billingTypes = collect(Product::billingTypeLabels())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();

        $produtoArray = $this->productToArray($produto, $rates);
        $produtoArray['users'] = $produto->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all();
        $produtoArray['offers'] = $produto->offers->map(fn ($o) => [
            'id' => $o->id,
            'name' => $o->name,
            'price' => (float) $o->price,
            'currency' => $o->currency ?? $produto->currency ?? 'BRL',
            'checkout_slug' => $o->checkout_slug,
            'position' => $o->position,
            'affiliate_share_enabled' => (bool) ($o->affiliate_share_enabled ?? false),
        ])->values()->all();
        $produtoArray['subscription_plans'] = $produto->subscriptionPlans->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'currency' => $p->currency ?? $produto->currency ?? 'BRL',
            'interval' => $p->interval,
            'checkout_slug' => $p->checkout_slug,
            'position' => $p->position,
        ])->values()->all();

        $basePlan = $produto->subscriptionPlans->sortBy('position')->first();
        $produtoArray['base_interval'] = $basePlan?->interval ?? 'monthly';

        $orderBumps = $produto->orderBumps()->with(['targetProduct', 'targetProductOffer'])->get();
        $produtoArray['order_bumps'] = $orderBumps->map(function (ProductOrderBump $b) {
            $target = $b->targetProduct;
            $imageUrl = $target && $target->image
                ? app(StorageService::class)->url($target->image)
                : null;

            return [
                'id' => $b->id,
                'target_product_id' => $b->target_product_id,
                'target_product_offer_id' => $b->target_product_offer_id,
                'target_name' => $target?->name,
                'target_image_url' => $imageUrl,
                'title' => $b->title,
                'description' => $b->description,
                'price_override' => $b->price_override !== null ? (float) $b->price_override : null,
                'cta_title' => $b->cta_title,
                'position' => $b->position,
                'effective_amount_brl' => $b->getEffectiveAmountBrl(),
            ];
        })->values()->all();

        $tenantId = auth()->user()->tenant_id;
        $availableForBump = Product::forTenant($tenantId)
            ->where('id', '!=', $produto->id)
            ->where('billing_type', Product::BILLING_ONE_TIME)
            ->availableForPurchase()
            ->orderBy('name')
            ->with('offers')
            ->get();
        $produtoArray['available_products_for_bump'] = $availableForBump->map(function (Product $p) {
            $imageUrl = $p->image ? app(StorageService::class)->url($p->image) : null;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'image_url' => $imageUrl,
                'price' => (float) $p->price,
                'currency' => $p->currency ?? 'BRL',
                'offers' => $p->offers->map(fn ($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'price' => (float) $o->price,
                    'currency' => $o->currency ?? $p->currency ?? 'BRL',
                ])->values()->all(),
            ];
        })->values()->all();

        $checkoutConfig = $produto->checkout_config ?? [];
        $checkoutConfig['email_template'] = array_merge(
            Product::defaultEmailTemplate(),
            $checkoutConfig['email_template'] ?? []
        );
        $produtoArray['checkout_config'] = $checkoutConfig;

        $external = DB::table('cademi_integration_product')
            ->where('product_id', $produto->id)
            ->orderByDesc('id')
            ->first();
        $produtoArray['external_member_area'] = $external ? [
            'integration_id' => (int) $external->cademi_integration_id,
            'cademi_tag_id' => $external->cademi_tag_id !== null ? (int) $external->cademi_tag_id : null,
            'cademi_produto_id' => $external->cademi_produto_id !== null ? (int) $external->cademi_produto_id : null,
            'cademi_produto_ids' => property_exists($external, 'cademi_produto_ids') && $external->cademi_produto_ids
                ? (json_decode((string) $external->cademi_produto_ids, true) ?: [])
                : [],
        ] : [
            'integration_id' => null,
            'cademi_tag_id' => null,
            'cademi_produto_id' => null,
            'cademi_produto_ids' => [],
        ];

        $productsForUpsell = Product::where('tenant_id', $tenantId)
            ->availableForPurchase()
            ->where('id', '!=', $produto->id)
            ->with('offers')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'offers' => $p->offers->map(fn ($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'price' => (float) $o->price,
                ])->values()->all(),
            ])
            ->values()->all();
        $produtoArray['products_for_upsell'] = $productsForUpsell;

        $checkoutGatewayUi = $this->checkoutGatewayUiForTenant($tenantId);
        $paymentService = app(PaymentService::class);

        $basePlanForGlobalMethods = $produto->billing_type === Product::BILLING_SUBSCRIPTION
            ? $produto->subscriptionPlans()->orderBy('position')->first()
            : null;
        $globalPaymentMethodsAvailable = $paymentService->globallyAvailablePaymentMethodKeys($produto, $basePlanForGlobalMethods);

        $cademiAvailable = SellerIntegrationVisibility::effectiveForTenant(
            SellerIntegrationVisibility::CADEMI,
            $tenantId !== null ? (int) $tenantId : null
        );
        $cademiIntegrations = $cademiAvailable
            ? CademiIntegration::forTenant($tenantId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CademiIntegration $i) => ['id' => $i->id, 'name' => $i->name])
                ->values()
                ->all()
            : [];

        ProductCoproducer::expireOverdue();

        $produtoArray['coproducers'] = $produto->coproducers()
            ->with('coProducer:id,name,email')
            ->orderByDesc('id')
            ->get()
            ->map(function (ProductCoproducer $c) {
                return [
                    'id' => $c->id,
                    'email' => $c->email,
                    'status' => $c->status,
                    'commission_percent' => (float) $c->commission_percent,
                    'commission_on_direct_sales' => $c->commission_on_direct_sales,
                    'commission_on_affiliate_sales' => $c->commission_on_affiliate_sales,
                    'duration_preset' => $c->duration_preset,
                    'starts_at' => $c->starts_at?->toIso8601String(),
                    'ends_at' => $c->ends_at?->toIso8601String(),
                    'accepted_at' => $c->accepted_at?->toIso8601String(),
                    'co_producer_name' => $c->coProducer?->name,
                ];
            })
            ->values()
            ->all();

        $checkoutBaseUrl = $produto->checkout_slug ? url('/c/'.$produto->checkout_slug) : '';
        $produtoArray['affiliate_enabled'] = (bool) $produto->affiliate_enabled;
        $produtoArray['affiliate_commission_percent'] = (float) $produto->affiliate_commission_percent;
        $produtoArray['affiliate_manual_approval'] = (bool) $produto->affiliate_manual_approval;
        $produtoArray['affiliate_show_in_showcase'] = (bool) $produto->affiliate_show_in_showcase;
        $produtoArray['affiliate_page_url'] = $produto->affiliate_page_url;
        $produtoArray['affiliate_support_email'] = $produto->affiliate_support_email;
        $produtoArray['affiliate_showcase_description'] = $produto->affiliate_showcase_description;
        $produtoArray['affiliate_hide_customer_data'] = (bool) $produto->affiliate_hide_customer_data;
        $produtoArray['affiliate_checkout_base_url'] = $checkoutBaseUrl;
        if ($produto->affiliate_enabled) {
            $produto->ensureAffiliateInviteToken();
        }
        $produtoArray['affiliate_join_url'] = $produto->affiliate_enabled ? $produto->affiliateJoinUrl() : null;

        $produtoArray['refund_policy_days'] = $produto->refund_policy_days !== null
            ? (int) $produto->refund_policy_days
            : null;

        $produtoArray['affiliate_enrollments'] = ProductAffiliateEnrollment::query()
            ->where('product_id', (string) $produto->getKey())
            ->with('affiliate')
            ->orderByRaw("case status when 'pending' then 0 when 'approved' then 1 when 'rejected' then 2 when 'revoked' then 3 else 4 end")
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (ProductAffiliateEnrollment $e) use ($checkoutBaseUrl) {
                $link = $e->public_ref && $checkoutBaseUrl
                    ? $checkoutBaseUrl.(str_contains($checkoutBaseUrl, '?') ? '&' : '?').'ref='.urlencode($e->public_ref)
                    : null;

                return [
                    'id' => $e->id,
                    'affiliate_user_id' => $e->affiliate_user_id,
                    'status' => $e->status,
                    'public_ref' => $e->public_ref,
                    'affiliate_link' => $link,
                    'affiliate_name' => $e->affiliate?->name,
                    'affiliate_email' => $e->affiliate?->email,
                    'created_at' => $e->created_at?->toIso8601String(),
                    'updated_at' => $e->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $shippingStores = ShippingStore::forTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (ShippingStore $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_active' => $s->is_active,
            ])
            ->values()
            ->all();

        $coproductionReadonly = ProductCoproducer::isActiveCoproducerOf((int) auth()->id(), (string) $produto->id)
            && (int) $produto->tenant_id !== (int) $tenantId;

        return Inertia::render('Produtos/Edit', [
            'produto' => $produtoArray,
            'coproduction_readonly' => $coproductionReadonly,
            'productTypes' => $productTypes,
            'billingTypes' => $billingTypes,
            'productCategories' => Product::categoriesForSelect(),
            'exchange_rates' => $rates,
            'checkout_gateway_ui' => $checkoutGatewayUi,
            'global_payment_methods_available' => $globalPaymentMethodsAvailable,
            'cademi_integrations' => $cademiIntegrations,
            'cademi_available' => $cademiAvailable,
            'shipping_stores' => PhysicalProductAccess::globalEnabled() ? $shippingStores : [],
            'layoutContentFlushLeft' => true,
            'pageTitleBadge' => $produto->name,
        ]);
    }

    public function updateExternalMemberArea(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'cademi_integration_id' => ['nullable', 'integer', 'exists:cademi_integrations,id'],
            'cademi_tag_id' => ['nullable', 'integer', 'min:1'],
            'cademi_produto_id' => ['nullable', 'integer', 'min:1'],
            'cademi_produto_ids' => ['nullable', 'array'],
            'cademi_produto_ids.*' => ['integer', 'min:1'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $cademiAvailable = SellerIntegrationVisibility::effectiveForTenant(
            SellerIntegrationVisibility::CADEMI,
            $tenantId !== null ? (int) $tenantId : null
        );
        if (! $cademiAvailable && ! empty($validated['cademi_integration_id'])) {
            abort(403, 'A integração Cademí não está disponível.');
        }
        if (! empty($validated['cademi_integration_id'])) {
            $exists = CademiIntegration::forTenant($tenantId)->where('id', (int) $validated['cademi_integration_id'])->exists();
            if (! $exists) {
                abort(422, 'Integração Cademí inválida para este tenant.');
            }
        }

        // TAG é opcional (postback pode funcionar sem tags).

        DB::table('cademi_integration_product')->where('product_id', $produto->id)->delete();

        if (! empty($validated['cademi_integration_id'])) {
            $produtoIds = [];
            if (! empty($validated['cademi_produto_ids']) && is_array($validated['cademi_produto_ids'])) {
                $produtoIds = array_values(array_unique(array_map('intval', $validated['cademi_produto_ids'])));
                $produtoIds = array_values(array_filter($produtoIds, fn ($v) => $v > 0));
            } elseif (! empty($validated['cademi_produto_id'])) {
                $produtoIds = [(int) $validated['cademi_produto_id']];
            }

            if ($produtoIds === []) {
                abort(422, 'Informe ao menos 1 Produto ID da Cademí.');
            }

            DB::table('cademi_integration_product')->insert([
                'cademi_integration_id' => (int) $validated['cademi_integration_id'],
                'product_id' => $produto->id,
                'cademi_tag_id' => $validated['cademi_tag_id'] ?? null,
                // keep legacy single field in sync (first id)
                'cademi_produto_id' => $produtoIds[0] ?? null,
                'cademi_produto_ids' => json_encode($produtoIds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_MEMBER_AREA_UPDATED, $produto, [
            'name' => $produto->name,
        ]);

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);

        if ($request->has('conversion_pixels') && is_string($request->conversion_pixels)) {
            $decoded = json_decode($request->conversion_pixels, true);
            $request->merge(['conversion_pixels' => is_array($decoded) ? $decoded : null]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'notification_name' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'in:'.implode(',', Product::categoryKeys())],
            'type' => ['required', 'string', 'in:'.implode(',', self::allowedProductTypes($produto))],
            'billing_type' => ['required', 'string', 'in:'.implode(',', self::BILLING_TYPES)],
            'price' => $this->platformPriceRules(),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'conversion_pixels' => ['nullable', 'array'],
            'conversion_pixels.meta' => ['nullable', 'array'],
            'conversion_pixels.meta.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.meta.entries' => ['nullable', 'array'],
            'conversion_pixels.meta.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.meta.entries.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.meta.entries.*.access_token' => ['nullable', 'string', 'max:500'],
            'conversion_pixels.meta.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.meta.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.meta.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok' => ['nullable', 'array'],
            'conversion_pixels.tiktok.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok.entries' => ['nullable', 'array'],
            'conversion_pixels.tiktok.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.tiktok.entries.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.tiktok.entries.*.access_token' => ['nullable', 'string', 'max:500'],
            'conversion_pixels.tiktok.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads' => ['nullable', 'array'],
            'conversion_pixels.google_ads.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads.entries' => ['nullable', 'array'],
            'conversion_pixels.google_ads.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_ads.entries.*.conversion_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_ads.entries.*.conversion_label' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_ads.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics' => ['nullable', 'array'],
            'conversion_pixels.google_analytics.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics.entries' => ['nullable', 'array'],
            'conversion_pixels.google_analytics.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_analytics.entries.*.measurement_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_analytics.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.custom_script' => ['nullable', 'array'],
            'conversion_pixels.custom_script.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.custom_script.*.name' => ['nullable', 'string', 'max:255'],
            'conversion_pixels.custom_script.*.script' => ['nullable', 'string', 'max:65535'],
            'card_installments' => ['nullable', 'array'],
            'card_installments.enabled' => ['nullable', 'boolean'],
            'card_installments.max' => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_methods_enabled' => ['nullable', 'array'],
            'payment_methods_enabled.pix' => ['nullable', 'boolean'],
            'payment_methods_enabled.card' => ['nullable', 'boolean'],
            'payment_methods_enabled.boleto' => ['nullable', 'boolean'],
            'payment_methods_enabled.pix_auto' => ['nullable', 'boolean'],
            'payment_methods_enabled.apple_pay' => ['nullable', 'boolean'],
            'payment_methods_enabled.google_pay' => ['nullable', 'boolean'],
            'payment_methods_enabled.open_finance' => ['nullable', 'boolean'],
            'payment_methods_enabled.paypal' => ['nullable', 'boolean'],
            'email_template' => ['nullable', 'array'],
            'email_template.logo_url' => ['nullable', 'string', 'max:500'],
            'email_template.from_name' => ['nullable', 'string', 'max:255'],
            'email_template.subject' => ['nullable', 'string', 'max:255'],
            'email_template.body_html' => ['nullable', 'string', 'max:65535'],
            'deliverable_link' => ['nullable', 'string', 'url', 'max:500'],
            'base_interval' => ['nullable', 'string', 'in:weekly,monthly,quarterly,semi_annual,annual,lifetime'],
            'refund_policy_days' => ['nullable', 'integer', 'in:7,14,30'],
            'shipping_store_id' => ['nullable', 'integer', 'exists:shipping_stores,id'],
            'physical_free_shipping' => ['nullable', 'boolean'],
        ]);

        $this->assertPhysicalProductRules($validated['type'] ?? '', $validated['billing_type'] ?? '');

        // Texto puro (evita XSS armazenado em nome/descrição/template)
        $validated['name'] = HtmlSanitizer::plainText($validated['name'] ?? '', 255);
        if (array_key_exists('notification_name', $validated)) {
            $nn = HtmlSanitizer::plainText($validated['notification_name'] ?? '', 80);
            $validated['notification_name'] = $nn !== '' ? $nn : null;
        }
        if (array_key_exists('support_email', $validated)) {
            $supportEmail = strtolower(trim((string) ($validated['support_email'] ?? '')));
            $validated['support_email'] = ($supportEmail !== '' && filter_var($supportEmail, FILTER_VALIDATE_EMAIL))
                ? $supportEmail
                : null;
        }
        if (array_key_exists('description', $validated)) {
            $validated['description'] = HtmlSanitizer::plainTextMultiline($validated['description'], 20000) ?: null;
        }
        if (isset($validated['email_template']) && is_array($validated['email_template'])) {
            if (array_key_exists('from_name', $validated['email_template'])) {
                $validated['email_template']['from_name'] = HtmlSanitizer::plainText($validated['email_template']['from_name'], 255) ?: null;
            }
            if (array_key_exists('subject', $validated['email_template'])) {
                $validated['email_template']['subject'] = HtmlSanitizer::plainText($validated['email_template']['subject'], 255) ?: null;
            }
            if (array_key_exists('body_html', $validated['email_template'])) {
                // Corpo do e-mail permite HTML, mas sanitizamos para remover scripts/eventos.
                $validated['email_template']['body_html'] = \App\Support\HtmlSanitizer::sanitize((string) ($validated['email_template']['body_html'] ?? ''));
            }
        }

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['currency'] = $validated['currency'] ?? config('products.currency_default', 'BRL');
        $validated['price'] = MoneyDecimal::storageFromBrl(
            (float) $validated['price'],
            (string) $validated['currency'],
            $this->productRates()
        );
        $validated['refund_policy_days'] = ($validated['refund_policy_days'] ?? null) !== null
            ? (int) $validated['refund_policy_days']
            : null;

        try {
            app(ProductApprovalService::class)->guardSellerActivation($produto, (bool) $validated['is_active']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $beforeEvent = new ProductBeforeSave($produto, $validated, false);
        event($beforeEvent);
        if ($beforeEvent->abort !== null) {
            return back()->with('error', $beforeEvent->abort)->withInput();
        }

        $oldImage = $produto->image;
        unset($validated['image']);
        $cardInstallments = $validated['card_installments'] ?? null;
        unset($validated['card_installments']);
        $paymentMethodsEnabledInput = $validated['payment_methods_enabled'] ?? null;
        unset($validated['payment_methods_enabled']);
        $emailTemplate = $validated['email_template'] ?? null;
        unset($validated['email_template']);
        $deliverableLink = $validated['deliverable_link'] ?? null;
        unset($validated['deliverable_link']);
        $conversionPixels = $validated['conversion_pixels'] ?? null;
        unset($validated['conversion_pixels']);
        $baseInterval = $validated['base_interval'] ?? null;
        unset($validated['base_interval']);
        $physicalFreeShipping = $request->boolean('physical_free_shipping');
        $shippingStoreId = $validated['shipping_store_id'] ?? null;
        unset($validated['physical_free_shipping'], $validated['shipping_store_id']);

        // Slug é gerado na criação e permanece estável (identificador interno).
        unset($validated['slug']);

        if (($validated['type'] ?? $produto->type) === Product::TYPE_PRODUTO_FISICO) {
            if ($shippingStoreId !== null) {
                $storeOk = ShippingStore::forTenant(auth()->user()->tenant_id)
                    ->where('id', $shippingStoreId)
                    ->where('is_active', true)
                    ->exists();
                if (! $storeOk) {
                    return back()->with('error', 'Loja de frete inválida ou inativa.')->withInput();
                }
            }
            $validated['shipping_store_id'] = $shippingStoreId;
            $validated['physical_config'] = ['free_shipping' => $physicalFreeShipping];
        } else {
            $validated['shipping_store_id'] = null;
            $validated['physical_config'] = null;
        }

        $paymentMethodsPm = null;
        if (is_array($paymentMethodsEnabledInput)) {
            $paymentMethodsPm = $this->resolveProductPaymentMethods(
                $request,
                $produto,
                $validated
            );
            if ($paymentMethodsPm['error'] !== null) {
                return back()->withErrors(['payment_methods_enabled' => $paymentMethodsPm['error']])->withInput();
            }
        }

        try {
            DB::transaction(function () use (
                $request,
                $produto,
                $validated,
                $baseInterval,
                $conversionPixels,
                $cardInstallments,
                $deliverableLink,
                $emailTemplate,
                $paymentMethodsPm,
                $oldImage
            ) {
                $produto->update($validated);

                if ($produto->billing_type === Product::BILLING_SUBSCRIPTION && $baseInterval !== null) {
                    $basePlan = $produto->subscriptionPlans()->orderBy('position')->first();
                    $labels = SubscriptionPlan::intervalLabels();
                    $planName = $labels[$baseInterval] ?? ucfirst($baseInterval);
                    if ($basePlan) {
                        $basePlan->update([
                            'name' => $planName,
                            'price' => $produto->price,
                            'currency' => $produto->currency ?? 'BRL',
                            'interval' => $baseInterval,
                        ]);
                    } else {
                        SubscriptionPlan::create([
                            'product_id' => $produto->id,
                            'name' => $planName,
                            'price' => $produto->price,
                            'currency' => $produto->currency ?? 'BRL',
                            'interval' => $baseInterval,
                            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
                            'position' => 0,
                        ]);
                    }
                }

                if ($request->has('conversion_pixels')) {
                    $merged = [];
                    foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $key) {
                        $raw = is_array($conversionPixels[$key] ?? null) ? $conversionPixels[$key] : [];
                        $merged[$key] = Product::normalizeConversionPixelBlock($raw, $key);
                    }
                    $merged['custom_script'] = [];
                    foreach ($conversionPixels['custom_script'] ?? [] as $item) {
                        if (! empty($item['script'] ?? '')) {
                            $merged['custom_script'][] = [
                                'id' => $item['id'] ?? Str::uuid()->toString(),
                                'name' => $item['name'] ?? '',
                                'script' => $item['script'],
                            ];
                        }
                    }
                    $produto->update(['conversion_pixels' => $merged]);
                }

                $config = $produto->checkout_config ?? [];
                $configUpdated = false;
                if (array_key_exists('payment_gateways', $config)) {
                    unset($config['payment_gateways']);
                    $configUpdated = true;
                }
                if ($request->has('deliverable_link')) {
                    $config['deliverable_link'] = $deliverableLink ?? '';
                    $configUpdated = true;
                }
                $billingType = $validated['billing_type'] ?? $produto->billing_type;
                $normalizedInstallments = PlatformCardInstallments::normalizeSellerInput(
                    is_array($cardInstallments) ? $cardInstallments : null,
                    (string) $billingType
                );
                if ($normalizedInstallments !== null) {
                    $config['card_installments'] = $normalizedInstallments;
                    $configUpdated = true;
                }
                if (is_array($paymentMethodsPm) && $paymentMethodsPm['pm'] !== null) {
                    $config['payment_methods_enabled'] = $paymentMethodsPm['pm'];
                    $configUpdated = true;
                }
                if (is_array($emailTemplate)) {
                    $config['email_template'] = array_merge(
                        Product::defaultEmailTemplate(),
                        $emailTemplate
                    );
                    $configUpdated = true;
                }
                if ($configUpdated) {
                    $produto->update(['checkout_config' => $config]);
                }

                if ($request->hasFile('image')) {
                    $storage = app(StorageService::class);
                    if ($oldImage && $storage->exists($oldImage)) {
                        $storage->delete($oldImage);
                    }
                    $path = $storage->putFile('products', $request->file('image'));
                    $produto->update(['image' => $path]);
                }
            });
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['image' => $e->getMessage()])
                ->with('error', $e->getMessage())
                ->withInput();
        }

        event(new ProductUpdated($produto));

        $url = route('produtos.edit', $produto);
        $tab = $request->query('tab');
        if ($tab) {
            $url .= '?tab='.urlencode($tab);
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_UPDATED, $produto, [
            'name' => $produto->name,
        ]);

        return redirect($url)->with('success', 'Produto atualizado.');
    }

    public function destroy(Product $produto)
    {
        $this->authorizeProduct($produto);
        event(new ProductDeleted($produto));
        $storage = app(StorageService::class);
        if ($produto->image && $storage->exists($produto->image)) {
            $storage->delete($produto->image);
        }
        $produto->delete();

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_DELETED, $produto, [
            'name' => $produto->name,
        ]);

        return redirect()->route('produtos.index')->with('success', 'Produto removido.');
    }

    public function duplicate(Product $produto)
    {
        $this->authorizeProduct($produto);
        $tenantId = auth()->user()->tenant_id;
        $baseName = $produto->name.' (cópia)';
        $uniqueSlug = Product::uniqueSlugForTenant($tenantId, $baseName);

        $dupConfig = $produto->checkout_config ?? [];
        if (is_array($dupConfig) && array_key_exists('payment_gateways', $dupConfig)) {
            unset($dupConfig['payment_gateways']);
        }

        $newProduct = Product::create([
            'tenant_id' => $tenantId,
            'name' => $baseName,
            'slug' => $uniqueSlug,
            'description' => $produto->description,
            'category' => $produto->category,
            'type' => $produto->type,
            'billing_type' => $produto->billing_type ?? Product::BILLING_ONE_TIME,
            'image' => null,
            'price' => $produto->price,
            'currency' => $produto->currency ?? config('products.currency_default', 'BRL'),
            'is_active' => $produto->is_active,
            'checkout_config' => $dupConfig,
            'refund_policy_days' => $produto->refund_policy_days !== null
                ? (int) $produto->refund_policy_days
                : 7,
        ]);

        $approvalService = app(ProductApprovalService::class);
        $approvalAttrs = $approvalService->attributesForNewProduct((bool) $newProduct->is_active);
        if ($approvalAttrs !== []) {
            $newProduct->forceFill($approvalAttrs)->save();
        }
        $approvalService->afterCreated($newProduct->fresh(), request());

        event(new ProductDuplicated($produto, $newProduct));

        $success = 'Produto duplicado.';
        if (($newProduct->fresh()->approval_status ?? null) === Product::APPROVAL_PENDING) {
            $success = 'Produto duplicado e enviado para análise.';
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_DUPLICATED, $newProduct, [
            'name' => $newProduct->name,
            'source_product_id' => $produto->id,
        ]);

        return redirect()->route('produtos.index')->with('success', $success);
    }

    public function resubmitForReview(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);

        try {
            app(ProductApprovalService::class)->resubmit($produto, $request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_RESUBMITTED, $produto, [
            'name' => $produto->name,
        ]);

        return back()->with('success', 'Produto reenviado para análise. Ele permanecerá indisponível para venda até nova aprovação.');
    }

    public function addAluno(Request $request, Product $produto, MemberAccessGrantService $memberAccessGrant)
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate(['email' => ['required', 'email', 'exists:users,email']]);
        $user = User::where('email', $validated['email'])->whereIn('role', User::buyerRoleValues())->firstOrFail();
        $memberAccessGrant->grant($user, $produto);

        $this->logSellerActivity(SellerActivityLogService::STUDENT_PRODUCT_ADDED, $produto, [
            'email' => $user->email,
            'name' => $produto->name,
        ]);

        return back()->with('success', 'Acesso concedido.');
    }

    public function uploadEmailTemplateLogo(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png'));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'png';
        }

        $storage = app(StorageService::class);
        $dir = 'email-templates/'.$produto->id;
        $disk = $storage->disk();
        if ($disk->exists($dir)) {
            foreach ($disk->files($dir) as $existing) {
                $storage->delete($existing);
            }
        }
        $filename = 'logo-'.strtolower((string) Str::ulid()).'.'.$ext;
        $path = $dir.'/'.$filename;
        $storage->putFileAs($dir, $file, $filename);
        $logoUrl = $storage->url($path);

        $config = $produto->checkout_config ?? [];
        $config['email_template'] = array_merge(
            Product::defaultEmailTemplate(),
            $config['email_template'] ?? [],
            ['logo_url' => $logoUrl]
        );
        $produto->update(['checkout_config' => $config]);

        return response()->json(['logo_url' => $logoUrl]);
    }

    public function storeOffer(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        if (($produto->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_ONE_TIME) {
            return back()->with('error', 'Ofertas são apenas para produtos com pagamento único.');
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => $this->platformPriceRules(),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
        ]);
        $validated['product_id'] = $produto->id;
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $validated['price'] = MoneyDecimal::toFloat($validated['price']);
        $maxPosition = $produto->offers()->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;
        $validated['checkout_slug'] = ProductOffer::generateUniqueCheckoutSlug();
        $validated['affiliate_share_enabled'] = true;
        ProductOffer::create($validated);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_OFFER_CREATED, $produto, [
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Oferta adicionada.');
    }

    public function updateOffer(Request $request, Product $produto, ProductOffer $offer)
    {
        $this->authorizeProduct($produto);
        if ($offer->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => $this->platformPriceRules(),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
        ]);
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $validated['price'] = MoneyDecimal::toFloat($validated['price']);
        $offer->update($validated);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_OFFER_UPDATED, $offer, [
            'name' => $offer->name,
        ]);

        return back()->with('success', 'Oferta atualizada.');
    }

    public function destroyOffer(Product $produto, ProductOffer $offer)
    {
        $this->authorizeProduct($produto);
        if ($offer->product_id !== $produto->id) {
            abort(404);
        }
        $offer->delete();

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_OFFER_DELETED, $offer, [
            'name' => $offer->name,
        ]);

        return back()->with('success', 'Oferta removida.');
    }

    public function storeOrderBump(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'target_product_id' => ['required', 'string', 'exists:products,id'],
            'target_product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'cta_title' => ['required', 'string', 'max:255'],
        ]);
        $target = Product::forTenant($tenantId)->find($validated['target_product_id']);
        if (! $target || $target->id === $produto->id) {
            return back()->with('error', 'Produto inválido para order bump.');
        }
        if (! empty($validated['target_product_offer_id'])) {
            $offer = ProductOffer::where('id', $validated['target_product_offer_id'])->where('product_id', $target->id)->first();
            if (! $offer) {
                return back()->with('error', 'Oferta não pertence ao produto selecionado.');
            }
        }
        $validated['product_id'] = $produto->id;
        $validated['target_product_offer_id'] = $validated['target_product_offer_id'] ?? null;
        $validated['price_override'] = isset($validated['price_override'])
            ? MoneyDecimal::toFloat($validated['price_override'])
            : null;
        $maxPosition = $produto->orderBumps()->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;
        ProductOrderBump::create($validated);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_ORDER_BUMP_CREATED, $produto, [
            'name' => $validated['title'] ?? $produto->name,
        ]);

        return back()->with('success', 'Order bump adicionado.');
    }

    public function updateOrderBump(Request $request, Product $produto, ProductOrderBump $bump)
    {
        $this->authorizeProduct($produto);
        if ($bump->product_id !== $produto->id) {
            abort(404);
        }
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'target_product_id' => ['required', 'string', 'exists:products,id'],
            'target_product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'cta_title' => ['required', 'string', 'max:255'],
        ]);
        $target = Product::forTenant($tenantId)->find($validated['target_product_id']);
        if (! $target || $target->id === $produto->id) {
            return back()->with('error', 'Produto inválido para order bump.');
        }
        if (! empty($validated['target_product_offer_id'])) {
            $offer = ProductOffer::where('id', $validated['target_product_offer_id'])->where('product_id', $target->id)->first();
            if (! $offer) {
                return back()->with('error', 'Oferta não pertence ao produto selecionado.');
            }
        }
        $validated['target_product_offer_id'] = $validated['target_product_offer_id'] ?? null;
        $validated['price_override'] = isset($validated['price_override'])
            ? MoneyDecimal::toFloat($validated['price_override'])
            : null;
        $bump->update($validated);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_ORDER_BUMP_UPDATED, $bump, [
            'name' => $bump->title ?? $produto->name,
        ]);

        return back()->with('success', 'Order bump atualizado.');
    }

    public function destroyOrderBump(Product $produto, ProductOrderBump $bump)
    {
        $this->authorizeProduct($produto);
        if ($bump->product_id !== $produto->id) {
            abort(404);
        }
        $bump->delete();

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_ORDER_BUMP_DELETED, $bump, [
            'name' => $bump->title ?? $produto->name,
        ]);

        return back()->with('success', 'Order bump removido.');
    }

    public function storeSubscriptionPlan(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        if (($produto->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_SUBSCRIPTION) {
            return back()->with('error', 'Planos de assinatura são apenas para produtos do tipo assinatura.');
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => $this->platformPriceRules(),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'interval' => ['required', 'string', 'in:weekly,monthly,quarterly,semi_annual,annual,lifetime'],
        ]);
        $validated['product_id'] = $produto->id;
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $validated['price'] = MoneyDecimal::toFloat($validated['price']);
        $maxPosition = $produto->subscriptionPlans()->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;
        $validated['checkout_slug'] = SubscriptionPlan::generateUniqueCheckoutSlug();
        SubscriptionPlan::create($validated);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_PLAN_CREATED, $produto, [
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Plano adicionado.');
    }

    public function updateSubscriptionPlan(Request $request, Product $produto, SubscriptionPlan $plan)
    {
        $this->authorizeProduct($produto);
        if ($plan->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => $this->platformPriceRules(),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'interval' => ['required', 'string', 'in:weekly,monthly,quarterly,semi_annual,annual,lifetime'],
        ]);
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $validated['price'] = MoneyDecimal::toFloat($validated['price']);
        $plan->update($validated);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_PLAN_UPDATED, $plan, [
            'name' => $plan->name,
        ]);

        return back()->with('success', 'Plano atualizado.');
    }

    public function destroySubscriptionPlan(Product $produto, SubscriptionPlan $plan)
    {
        $this->authorizeProduct($produto);
        if ($plan->product_id !== $produto->id) {
            abort(404);
        }
        $plan->delete();

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_PLAN_DELETED, $plan, [
            'name' => $plan->name,
        ]);

        return back()->with('success', 'Plano removido.');
    }

    /**
     * @return array<string, mixed>
     */
    private function coproductionRowToArray(ProductCoproducer $row): array
    {
        $product = $row->product;
        $owner = $product && $product->tenant_id
            ? User::query()->find($product->tenant_id)
            : null;
        $imageUrl = $product && $product->image
            ? app(StorageService::class)->url($product->image)
            : null;

        return [
            'id' => $row->id,
            'token' => $row->token,
            'status' => $row->status,
            'commission_percent' => (float) $row->commission_percent,
            'commission_on_direct_sales' => $row->commission_on_direct_sales,
            'commission_on_affiliate_sales' => $row->commission_on_affiliate_sales,
            'duration_preset' => $row->duration_preset,
            'starts_at' => $row->starts_at?->toIso8601String(),
            'ends_at' => $row->ends_at?->toIso8601String(),
            'accepted_at' => $row->accepted_at?->toIso8601String(),
            'inviter_name' => $row->inviter?->name ?? '',
            'product' => [
                'id' => $product?->id,
                'name' => $product?->name ?? '—',
                'image_url' => $imageUrl,
                'checkout_slug' => $product?->checkout_slug,
                'owner_name' => $owner?->name ?? '',
            ],
        ];
    }

    private function authorizeProduct(Product $produto, bool $manage = true): void
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id;
        if ((int) $produto->tenant_id === (int) $tenantId) {
            if ($user->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
                if (! in_array($produto->id, $allowed, true)) {
                    abort(403);
                }
            }

            return;
        }

        if (! $manage && ProductCoproducer::isActiveCoproducerOf((int) $user->id, (string) $produto->id)) {
            return;
        }

        abort(403);
    }

    /**
     * @return array{brl_eur: float|string, brl_usd: float|string}
     */
    private function productRates(): array
    {
        return config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
    }

    private function makeUniqueProductSlug(int $tenantId, string $baseSlug): string
    {
        return Product::uniqueSlugForTenant($tenantId, $baseSlug !== '' ? $baseSlug : 'produto');
    }

    private function isDuplicateProductSlugException(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, '23505') || str_contains($message, 'unique violation')) {
            return str_contains($message, 'slug') || str_contains($message, 'products_tenant_id');
        }

        return str_contains($message, 'duplicate')
            && (str_contains($message, 'slug') || str_contains($message, 'products.tenant_id') || str_contains($message, 'products_tenant_id'));
    }

    private function productToArray(Product $p, array $rates): array
    {
        $currency = $p->currency ?? 'BRL';
        $priceBrl = MoneyDecimal::brlFromStorage((float) $p->price, (string) $currency, $rates);
        $priceEur = MoneyDecimal::toFloat(bcmul((string) $priceBrl, (string) ($rates['brl_eur'] ?? 0.16), 4));
        $priceUsd = MoneyDecimal::toFloat(bcmul((string) $priceBrl, (string) ($rates['brl_usd'] ?? 0.18), 4));

        $imageUrl = $p->image
            ? app(StorageService::class)->url($p->image)
            : null;

        $config = Product::typeConfig()[$p->type] ?? null;
        $typeLabel = $config['label'] ?? $p->type;

        $billingLabels = Product::billingTypeLabels();
        $billingType = $p->billing_type ?? Product::BILLING_ONE_TIME;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'notification_name' => $p->notification_name,
            'support_email' => $p->support_email,
            'slug' => $p->slug,
            'checkout_slug' => $p->checkout_slug,
            'description' => $p->description,
            'category' => $p->category,
            'category_label' => Product::categoryLabels()[$p->category] ?? null,
            'type' => $p->type,
            'type_label' => $typeLabel,
            'billing_type' => $billingType,
            'billing_type_label' => $billingLabels[$billingType] ?? $billingType,
            'image' => $p->image,
            'image_url' => $imageUrl,
            'price' => MoneyDecimal::toFloat($p->price),
            'currency' => $currency,
            'price_brl' => $priceBrl,
            'price_eur' => $priceEur,
            'price_usd' => $priceUsd,
            'is_active' => $p->is_active,
            'available_for_purchase' => $p->isAvailableForPurchase(),
            'approval' => app(ProductApprovalService::class)->sellerFacingStatus($p),
            'conversion_pixels' => $p->conversion_pixels,
            'shipping_store_id' => $p->shipping_store_id,
            'physical_config' => $p->physical_config ?? ['free_shipping' => false],
        ];
    }

    private function platformPriceRules(): array
    {
        $tenantId = (int) (auth()->user()?->tenant_id ?? 0);
        $min = app(MinimumChargeService::class)->platformMinimumBrlForTenant($tenantId > 0 ? $tenantId : null);

        return ['required', 'numeric', 'min:'.$min];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{pm: ?array<string, bool>, error: ?string}
     */
    private function resolveProductPaymentMethods(
        Request $request,
        Product $produto,
        array $validated
    ): array {
        $paymentService = app(PaymentService::class);
        $billingType = (string) ($validated['billing_type'] ?? $produto->billing_type ?? Product::BILLING_ONE_TIME);
        $basePlan = $billingType === Product::BILLING_SUBSCRIPTION
            ? $produto->subscriptionPlans()->orderBy('position')->first()
            : null;
        $global = $paymentService->globallyAvailablePaymentMethodKeys($produto, $basePlan);
        $pm = [
            'pix' => $request->boolean('payment_methods_enabled.pix', true),
            'card' => $request->boolean('payment_methods_enabled.card', true),
            'boleto' => $request->boolean('payment_methods_enabled.boleto', true),
            'pix_auto' => $billingType === Product::BILLING_SUBSCRIPTION
                ? $request->boolean('payment_methods_enabled.pix_auto', true)
                : false,
            'apple_pay' => $request->boolean('payment_methods_enabled.apple_pay', true),
            'google_pay' => $request->boolean('payment_methods_enabled.google_pay', true),
            'open_finance' => $request->boolean('payment_methods_enabled.open_finance', true),
            'paypal' => $request->boolean('payment_methods_enabled.paypal', true),
        ];
        $keysToCheck = ['pix', 'card', 'boleto', 'open_finance'];
        if ($billingType === Product::BILLING_SUBSCRIPTION && $basePlan) {
            $keysToCheck[] = 'pix_auto';
        }
        if (! empty($global['apple_pay'])) {
            $keysToCheck[] = 'apple_pay';
        }
        if (! empty($global['google_pay'])) {
            $keysToCheck[] = 'google_pay';
        }
        if (! empty($global['paypal'])) {
            $keysToCheck[] = 'paypal';
        }
        foreach (array_keys($pm) as $k) {
            if (empty($global[$k])) {
                $pm[$k] = false;
            }
        }
        $anyGlobal = false;
        foreach ($keysToCheck as $k) {
            if (! empty($global[$k])) {
                $anyGlobal = true;
                break;
            }
        }
        if ($anyGlobal) {
            $atLeastOne = false;
            foreach ($keysToCheck as $k) {
                if (! empty($global[$k]) && ($pm[$k] ?? false)) {
                    $atLeastOne = true;
                    break;
                }
            }
            if (! $atLeastOne) {
                return [
                    'pm' => null,
                    'error' => 'Ative pelo menos um método de pagamento disponível na plataforma.',
                ];
            }
        }

        return ['pm' => $pm, 'error' => null];
    }

    private function assertPhysicalProductRules(string $type, string $billingType): void
    {
        if ($type === Product::TYPE_PRODUTO_FISICO) {
            if (! PhysicalProductAccess::globalEnabled()) {
                abort(422, 'Produto físico não está habilitado na plataforma.');
            }
            if ($billingType === Product::BILLING_SUBSCRIPTION) {
                abort(422, 'Produto físico não pode ser vendido como assinatura.');
            }
        }
    }

    /**
     * Garante que o produto, uma oferta ou um plano tenha checkout_slug (gera se estiver vazio).
     * Redireciona de volta à edição do produto para recarregar os dados.
     */
    public function ensureCheckoutSlug(Request $request, Product $produto): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:main,offer,plan'],
            'offer_id' => ['required_if:type,offer', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,plan', 'nullable', 'integer'],
        ]);

        $type = $validated['type'];

        if ($type === 'main') {
            if (empty($produto->checkout_slug)) {
                $produto->checkout_slug = Product::generateUniqueCheckoutSlug();
                $produto->save();
            }

            $this->logSellerActivity(SellerActivityLogService::PRODUCT_CHECKOUT_SLUG_GENERATED, $produto, [
                'name' => $produto->name,
                'type' => 'main',
            ]);

            return redirect()->to(route('produtos.edit', $produto).'?tab=checkout')->with('success', 'Link do checkout (produto base) gerado.');
        }

        if ($type === 'offer') {
            $offer = ProductOffer::where('id', $validated['offer_id'])->where('product_id', $produto->id)->firstOrFail();
            if (empty($offer->checkout_slug)) {
                $offer->checkout_slug = ProductOffer::generateUniqueCheckoutSlug();
                $offer->save();
            }

            $this->logSellerActivity(SellerActivityLogService::PRODUCT_CHECKOUT_SLUG_GENERATED, $produto, [
                'name' => $produto->name,
                'type' => 'offer',
            ]);

            return redirect()->to(route('produtos.edit', $produto).'?tab=checkout')->with('success', 'Link do checkout da oferta gerado.');
        }

        $plan = SubscriptionPlan::where('id', $validated['plan_id'])->where('product_id', $produto->id)->firstOrFail();
        if (empty($plan->checkout_slug)) {
            $plan->checkout_slug = SubscriptionPlan::generateUniqueCheckoutSlug();
            $plan->save();
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_CHECKOUT_SLUG_GENERATED, $produto, [
            'name' => $produto->name,
            'type' => 'plan',
        ]);

        return redirect()->to(route('produtos.edit', $produto).'?tab=checkout')->with('success', 'Link do checkout do plano gerado.');
    }

    /**
     * Remove o checkout exclusivo de uma oferta ou plano (passa a usar o checkout principal).
     * Só aplica a ofertas e planos; o produto principal não pode ter slug removido.
     */
    public function removeCheckoutSlug(Request $request, Product $produto): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:offer,plan'],
            'offer_id' => ['required_if:type,offer', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,plan', 'nullable', 'integer'],
        ]);

        if ($validated['type'] === 'offer') {
            $offer = ProductOffer::where('id', $validated['offer_id'])->where('product_id', $produto->id)->firstOrFail();
            $offer->update(['checkout_slug' => null]);

            $this->logSellerActivity(SellerActivityLogService::PRODUCT_CHECKOUT_SLUG_REMOVED, $produto, [
                'name' => $produto->name,
                'type' => 'offer',
            ]);

            return redirect()->to(route('produtos.edit', $produto).'?tab=checkout')->with('success', 'Checkout exclusivo da oferta removido; ela passará a usar o checkout principal.');
        }

        $plan = SubscriptionPlan::where('id', $validated['plan_id'])->where('product_id', $produto->id)->firstOrFail();
        $plan->update(['checkout_slug' => null]);

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_CHECKOUT_SLUG_REMOVED, $produto, [
            'name' => $produto->name,
            'type' => 'plan',
        ]);

        return redirect()->to(route('produtos.edit', $produto).'?tab=checkout')->with('success', 'Checkout exclusivo do plano removido; ele passará a usar o checkout principal.');
    }

    /**
     * @return array{
     *     card_show_installments: bool,
     *     card_installments_gateway_name: string,
     *     digital_wallets_at_checkout: bool,
     *     platform_card_installments_enabled: bool,
     *     platform_card_installments_max: int
     * }
     */
    private function checkoutGatewayUiForTenant(mixed $tenantId): array
    {
        $platformEnabled = PlatformCardInstallments::globalEnabled();
        $platformMax = PlatformCardInstallments::maxAllowed();
        $tenantId = $tenantId !== null && $tenantId !== '' ? (int) $tenantId : null;
        if ($tenantId !== null && $tenantId < 1) {
            $tenantId = null;
        }
        if ($tenantId === null) {
            return [
                'card_show_installments' => false,
                'card_installments_gateway_name' => '',
                'digital_wallets_at_checkout' => false,
                'platform_card_installments_enabled' => $platformEnabled,
                'platform_card_installments_max' => $platformMax,
            ];
        }

        $paymentService = app(PaymentService::class);
        $cardOrder = $paymentService->getGatewayOrderForMethod($tenantId, 'card', null, null);
        $credentialBySlug = GatewayCredential::connectedMapForPayment($tenantId);
        $primaryConnectedCardSlug = null;
        foreach ($cardOrder as $slug) {
            if (! is_string($slug) || $slug === '' || ! $credentialBySlug->get($slug)) {
                continue;
            }
            $gw = GatewayRegistry::get($slug);
            if ($gw && in_array('card', $gw['methods'] ?? [], true)) {
                $primaryConnectedCardSlug = $slug;
                break;
            }
        }
        $connectedCardDef = is_string($primaryConnectedCardSlug)
            ? GatewayRegistry::get($primaryConnectedCardSlug)
            : null;

        return [
            'card_show_installments' => $platformEnabled && CardInstallments::gatewaySupports($primaryConnectedCardSlug),
            'card_installments_gateway_name' => is_array($connectedCardDef)
                ? (string) ($connectedCardDef['name'] ?? $primaryConnectedCardSlug)
                : '',
            'digital_wallets_at_checkout' => $primaryConnectedCardSlug === 'cajupay',
            'platform_card_installments_enabled' => $platformEnabled,
            'platform_card_installments_max' => $platformMax,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input
     */
    private function applyCardInstallmentsToProduct(Product $product, ?array $input): void
    {
        $normalized = PlatformCardInstallments::normalizeSellerInput($input, (string) $product->billing_type);
        if ($normalized === null) {
            return;
        }
        $config = $product->checkout_config ?? [];
        $config['card_installments'] = $normalized;
        $product->update(['checkout_config' => $config]);
    }
}
