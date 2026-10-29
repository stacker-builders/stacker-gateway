<?php

namespace App\Services\Mcp;

use App\Events\ProductBeforeSave;
use App\Events\ProductCreated;
use App\Events\ProductUpdated;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Services\MinimumChargeService;
use App\Services\PhysicalProductAccess;
use App\Services\ProductApprovalService;
use App\Services\SellerActivityLogService;
use App\Support\DemoMode;
use App\Support\HtmlSanitizer;
use App\Support\MoneyDecimal;
use App\Support\ProductApprovalSettings;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class McpCatalogService
{
    public const WRITE_TOOLS = [
        'catalog.create_product',
        'catalog.update_product',
        'catalog.create_offer',
        'catalog.update_offer',
        'catalog.create_coupon',
        'catalog.update_coupon',
        'catalog.create_order_bump',
        'catalog.update_order_bump',
        'catalog.set_upsell',
        'catalog.set_downsell',
        'catalog.apply_plan',
    ];

    private const ALLOWED_TYPES = [
        Product::TYPE_APLICATIVO,
        Product::TYPE_AREA_MEMBROS,
        Product::TYPE_AREA_MEMBROS_EXTERNA,
        Product::TYPE_LINK,
        Product::TYPE_LINK_PAGAMENTO,
    ];

    private const BILLING_TYPES = [
        Product::BILLING_ONE_TIME,
        Product::BILLING_SUBSCRIPTION,
    ];

    public function __construct(
        private readonly McpPreviewStore $previews,
        private readonly ProductApprovalService $approval,
        private readonly MinimumChargeService $minimumCharge,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $productId = ['type' => 'string', 'description' => 'ID do produto deste tenant.'];
        $price = ['type' => 'number', 'description' => 'Preço na moeda informada.'];
        $currency = ['type' => 'string', 'enum' => ['BRL', 'EUR', 'USD']];

        return [
            $this->tool('catalog.ping', 'Confirma que o MCP de catálogo está autenticado neste tenant. Não altera dados.', [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.list_products', 'Lista produtos do catálogo deste tenant (campos seguros, sem pixels nem gateways).', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Filtro por nome.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.get_product', 'Detalhe de um produto: ofertas, order bumps e resumo de upsell/downsell. Não devolve checkout_config completo.', [
                'type' => 'object',
                'properties' => ['product_id' => $productId],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.preview_plan', 'Valida um lote de operações e devolve um preview_id assinado. Não grava. Operações com risco listam gates humanos.', [
                'type' => 'object',
                'properties' => [
                    'operations' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 20,
                        'items' => ['type' => 'object'],
                    ],
                ],
                'required' => ['operations'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.apply_plan', 'Aplica um preview_id previamente emitido. Única forma de gravar operações que exigem confirmação humana. Use idempotency_key para reexecutar com segurança.', [
                'type' => 'object',
                'properties' => [
                    'preview_id' => ['type' => 'string'],
                    'idempotency_key' => ['type' => 'string', 'maxLength' => 128],
                ],
                'required' => ['preview_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.create_product', 'Cria produto neste tenant. Se a aprovação automática estiver ligada ou o preço for baixo, devolve preview_id em vez de gravar.', [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'price' => $price,
                    'currency' => $currency,
                    'type' => ['type' => 'string', 'enum' => self::ALLOWED_TYPES],
                    'billing_type' => ['type' => 'string', 'enum' => self::BILLING_TYPES],
                    'description' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'notification_name' => ['type' => 'string'],
                    'support_email' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                ],
                'required' => ['name', 'price'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.update_product', 'Atualiza nome, descrição, categoria, preço ou status de um produto deste tenant. Mudança de preço em produto aprovado exige preview.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'name' => ['type' => 'string'],
                    'price' => $price,
                    'currency' => $currency,
                    'description' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'notification_name' => ['type' => 'string'],
                    'support_email' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                ],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.create_offer', 'Cria oferta (preço alternativo) em produto de pagamento único deste tenant.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'name' => ['type' => 'string'],
                    'price' => $price,
                    'currency' => $currency,
                ],
                'required' => ['product_id', 'name', 'price'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.update_offer', 'Atualiza nome ou preço de uma oferta deste tenant.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'offer_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'price' => $price,
                    'currency' => $currency,
                ],
                'required' => ['product_id', 'offer_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.create_coupon', 'Cria cupom neste tenant. Percentual alto, sem teto de usos ou sem produtos ligados exige preview.', [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => [Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED]],
                    'value' => ['type' => 'number'],
                    'product_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'max_uses' => ['type' => 'integer', 'minimum' => 1],
                    'min_amount' => ['type' => 'number'],
                    'valid_from' => ['type' => 'string'],
                    'valid_until' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                ],
                'required' => ['code', 'type', 'value'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.update_coupon', 'Atualiza cupom deste tenant. As mesmas regras de risco do create se aplicam.', [
                'type' => 'object',
                'properties' => [
                    'coupon_id' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => [Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED]],
                    'value' => ['type' => 'number'],
                    'product_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'max_uses' => ['type' => 'integer', 'minimum' => 1],
                    'min_amount' => ['type' => 'number'],
                    'valid_from' => ['type' => 'string'],
                    'valid_until' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                ],
                'required' => ['coupon_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.create_order_bump', 'Cria order bump no checkout de um produto, apontando para outro produto deste tenant.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'target_product_id' => $productId,
                    'target_product_offer_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'cta_title' => ['type' => 'string'],
                    'price_override' => ['type' => 'number'],
                ],
                'required' => ['product_id', 'target_product_id', 'title', 'cta_title'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.update_order_bump', 'Atualiza um order bump deste tenant.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'bump_id' => ['type' => 'integer'],
                    'target_product_id' => $productId,
                    'target_product_offer_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'cta_title' => ['type' => 'string'],
                    'price_override' => ['type' => 'number'],
                ],
                'required' => ['product_id', 'bump_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.set_upsell', 'Define o funil de upsell do produto (somente chaves de upsell). Ativar exige preview.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'enabled' => ['type' => 'boolean'],
                    'products' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'product_id' => $productId,
                                'product_offer_id' => ['type' => 'integer'],
                                'title_override' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'image_url' => ['type' => 'string'],
                                'video_url' => ['type' => 'string'],
                            ],
                            'required' => ['product_id'],
                        ],
                    ],
                    'headline' => ['type' => 'string'],
                    'subheadline' => ['type' => 'string'],
                    'button_accept' => ['type' => 'string'],
                    'button_decline' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'enabled'],
                'additionalProperties' => false,
            ]),
            $this->tool('catalog.set_downsell', 'Define o downsell do produto. Só pode ficar ativo se o upsell já estiver ativo. Ativar exige preview.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'enabled' => ['type' => 'boolean'],
                    'offer_product_id' => $productId,
                    'product_offer_id' => ['type' => 'integer'],
                    'headline' => ['type' => 'string'],
                    'subheadline' => ['type' => 'string'],
                    'button_accept' => ['type' => 'string'],
                    'button_decline' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'enabled'],
                'additionalProperties' => false,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, int $tenantId): array
    {
        if (in_array($name, self::WRITE_TOOLS, true) && DemoMode::isEnabled()) {
            throw new McpCatalogException('Escrita MCP bloqueada no modo demonstração.', 'demo');
        }

        return match ($name) {
            'catalog.ping' => $this->ping($tenantId),
            'catalog.list_products' => $this->listProducts($tenantId, $arguments),
            'catalog.get_product' => $this->getProduct($tenantId, $arguments),
            'catalog.preview_plan' => $this->previewPlan($tenantId, $arguments),
            'catalog.apply_plan' => $this->applyPlan($tenantId, $arguments),
            'catalog.create_product' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_product'])),
            'catalog.update_product' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_product'])),
            'catalog.create_offer' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_offer'])),
            'catalog.update_offer' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_offer'])),
            'catalog.create_coupon' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_coupon'])),
            'catalog.update_coupon' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_coupon'])),
            'catalog.create_order_bump' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_order_bump'])),
            'catalog.update_order_bump' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_order_bump'])),
            'catalog.set_upsell' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'set_upsell'])),
            'catalog.set_downsell' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'set_downsell'])),
            default => throw new McpCatalogException('Ferramenta indisponível neste servidor de catálogo.', 'unknown_tool'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(int $tenantId): array
    {
        return [
            'ok' => true,
            'tenant_id' => $tenantId,
            'message' => 'MCP autenticado. Catálogo, área de membros, alunos, leitura de vendas e saldo disponível estão neste servidor. Escritas de catálogo com risco exigem catalog.preview_plan e catalog.apply_plan. Exclusão, revogação e e-mail de acesso exigem members.apply_plan. Reembolso, cobrança e saque não existem neste servidor.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function listProducts(int $tenantId, array $arguments): array
    {
        $query = isset($arguments['query']) ? HtmlSanitizer::plainText((string) $arguments['query'], 80) : '';
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $builder = Product::query()->forTenant($tenantId)->orderBy('name');
        if ($query !== '') {
            $builder->where('name', 'like', '%'.$query.'%');
        }

        $total = (clone $builder)->count();
        $items = $builder->offset($offset)->limit($limit)->get()->map(
            fn (Product $product) => $this->productSummary($product)
        )->values()->all();

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'products' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getProduct(int $tenantId, array $arguments): array
    {
        $product = $this->productOfTenant($tenantId, (string) ($arguments['product_id'] ?? ''));
        $product->load(['offers', 'orderBumps']);

        return $this->productDetail($product);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function previewPlan(int $tenantId, array $arguments): array
    {
        $operations = $arguments['operations'] ?? null;
        if (! is_array($operations) || $operations === [] || ! array_is_list($operations)) {
            throw new McpCatalogException('Informe uma lista de operações em operations.', 'validation');
        }
        if (count($operations) > 20) {
            throw new McpCatalogException('O plano aceita no máximo 20 operações.', 'validation');
        }

        $inspected = [];
        $gates = [];
        $invalid = false;
        foreach ($operations as $index => $raw) {
            if (! is_array($raw)) {
                throw new McpCatalogException('Cada item de operations precisa ser um objeto.', 'validation');
            }
            $item = $this->inspect($tenantId, $raw);
            $item['index'] = $index;
            $inspected[] = $item;
            foreach ($item['gates'] as $gate) {
                $gates[] = array_merge($gate, ['index' => $index, 'op' => $item['op']]);
            }
            if ($item['status'] === 'invalid') {
                $invalid = true;
            }
        }

        if ($invalid) {
            return [
                'ok' => false,
                'preview_id' => null,
                'message' => 'Há operações inválidas. Corrija antes de gerar o preview.',
                'operations' => $this->publicInspected($inspected),
                'gates' => $gates,
            ];
        }

        $stored = $this->previews->put($tenantId, $inspected, $gates);
        $needsConfirm = $gates !== [];

        return [
            'ok' => true,
            'preview_id' => $stored['preview_id'],
            'expires_at' => $stored['expires_at'],
            'needs_confirmation' => $needsConfirm,
            'message' => $needsConfirm
                ? 'Mostre este plano ao vendedor. Só grave com catalog.apply_plan usando o preview_id.'
                : 'Plano validado. Use catalog.apply_plan com este preview_id para gravar.',
            'operations' => $this->publicInspected($inspected),
            'gates' => $gates,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function applyPlan(int $tenantId, array $arguments): array
    {
        $previewId = trim((string) ($arguments['preview_id'] ?? ''));
        $idempotencyKey = trim((string) ($arguments['idempotency_key'] ?? ''));

        if ($idempotencyKey !== '') {
            $cached = $this->previews->getIdempotency($tenantId, $idempotencyKey);
            if ($cached !== null) {
                return array_merge($cached, ['idempotent_replay' => true]);
            }
        }

        $preview = $this->previews->get($tenantId, $previewId);
        if ($preview === null) {
            throw new McpCatalogException('Preview inválido, expirado ou de outra conta.', 'preview_expired');
        }

        if (! empty($preview['consumed_at'])) {
            $previous = is_array($preview['result'] ?? null) ? $preview['result'] : ['ok' => true];

            return array_merge($previous, [
                'already_applied' => true,
                'applied_at' => $preview['consumed_at'],
            ]);
        }

        $operations = is_array($preview['operations'] ?? null) ? $preview['operations'] : [];
        $results = [];
        DB::transaction(function () use ($tenantId, $operations, &$results): void {
            foreach ($operations as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $results[] = $this->execute($tenantId, $item);
            }
        });

        $payload = [
            'ok' => true,
            'preview_id' => $previewId,
            'results' => $results,
        ];
        $this->previews->markConsumed($tenantId, $previewId, $payload);
        if ($idempotencyKey !== '') {
            $this->previews->putIdempotency($tenantId, $idempotencyKey, $payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function dispatchWrite(int $tenantId, array $raw): array
    {
        $inspected = $this->inspect($tenantId, $raw);
        if ($inspected['status'] === 'invalid') {
            throw new McpCatalogException((string) $inspected['error'], 'validation', [
                'op' => $inspected['op'],
                'gates' => $inspected['gates'],
            ]);
        }

        if ($inspected['status'] === 'needs_confirmation') {
            $stored = $this->previews->put($tenantId, [$inspected], $inspected['gates']);
            throw new McpCatalogException(
                'Esta alteração exige confirmação humana. Mostre o plano ao vendedor e chame catalog.apply_plan com o preview_id.',
                'needs_confirmation',
                [
                    'preview_id' => $stored['preview_id'],
                    'expires_at' => $stored['expires_at'],
                    'summary' => $inspected['summary'],
                    'gates' => $inspected['gates'],
                    'operation' => $this->publicInspected([$inspected])[0],
                ]
            );
        }

        return $this->execute($tenantId, $inspected);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{op: string, status: string, gates: list<array<string, mixed>>, error: ?string, summary: string, normalized: array<string, mixed>}
     */
    private function inspect(int $tenantId, array $raw): array
    {
        $op = trim((string) ($raw['op'] ?? ''));

        return match ($op) {
            'create_product' => $this->inspectCreateProduct($tenantId, $raw),
            'update_product' => $this->inspectUpdateProduct($tenantId, $raw),
            'create_offer' => $this->inspectCreateOffer($tenantId, $raw),
            'update_offer' => $this->inspectUpdateOffer($tenantId, $raw),
            'create_coupon' => $this->inspectCreateCoupon($tenantId, $raw),
            'update_coupon' => $this->inspectUpdateCoupon($tenantId, $raw),
            'create_order_bump' => $this->inspectCreateOrderBump($tenantId, $raw),
            'update_order_bump' => $this->inspectUpdateOrderBump($tenantId, $raw),
            'set_upsell' => $this->inspectSetUpsell($tenantId, $raw),
            'set_downsell' => $this->inspectSetDownsell($tenantId, $raw),
            default => $this->inspected($op !== '' ? $op : 'unknown', 'invalid', 'Operação não suportada: '.($op !== '' ? $op : '(vazia)').'.'),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function execute(int $tenantId, array $item): array
    {
        $op = (string) ($item['op'] ?? '');
        $normalized = is_array($item['normalized'] ?? null) ? $item['normalized'] : [];

        return match ($op) {
            'create_product' => $this->executeCreateProduct($tenantId, $normalized),
            'update_product' => $this->executeUpdateProduct($tenantId, $normalized),
            'create_offer' => $this->executeCreateOffer($tenantId, $normalized),
            'update_offer' => $this->executeUpdateOffer($tenantId, $normalized),
            'create_coupon' => $this->executeCreateCoupon($tenantId, $normalized),
            'update_coupon' => $this->executeUpdateCoupon($tenantId, $normalized),
            'create_order_bump' => $this->executeCreateOrderBump($tenantId, $normalized),
            'update_order_bump' => $this->executeUpdateOrderBump($tenantId, $normalized),
            'set_upsell' => $this->executeSetUpsell($tenantId, $normalized),
            'set_downsell' => $this->executeSetDownsell($tenantId, $normalized),
            default => throw new McpCatalogException('Operação não suportada no apply.', 'validation'),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateProduct(int $tenantId, array $raw): array
    {
        try {
            $data = $this->validatedProductPayload($tenantId, $raw, creating: true);
        } catch (McpCatalogException $e) {
            return $this->inspected('create_product', 'invalid', $e->getMessage());
        }

        $gates = [];
        if (ProductApprovalSettings::autoApproveEnabled()) {
            $gates[] = [
                'code' => 'auto_approve',
                'message' => 'A aprovação automática está ligada: o produto pode ir ao ar sem fila de análise.',
            ];
        }
        if ($data['price_brl'] < 1) {
            $gates[] = [
                'code' => 'low_price',
                'message' => 'Preço abaixo de R$ 1,00.',
            ];
        }

        return $this->inspected(
            'create_product',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            'Criar produto "'.$data['name'].'" a '.$data['currency'].' '.$data['price'],
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateProduct(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            $data = $this->validatedProductPayload($tenantId, $raw, creating: false, existing: $product);
        } catch (McpCatalogException $e) {
            return $this->inspected('update_product', 'invalid', $e->getMessage());
        }

        $gates = [];
        $priceChanging = array_key_exists('price', $data)
            && MoneyDecimal::toFloat($product->price) !== MoneyDecimal::toFloat($data['price']);
        $approved = ($product->approval_status ?? Product::APPROVAL_APPROVED) === Product::APPROVAL_APPROVED;
        if ($priceChanging && $approved) {
            $gates[] = [
                'code' => 'price_change',
                'message' => 'Alterar preço de produto aprovado.',
            ];
        }
        if (isset($data['price_brl']) && $data['price_brl'] < 1) {
            $gates[] = [
                'code' => 'low_price',
                'message' => 'Preço abaixo de R$ 1,00.',
            ];
        }

        return $this->inspected(
            'update_product',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            'Atualizar produto "'.$product->name.'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateProduct(int $tenantId, array $data): array
    {
        $attrs = [
            'id' => $this->newProductId(),
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'notification_name' => $data['notification_name'],
            'support_email' => $data['support_email'],
            'description' => $data['description'],
            'category' => $data['category'],
            'type' => $data['type'],
            'billing_type' => $data['billing_type'],
            'price' => $data['price'],
            'currency' => $data['currency'],
            'is_active' => $data['is_active'],
            'slug' => Product::uniqueSlugForTenant($tenantId, Str::slug($data['name']) ?: 'produto'),
            'refund_policy_days' => 7,
        ];
        $approvalAttrs = $this->approval->attributesForNewProduct((bool) $attrs['is_active']);
        if ($approvalAttrs !== []) {
            $attrs = array_merge($attrs, $approvalAttrs);
        }

        $product = new Product($attrs);
        $before = new ProductBeforeSave($product, $attrs, true);
        event($before);
        if ($before->abort !== null) {
            throw new McpCatalogException($before->abort, 'validation');
        }

        $product = Product::query()->create($attrs);
        event(new ProductCreated($product));
        $this->approval->afterCreated($product);
        $this->log(SellerActivityLogService::PRODUCT_CREATED, $tenantId, $product, ['name' => $product->name]);

        return [
            'op' => 'create_product',
            'product' => $this->productSummary($product->fresh() ?? $product),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateProduct(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $attrs = [];
        foreach (['name', 'notification_name', 'support_email', 'description', 'category', 'price', 'currency', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }

        $before = new ProductBeforeSave($product, $attrs, false);
        event($before);
        if ($before->abort !== null) {
            throw new McpCatalogException($before->abort, 'validation');
        }

        if ($attrs !== []) {
            $product->update($attrs);
        }
        event(new ProductUpdated($product));
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name]);

        return [
            'op' => 'update_product',
            'product' => $this->productSummary($product->fresh() ?? $product),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateOffer(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            if (($product->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_ONE_TIME) {
                throw new McpCatalogException('Ofertas são apenas para produtos com pagamento único.', 'validation');
            }
            $data = $this->validatedOfferPayload($tenantId, $raw, $product);
            $data['product_id'] = (string) $product->id;
        } catch (McpCatalogException $e) {
            return $this->inspected('create_offer', 'invalid', $e->getMessage());
        }

        $gates = [];
        if ($data['price_brl'] < 1) {
            $gates[] = ['code' => 'low_price', 'message' => 'Preço da oferta abaixo de R$ 1,00.'];
        }

        return $this->inspected(
            'create_offer',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            'Criar oferta "'.$data['name'].'" no produto "'.$product->name.'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateOffer(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            $offer = $this->offerOfProduct($product, (int) ($raw['offer_id'] ?? 0));
            $data = $this->validatedOfferPayload($tenantId, $raw, $product, required: false);
            $data['product_id'] = (string) $product->id;
            $data['offer_id'] = $offer->id;
        } catch (McpCatalogException $e) {
            return $this->inspected('update_offer', 'invalid', $e->getMessage());
        }

        $gates = [];
        if (isset($data['price_brl']) && $data['price_brl'] < 1) {
            $gates[] = ['code' => 'low_price', 'message' => 'Preço da oferta abaixo de R$ 1,00.'];
        }

        return $this->inspected(
            'update_offer',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            'Atualizar oferta "'.$offer->name.'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateOffer(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $offer = ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => $data['name'],
            'price' => $data['price'],
            'currency' => $data['currency'],
            'position' => ((int) $product->offers()->max('position')) + 1,
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
            'affiliate_share_enabled' => true,
        ]);
        $this->log(SellerActivityLogService::PRODUCT_OFFER_CREATED, $tenantId, $product, ['name' => $offer->name]);

        return ['op' => 'create_offer', 'offer' => $this->offerSummary($offer)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateOffer(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $offer = $this->offerOfProduct($product, (int) $data['offer_id']);
        $attrs = [];
        foreach (['name', 'price', 'currency'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $offer->update($attrs);
        }
        $this->log(SellerActivityLogService::PRODUCT_OFFER_UPDATED, $tenantId, $offer, ['name' => $offer->name]);

        return ['op' => 'update_offer', 'offer' => $this->offerSummary($offer->fresh() ?? $offer)];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateCoupon(int $tenantId, array $raw): array
    {
        try {
            $data = $this->validatedCouponPayload($tenantId, $raw, creating: true);
        } catch (McpCatalogException $e) {
            return $this->inspected('create_coupon', 'invalid', $e->getMessage());
        }

        return $this->inspected(
            'create_coupon',
            $data['gates'] === [] ? 'ok' : 'needs_confirmation',
            null,
            $data['gates'],
            'Criar cupom '.$data['code'],
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateCoupon(int $tenantId, array $raw): array
    {
        try {
            $coupon = $this->couponOfTenant($tenantId, (int) ($raw['coupon_id'] ?? 0));
            $data = $this->validatedCouponPayload($tenantId, $raw, creating: false, existing: $coupon);
            $data['coupon_id'] = $coupon->id;
        } catch (McpCatalogException $e) {
            return $this->inspected('update_coupon', 'invalid', $e->getMessage());
        }

        return $this->inspected(
            'update_coupon',
            $data['gates'] === [] ? 'ok' : 'needs_confirmation',
            null,
            $data['gates'],
            'Atualizar cupom '.$data['code'],
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateCoupon(int $tenantId, array $data): array
    {
        $coupon = Coupon::query()->create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'type' => $data['type'],
            'value' => $data['value'],
            'min_amount' => $data['min_amount'],
            'max_uses' => $data['max_uses'],
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
            'is_active' => $data['is_active'],
        ]);
        $coupon->products()->sync($data['product_ids']);
        $this->log(SellerActivityLogService::COUPON_CREATED, $tenantId, $coupon, [
            'name' => $coupon->code,
            'type' => $coupon->type,
        ]);

        return ['op' => 'create_coupon', 'coupon' => $this->couponSummary($coupon->fresh(['products']) ?? $coupon)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateCoupon(int $tenantId, array $data): array
    {
        $coupon = $this->couponOfTenant($tenantId, (int) $data['coupon_id']);
        $attrs = [];
        foreach (['code', 'type', 'value', 'min_amount', 'max_uses', 'valid_from', 'valid_until', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $coupon->update($attrs);
        }
        if (array_key_exists('product_ids', $data)) {
            $coupon->products()->sync($data['product_ids']);
        }
        $this->log(SellerActivityLogService::COUPON_UPDATED, $tenantId, $coupon, [
            'name' => $coupon->code,
            'type' => $coupon->type,
        ]);

        return ['op' => 'update_coupon', 'coupon' => $this->couponSummary($coupon->fresh(['products']) ?? $coupon)];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateOrderBump(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            $data = $this->validatedBumpPayload($tenantId, $raw, $product, creating: true);
        } catch (McpCatalogException $e) {
            return $this->inspected('create_order_bump', 'invalid', $e->getMessage());
        }

        return $this->inspected(
            'create_order_bump',
            'ok',
            null,
            [],
            'Criar order bump "'.$data['title'].'" em "'.$product->name.'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateOrderBump(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            $bump = $this->bumpOfProduct($product, (int) ($raw['bump_id'] ?? 0));
            $data = $this->validatedBumpPayload($tenantId, $raw, $product, creating: false);
            $data['bump_id'] = $bump->id;
        } catch (McpCatalogException $e) {
            return $this->inspected('update_order_bump', 'invalid', $e->getMessage());
        }

        return $this->inspected(
            'update_order_bump',
            'ok',
            null,
            [],
            'Atualizar order bump "'.($data['title'] ?? $bump->title).'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateOrderBump(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $bump = ProductOrderBump::query()->create([
            'product_id' => $product->id,
            'target_product_id' => $data['target_product_id'],
            'target_product_offer_id' => $data['target_product_offer_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'cta_title' => $data['cta_title'],
            'price_override' => $data['price_override'],
            'position' => ((int) $product->orderBumps()->max('position')) + 1,
        ]);
        $this->log(SellerActivityLogService::PRODUCT_ORDER_BUMP_CREATED, $tenantId, $product, [
            'name' => $bump->title,
        ]);

        return ['op' => 'create_order_bump', 'order_bump' => $this->bumpSummary($bump)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateOrderBump(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $bump = $this->bumpOfProduct($product, (int) $data['bump_id']);
        $attrs = [];
        foreach (['target_product_id', 'target_product_offer_id', 'title', 'description', 'cta_title', 'price_override'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $bump->update($attrs);
        }
        $this->log(SellerActivityLogService::PRODUCT_ORDER_BUMP_UPDATED, $tenantId, $bump, [
            'name' => $bump->title,
        ]);

        return ['op' => 'update_order_bump', 'order_bump' => $this->bumpSummary($bump->fresh() ?? $bump)];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectSetUpsell(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            $data = $this->validatedUpsellPayload($tenantId, $raw, $product);
        } catch (McpCatalogException $e) {
            return $this->inspected('set_upsell', 'invalid', $e->getMessage());
        }

        $gates = [];
        if ($data['enabled'] === true) {
            $gates[] = [
                'code' => 'enable_upsell',
                'message' => 'Ativar upsell no checkout de "'.$product->name.'".',
            ];
        }

        return $this->inspected(
            'set_upsell',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            ($data['enabled'] ? 'Ativar' : 'Desativar').' upsell em "'.$product->name.'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectSetDownsell(int $tenantId, array $raw): array
    {
        try {
            $product = $this->productOfTenant($tenantId, (string) ($raw['product_id'] ?? ''));
            $data = $this->validatedDownsellPayload($tenantId, $raw, $product);
        } catch (McpCatalogException $e) {
            return $this->inspected('set_downsell', 'invalid', $e->getMessage());
        }

        $gates = [];
        if ($data['enabled'] === true) {
            $gates[] = [
                'code' => 'enable_downsell',
                'message' => 'Ativar downsell no checkout de "'.$product->name.'".',
            ];
        }

        return $this->inspected(
            'set_downsell',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            ($data['enabled'] ? 'Ativar' : 'Desativar').' downsell em "'.$product->name.'"',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeSetUpsell(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $config = $this->storedCheckoutConfig($product);
        $upsell = array_replace_recursive(
            Product::defaultCheckoutConfig()['upsell'],
            is_array($config['upsell'] ?? null) ? $config['upsell'] : []
        );
        $upsell['enabled'] = $data['enabled'];
        $upsell['products'] = $data['products'];
        if (! isset($upsell['page']) || ! is_array($upsell['page'])) {
            $upsell['page'] = [];
        }
        if (! isset($upsell['appearance']) || ! is_array($upsell['appearance'])) {
            $upsell['appearance'] = [];
        }
        if ($data['headline'] !== null) {
            $upsell['page']['headline'] = $data['headline'];
            $upsell['appearance']['title'] = $data['headline'];
        }
        if ($data['subheadline'] !== null) {
            $upsell['page']['subheadline'] = $data['subheadline'];
            $upsell['appearance']['subtitle'] = $data['subheadline'];
        }
        if ($data['button_accept'] !== null) {
            $upsell['appearance']['button_accept'] = $data['button_accept'];
        }
        if ($data['button_decline'] !== null) {
            $upsell['appearance']['button_decline'] = $data['button_decline'];
        }
        $config['upsell'] = $upsell;
        if (! $data['enabled']) {
            if (! isset($config['downsell']) || ! is_array($config['downsell'])) {
                $config['downsell'] = [];
            }
            $config['downsell']['enabled'] = false;
        }
        $product->update(['checkout_config' => $config]);
        $this->log(SellerActivityLogService::PRODUCT_UPSELL_UPDATED, $tenantId, $product, [
            'name' => $product->name,
        ]);

        return [
            'op' => 'set_upsell',
            'product_id' => (string) $product->id,
            'upsell' => $this->funnelSummary($product->fresh() ?? $product)['upsell'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeSetDownsell(int $tenantId, array $data): array
    {
        $product = $this->productOfTenant($tenantId, (string) $data['product_id']);
        $config = $this->storedCheckoutConfig($product);
        $downsell = array_replace_recursive(
            Product::defaultCheckoutConfig()['downsell'],
            is_array($config['downsell'] ?? null) ? $config['downsell'] : []
        );
        $downsell['enabled'] = $data['enabled'];
        $downsell['product_id'] = $data['offer_product_id'];
        $downsell['product_offer_id'] = $data['product_offer_id'];
        if (! isset($downsell['page']) || ! is_array($downsell['page'])) {
            $downsell['page'] = [];
        }
        if (! isset($downsell['appearance']) || ! is_array($downsell['appearance'])) {
            $downsell['appearance'] = [];
        }
        if ($data['headline'] !== null) {
            $downsell['page']['headline'] = $data['headline'];
            $downsell['appearance']['title'] = $data['headline'];
        }
        if ($data['subheadline'] !== null) {
            $downsell['page']['subheadline'] = $data['subheadline'];
            $downsell['appearance']['subtitle'] = $data['subheadline'];
        }
        if ($data['button_accept'] !== null) {
            $downsell['appearance']['button_accept'] = $data['button_accept'];
        }
        if ($data['button_decline'] !== null) {
            $downsell['appearance']['button_decline'] = $data['button_decline'];
        }
        $config['downsell'] = $downsell;
        $product->update(['checkout_config' => $config]);
        $this->log(SellerActivityLogService::PRODUCT_DOWNSELL_UPDATED, $tenantId, $product, [
            'name' => $product->name,
        ]);

        return [
            'op' => 'set_downsell',
            'product_id' => (string) $product->id,
            'downsell' => $this->funnelSummary($product->fresh() ?? $product)['downsell'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validatedProductPayload(int $tenantId, array $raw, bool $creating, ?Product $existing = null): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'price' => array_merge([$creating ? 'required' : 'sometimes'], $this->priceRules($tenantId)),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'type' => [$creating ? 'nullable' : 'prohibited', 'string', 'in:'.implode(',', self::ALLOWED_TYPES)],
            'billing_type' => [$creating ? 'nullable' : 'prohibited', 'string', 'in:'.implode(',', self::BILLING_TYPES)],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'in:'.implode(',', Product::categoryKeys())],
            'notification_name' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
        $validated = $this->validate($raw, $rules);

        if (isset($validated['type']) && $validated['type'] === Product::TYPE_PRODUTO_FISICO) {
            throw new McpCatalogException('Produto físico não é suportado pelo MCP.', 'validation');
        }
        if (PhysicalProductAccess::globalEnabled() === false && ($validated['type'] ?? '') === Product::TYPE_PRODUTO_FISICO) {
            throw new McpCatalogException('Produto físico não está habilitado.', 'validation');
        }

        $out = [];
        if ($creating || array_key_exists('name', $validated)) {
            $name = HtmlSanitizer::plainText((string) ($validated['name'] ?? $existing?->name ?? ''), 255);
            if ($name === '') {
                throw new McpCatalogException('Nome do produto é obrigatório.', 'validation');
            }
            $out['name'] = $name;
        }
        if ($creating || array_key_exists('price', $validated)) {
            $currency = strtoupper((string) ($validated['currency'] ?? $existing?->currency ?? 'BRL'));
            if (! in_array($currency, ['BRL', 'EUR', 'USD'], true)) {
                $currency = 'BRL';
            }
            $price = MoneyDecimal::toFloat($validated['price']);
            $priceBrl = MoneyDecimal::brlFromStorage($price, $currency, $this->rates());
            $min = $this->minimumCharge->platformMinimumBrlForTenant($tenantId);
            if ($priceBrl < $min) {
                throw new McpCatalogException('Preço abaixo do mínimo da plataforma (R$ '.number_format($min, 2, ',', '.').').', 'validation');
            }
            $out['price'] = $price;
            $out['currency'] = $currency;
            $out['price_brl'] = $priceBrl;
        }
        if ($creating) {
            $out['type'] = (string) ($validated['type'] ?? Product::TYPE_AREA_MEMBROS);
            $out['billing_type'] = (string) ($validated['billing_type'] ?? Product::BILLING_ONE_TIME);
            if (! in_array($out['type'], self::ALLOWED_TYPES, true)) {
                throw new McpCatalogException('Tipo de produto não permitido no MCP.', 'validation');
            }
        }
        if ($creating || array_key_exists('description', $validated)) {
            $out['description'] = HtmlSanitizer::plainTextMultiline((string) ($validated['description'] ?? ''), 20000) ?: null;
        }
        if ($creating || array_key_exists('category', $validated)) {
            $out['category'] = $validated['category'] ?? null;
        }
        if ($creating || array_key_exists('notification_name', $validated)) {
            $nn = HtmlSanitizer::plainText((string) ($validated['notification_name'] ?? ''), 80);
            $out['notification_name'] = $nn !== '' ? $nn : null;
        }
        if ($creating || array_key_exists('support_email', $validated)) {
            $email = strtolower(trim((string) ($validated['support_email'] ?? '')));
            $out['support_email'] = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
        }
        if ($creating) {
            $out['is_active'] = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;
        } elseif (array_key_exists('is_active', $validated)) {
            if ($existing !== null && ($existing->approval_status ?? Product::APPROVAL_APPROVED) !== Product::APPROVAL_APPROVED) {
                throw new McpCatalogException('Só é possível ativar produto aprovado.', 'validation');
            }
            $out['is_active'] = (bool) $validated['is_active'];
        }
        if ($existing !== null) {
            $out['product_id'] = (string) $existing->id;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validatedOfferPayload(int $tenantId, array $raw, Product $product, bool $required = true): array
    {
        $validated = $this->validate($raw, [
            'name' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'price' => array_merge([$required ? 'required' : 'sometimes'], $this->priceRules($tenantId)),
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
        ]);
        $out = [];
        if (array_key_exists('name', $validated)) {
            $name = HtmlSanitizer::plainText((string) $validated['name'], 255);
            if ($name === '') {
                throw new McpCatalogException('Nome da oferta é obrigatório.', 'validation');
            }
            $out['name'] = $name;
        }
        if (array_key_exists('price', $validated)) {
            $currency = strtoupper((string) ($validated['currency'] ?? $product->currency ?? 'BRL'));
            $price = MoneyDecimal::toFloat($validated['price']);
            $priceBrl = MoneyDecimal::brlFromStorage($price, $currency, $this->rates());
            $min = $this->minimumCharge->platformMinimumBrlForTenant($tenantId);
            if ($priceBrl < $min) {
                throw new McpCatalogException('Preço abaixo do mínimo da plataforma.', 'validation');
            }
            $out['price'] = $price;
            $out['currency'] = $currency;
            $out['price_brl'] = $priceBrl;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validatedCouponPayload(int $tenantId, array $raw, bool $creating, ?Coupon $existing = null): array
    {
        $validated = $this->validate($raw, [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'type' => [$creating ? 'required' : 'sometimes', 'string', 'in:'.Coupon::TYPE_PERCENT.','.Coupon::TYPE_FIXED],
            'value' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $code = HtmlSanitizer::plainText((string) ($validated['code'] ?? $existing?->code ?? ''), 64);
        if ($code === '') {
            throw new McpCatalogException('Código do cupom é obrigatório.', 'validation');
        }

        $duplicate = Coupon::query()->forTenant($tenantId)
            ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->exists();
        if ($duplicate) {
            throw new McpCatalogException('Já existe um cupom com este código.', 'conflict');
        }

        $type = (string) ($validated['type'] ?? $existing?->type ?? Coupon::TYPE_PERCENT);
        $value = MoneyDecimal::toFloat($validated['value'] ?? $existing?->value ?? 0);
        if ($type === Coupon::TYPE_PERCENT && $value > 100) {
            throw new McpCatalogException('Percentual do cupom não pode passar de 100.', 'validation');
        }

        $productIds = [];
        if (array_key_exists('product_ids', $validated) || $creating) {
            $requested = array_values(array_filter(array_map('strval', $validated['product_ids'] ?? [])));
            foreach ($requested as $productId) {
                $this->productOfTenant($tenantId, $productId);
                $productIds[] = $productId;
            }
            $productIds = array_values(array_unique($productIds));
        } else {
            $productIds = $existing?->products()->pluck('products.id')->map(fn ($id) => (string) $id)->all() ?? [];
        }

        $maxUses = array_key_exists('max_uses', $validated)
            ? ($validated['max_uses'] !== null ? (int) $validated['max_uses'] : null)
            : ($creating ? null : $existing?->max_uses);

        $gates = [];
        if ($type === Coupon::TYPE_PERCENT && $value >= 50) {
            $gates[] = ['code' => 'high_percent', 'message' => 'Cupom de '.$value.'% (50% ou mais).'];
        }
        if ($maxUses === null) {
            $gates[] = ['code' => 'unlimited_uses', 'message' => 'Cupom sem limite de usos.'];
        }
        if ($productIds === []) {
            $gates[] = ['code' => 'global_coupon', 'message' => 'Cupom sem produtos ligados vale para o catálogo inteiro.'];
        }

        return [
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'product_ids' => $productIds,
            'min_amount' => array_key_exists('min_amount', $validated)
                ? ($validated['min_amount'] !== null ? MoneyDecimal::toFloat($validated['min_amount']) : null)
                : ($creating ? null : ($existing?->min_amount !== null ? (float) $existing->min_amount : null)),
            'max_uses' => $maxUses,
            'valid_from' => $validated['valid_from'] ?? ($creating ? null : $existing?->valid_from),
            'valid_until' => $validated['valid_until'] ?? ($creating ? null : $existing?->valid_until),
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : ($creating ? true : (bool) $existing?->is_active),
            'gates' => $gates,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validatedBumpPayload(int $tenantId, array $raw, Product $product, bool $creating): array
    {
        $validated = $this->validate($raw, [
            'target_product_id' => [$creating ? 'required' : 'sometimes', 'string'],
            'target_product_offer_id' => ['nullable', 'integer'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'cta_title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        $out = ['product_id' => (string) $product->id];
        if (array_key_exists('target_product_id', $validated) || $creating) {
            $target = $this->productOfTenant($tenantId, (string) $validated['target_product_id']);
            if ((string) $target->id === (string) $product->id) {
                throw new McpCatalogException('Order bump precisa apontar para outro produto.', 'validation');
            }
            $out['target_product_id'] = (string) $target->id;
            $offerId = $validated['target_product_offer_id'] ?? null;
            if ($offerId) {
                $this->offerOfProduct($target, (int) $offerId);
                $out['target_product_offer_id'] = (int) $offerId;
            } else {
                $out['target_product_offer_id'] = null;
            }
        }
        if (array_key_exists('title', $validated)) {
            $title = HtmlSanitizer::plainText((string) $validated['title'], 255);
            if ($title === '') {
                throw new McpCatalogException('Título do order bump é obrigatório.', 'validation');
            }
            $out['title'] = $title;
        }
        if ($creating || array_key_exists('description', $validated)) {
            $out['description'] = HtmlSanitizer::plainTextMultiline((string) ($validated['description'] ?? ''), 20000) ?: null;
        }
        if (array_key_exists('cta_title', $validated)) {
            $cta = HtmlSanitizer::plainText((string) $validated['cta_title'], 255);
            if ($cta === '') {
                throw new McpCatalogException('CTA do order bump é obrigatório.', 'validation');
            }
            $out['cta_title'] = $cta;
        }
        if ($creating || array_key_exists('price_override', $validated)) {
            $out['price_override'] = isset($validated['price_override'])
                ? MoneyDecimal::toFloat($validated['price_override'])
                : null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validatedUpsellPayload(int $tenantId, array $raw, Product $product): array
    {
        $validated = $this->validate($raw, [
            'enabled' => ['required', 'boolean'],
            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required_with:products', 'string'],
            'products.*.product_offer_id' => ['nullable', 'integer'],
            'products.*.title_override' => ['nullable', 'string', 'max:255'],
            'products.*.description' => ['nullable', 'string', 'max:2000'],
            'products.*.image_url' => ['nullable', 'string', 'max:500'],
            'products.*.video_url' => ['nullable', 'string', 'max:500'],
            'headline' => ['nullable', 'string', 'max:255'],
            'subheadline' => ['nullable', 'string', 'max:255'],
            'button_accept' => ['nullable', 'string', 'max:120'],
            'button_decline' => ['nullable', 'string', 'max:120'],
        ]);

        $enabled = (bool) $validated['enabled'];
        $items = [];
        foreach ($validated['products'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $target = $this->productOfTenant($tenantId, (string) ($row['product_id'] ?? ''));
            $offerId = isset($row['product_offer_id']) ? (int) $row['product_offer_id'] : null;
            if ($offerId) {
                $this->offerOfProduct($target, $offerId);
            }
            $items[] = [
                'product_id' => (string) $target->id,
                'product_offer_id' => $offerId ?: null,
                'title_override' => HtmlSanitizer::plainText((string) ($row['title_override'] ?? ''), 255) ?: null,
                'description' => HtmlSanitizer::plainTextMultiline((string) ($row['description'] ?? ''), 2000) ?: null,
                'image_url' => $this->safeHttpUrl($row['image_url'] ?? null),
                'video_url' => $this->safeHttpUrl($row['video_url'] ?? null),
            ];
        }
        if ($enabled && $items === []) {
            throw new McpCatalogException('Upsell ativo precisa de pelo menos um produto.', 'validation');
        }

        return [
            'product_id' => (string) $product->id,
            'enabled' => $enabled,
            'products' => $items,
            'headline' => isset($validated['headline']) ? HtmlSanitizer::plainText((string) $validated['headline'], 255) : null,
            'subheadline' => isset($validated['subheadline']) ? HtmlSanitizer::plainText((string) $validated['subheadline'], 255) : null,
            'button_accept' => isset($validated['button_accept']) ? HtmlSanitizer::plainText((string) $validated['button_accept'], 120) : null,
            'button_decline' => isset($validated['button_decline']) ? HtmlSanitizer::plainText((string) $validated['button_decline'], 120) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validatedDownsellPayload(int $tenantId, array $raw, Product $product): array
    {
        $validated = $this->validate($raw, [
            'enabled' => ['required', 'boolean'],
            'offer_product_id' => ['nullable', 'string'],
            'product_offer_id' => ['nullable', 'integer'],
            'headline' => ['nullable', 'string', 'max:255'],
            'subheadline' => ['nullable', 'string', 'max:255'],
            'button_accept' => ['nullable', 'string', 'max:120'],
            'button_decline' => ['nullable', 'string', 'max:120'],
        ]);

        $enabled = (bool) $validated['enabled'];
        $config = $this->storedCheckoutConfig($product);
        $upsellEnabled = (bool) ($config['upsell']['enabled'] ?? false);
        if ($enabled && ! $upsellEnabled) {
            throw new McpCatalogException('Downsell só pode ficar ativo se o upsell já estiver ativo.', 'validation');
        }

        $offerProductId = null;
        $offerId = null;
        if ($enabled || ! empty($validated['offer_product_id'])) {
            $targetId = (string) ($validated['offer_product_id'] ?? '');
            if ($enabled && $targetId === '') {
                throw new McpCatalogException('Downsell ativo precisa de offer_product_id.', 'validation');
            }
            if ($targetId !== '') {
                $target = $this->productOfTenant($tenantId, $targetId);
                $offerProductId = (string) $target->id;
                if (! empty($validated['product_offer_id'])) {
                    $this->offerOfProduct($target, (int) $validated['product_offer_id']);
                    $offerId = (int) $validated['product_offer_id'];
                }
            }
        }

        return [
            'product_id' => (string) $product->id,
            'enabled' => $enabled,
            'offer_product_id' => $offerProductId,
            'product_offer_id' => $offerId,
            'headline' => isset($validated['headline']) ? HtmlSanitizer::plainText((string) $validated['headline'], 255) : null,
            'subheadline' => isset($validated['subheadline']) ? HtmlSanitizer::plainText((string) $validated['subheadline'], 255) : null,
            'button_accept' => isset($validated['button_accept']) ? HtmlSanitizer::plainText((string) $validated['button_accept'], 120) : null,
            'button_decline' => isset($validated['button_decline']) ? HtmlSanitizer::plainText((string) $validated['button_decline'], 120) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productSummary(Product $product): array
    {
        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => MoneyDecimal::toFloat($product->price),
            'currency' => $product->currency ?? 'BRL',
            'category' => $product->category,
            'is_active' => (bool) $product->is_active,
            'checkout_slug' => $product->checkout_slug,
            'approval' => $this->approval->sellerFacingStatus($product),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productDetail(Product $product): array
    {
        $summary = $this->productSummary($product);
        $summary['description'] = $product->description;
        $summary['notification_name'] = $product->notification_name;
        $summary['offers'] = $product->offers->map(fn (ProductOffer $offer) => $this->offerSummary($offer))->values()->all();
        $summary['order_bumps'] = $product->orderBumps->map(fn (ProductOrderBump $bump) => $this->bumpSummary($bump))->values()->all();
        $summary['funnel'] = $this->funnelSummary($product);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSummary(ProductOffer $offer): array
    {
        return [
            'id' => $offer->id,
            'product_id' => (string) $offer->product_id,
            'name' => $offer->name,
            'price' => MoneyDecimal::toFloat($offer->price),
            'currency' => $offer->currency,
            'checkout_slug' => $offer->checkout_slug,
            'position' => $offer->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bumpSummary(ProductOrderBump $bump): array
    {
        return [
            'id' => $bump->id,
            'product_id' => (string) $bump->product_id,
            'target_product_id' => (string) $bump->target_product_id,
            'target_product_offer_id' => $bump->target_product_offer_id,
            'title' => $bump->title,
            'description' => $bump->description,
            'cta_title' => $bump->cta_title,
            'price_override' => $bump->price_override !== null ? MoneyDecimal::toFloat($bump->price_override) : null,
            'position' => $bump->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function couponSummary(Coupon $coupon): array
    {
        $coupon->loadMissing('products');
        $productIds = $coupon->products->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => MoneyDecimal::toFloat($coupon->value),
            'product_ids' => $productIds,
            'min_amount' => $coupon->min_amount !== null ? MoneyDecimal::toFloat($coupon->min_amount) : null,
            'max_uses' => $coupon->max_uses,
            'used_count' => $coupon->used_count,
            'is_active' => (bool) $coupon->is_active,
        ];
    }

    /**
     * @return array{upsell: array<string, mixed>, downsell: array<string, mixed>}
     */
    private function funnelSummary(Product $product): array
    {
        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $upsell = is_array($config['upsell'] ?? null) ? $config['upsell'] : [];
        $downsell = is_array($config['downsell'] ?? null) ? $config['downsell'] : [];
        $products = [];
        foreach ($upsell['products'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $products[] = [
                'product_id' => isset($row['product_id']) ? (string) $row['product_id'] : null,
                'product_offer_id' => $row['product_offer_id'] ?? null,
                'title_override' => $row['title_override'] ?? null,
            ];
        }

        return [
            'upsell' => [
                'enabled' => (bool) ($upsell['enabled'] ?? false),
                'products' => $products,
                'headline' => $upsell['page']['headline'] ?? ($upsell['appearance']['title'] ?? null),
            ],
            'downsell' => [
                'enabled' => (bool) ($downsell['enabled'] ?? false),
                'product_id' => isset($downsell['product_id']) ? (string) $downsell['product_id'] : null,
                'product_offer_id' => $downsell['product_offer_id'] ?? null,
                'headline' => $downsell['page']['headline'] ?? ($downsell['appearance']['title'] ?? null),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedCheckoutConfig(Product $product): array
    {
        return is_array($product->checkout_config) ? $product->checkout_config : [];
    }

    private function productOfTenant(int $tenantId, string $productId): Product
    {
        $productId = trim($productId);
        if ($productId === '') {
            throw new McpCatalogException('product_id é obrigatório.', 'validation');
        }
        $product = Product::query()->forTenant($tenantId)->whereKey($productId)->first();
        if ($product === null) {
            throw new McpCatalogException('Produto não encontrado neste catálogo.', 'not_found');
        }

        return $product;
    }

    private function offerOfProduct(Product $product, int $offerId): ProductOffer
    {
        $offer = ProductOffer::query()->where('product_id', $product->id)->whereKey($offerId)->first();
        if ($offer === null) {
            throw new McpCatalogException('Oferta não encontrada neste produto.', 'not_found');
        }

        return $offer;
    }

    private function bumpOfProduct(Product $product, int $bumpId): ProductOrderBump
    {
        $bump = ProductOrderBump::query()->where('product_id', $product->id)->whereKey($bumpId)->first();
        if ($bump === null) {
            throw new McpCatalogException('Order bump não encontrado neste produto.', 'not_found');
        }

        return $bump;
    }

    private function couponOfTenant(int $tenantId, int $couponId): Coupon
    {
        $coupon = Coupon::query()->forTenant($tenantId)->whereKey($couponId)->first();
        if ($coupon === null) {
            throw new McpCatalogException('Cupom não encontrado neste catálogo.', 'not_found');
        }

        return $coupon;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $raw, array $rules): array
    {
        try {
            return Validator::make($raw, $rules)->validate();
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            throw new McpCatalogException(is_string($first) ? $first : 'Dados inválidos.', 'validation', [
                'errors' => $e->errors(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function priceRules(int $tenantId): array
    {
        $min = $this->minimumCharge->platformMinimumBrlForTenant($tenantId);

        return ['numeric', 'min:'.$min];
    }

    /**
     * @return array{brl_eur: float|string, brl_usd: float|string}
     */
    private function rates(): array
    {
        return config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
    }

    private function newProductId(): string
    {
        $driver = Product::query()->getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return (string) ((int) (Product::query()->max('id') ?? 0) + 1);
        }

        return (string) Str::uuid();
    }

    private function safeHttpUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return SafeUrl::normalizeHttpUrl($url);
    }

    /**
     * @param  list<array<string, mixed>>  $gates
     * @param  array<string, mixed>  $normalized
     * @return array{op: string, status: string, gates: list<array<string, mixed>>, error: ?string, summary: string, normalized: array<string, mixed>}
     */
    private function inspected(
        string $op,
        string $status,
        ?string $error = null,
        array $gates = [],
        string $summary = '',
        array $normalized = [],
    ): array {
        return [
            'op' => $op,
            'status' => $status,
            'gates' => $gates,
            'error' => $error,
            'summary' => $summary !== '' ? $summary : ($error ?: $op),
            'normalized' => $normalized,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function publicInspected(array $items): array
    {
        return array_map(static function (array $item): array {
            return [
                'op' => $item['op'] ?? '',
                'status' => $item['status'] ?? '',
                'summary' => $item['summary'] ?? '',
                'error' => $item['error'] ?? null,
                'gates' => $item['gates'] ?? [],
            ];
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(string $action, int $tenantId, mixed $target, array $metadata): void
    {
        $targetType = is_object($target) ? $target::class : null;
        $targetId = is_object($target) ? ($target->id ?? null) : null;

        SellerActivityLogService::record(
            actor: null,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            metadata: $metadata,
            tenantId: $tenantId,
            source: 'mcp',
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $schema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }
}
