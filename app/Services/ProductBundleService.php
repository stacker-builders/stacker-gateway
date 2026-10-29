<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBundleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ProductBundleService
{
    public const MAX_INCLUDED = 20;

    /**
     * @return list<string>
     */
    public static function allowedIncludedTypes(): array
    {
        return [
            Product::TYPE_AREA_MEMBROS,
            Product::TYPE_AREA_MEMBROS_EXTERNA,
            Product::TYPE_LINK,
        ];
    }

    public function isEnabled(): bool
    {
        return Schema::hasTable('product_bundle_items');
    }

    /**
     * Produtos do tenant que podem entrar num combo (digitais, pagamento único, sem pacote próprio).
     *
     * @return Collection<int, Product>
     */
    public function candidatesForTenant(int $tenantId, ?string $exceptProductId = null): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $query = Product::query()
            ->forTenant($tenantId)
            ->whereIn('type', self::allowedIncludedTypes())
            ->where('billing_type', Product::BILLING_ONE_TIME)
            ->orderBy('name');

        if ($exceptProductId !== null && $exceptProductId !== '') {
            $query->where('id', '!=', $exceptProductId);
        }

        $products = $query->get();
        $comboParentIds = ProductBundleItem::query()
            ->whereIn('parent_product_id', $products->pluck('id'))
            ->pluck('parent_product_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->all();

        return $products
            ->reject(fn (Product $product) => in_array((string) $product->id, $comboParentIds, true))
            ->values();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function candidatesPayload(int $tenantId, ?string $exceptProductId = null): array
    {
        return $this->candidatesForTenant($tenantId, $exceptProductId)
            ->map(fn (Product $product) => [
                'id' => (string) $product->id,
                'name' => (string) $product->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Product>
     */
    public function includedProducts(Product $parent): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $ids = ProductBundleItem::query()
            ->where('parent_product_id', $parent->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('included_product_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $products = Product::query()->whereIn('id', $ids)->get()->keyBy(fn (Product $p) => (string) $p->id);

        return $ids
            ->map(fn ($id) => $products->get((string) $id))
            ->filter()
            ->values();
    }

    /**
     * @return list<string>
     */
    public function includedProductIds(Product $parent): array
    {
        return $this->includedProducts($parent)
            ->map(fn (Product $product) => (string) $product->id)
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $ids
     */
    public function syncIncluded(Product $parent, array $ids): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($parent->getKey() === null || $parent->getKey() === '') {
            throw new InvalidArgumentException('Não foi possível salvar os produtos inclusos deste combo.');
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (! is_string($id) && ! is_int($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ($id === '' || $id === (string) $parent->id) {
                continue;
            }
            $normalized[$id] = $id;
        }
        $normalized = array_values($normalized);

        if (count($normalized) > self::MAX_INCLUDED) {
            throw new InvalidArgumentException('Selecione no máximo '.self::MAX_INCLUDED.' produtos para entregar junto.');
        }

        $allowed = $this->candidatesForTenant((int) $parent->tenant_id, (string) $parent->id)
            ->map(fn (Product $product) => (string) $product->id)
            ->all();

        foreach ($normalized as $id) {
            if (! in_array($id, $allowed, true)) {
                throw new InvalidArgumentException('Um dos produtos selecionados não pode entrar neste combo.');
            }
        }

        ProductBundleItem::query()->where('parent_product_id', $parent->id)->delete();

        foreach (array_values($normalized) as $position => $id) {
            ProductBundleItem::query()->create([
                'parent_product_id' => $parent->id,
                'included_product_id' => $id,
                'position' => $position,
            ]);
        }
    }

    /**
     * Grava no pedido os inclusos do produto principal e de cada item pago (ex.: order bump).
     */
    public function snapshotIncludedProducts(Order $order): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $order->loadMissing('orderItems.product', 'product');

        $parents = collect();
        if ($order->product) {
            $parents->put((string) $order->product->id, $order->product);
        }

        foreach ($order->orderItems as $item) {
            if ($item->isBundleItem() || $item->product === null) {
                continue;
            }
            $parents->put((string) $item->product->id, $item->product);
        }

        foreach ($parents as $parent) {
            $this->appendIncludedOrderItems($order, $parent);
        }

        $order->unsetRelation('orderItems');
    }

    public function appendIncludedOrderItems(Order $order, Product $parent): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $included = $this->includedProducts($parent);
        if ($included->isEmpty()) {
            return;
        }

        $existing = $order->orderItems()->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $position = (int) $order->orderItems()->max('position');
        $hasSource = Schema::hasColumn('order_items', 'source');

        foreach ($included as $product) {
            $id = (string) $product->id;
            if (in_array($id, $existing, true)) {
                continue;
            }

            $payload = [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_offer_id' => null,
                'subscription_plan_id' => null,
                'amount' => 0,
                'position' => ++$position,
            ];
            if ($hasSource) {
                $payload['source'] = OrderItem::SOURCE_BUNDLE;
            }

            OrderItem::query()->create($payload);
            $existing[] = $id;
        }
    }
}
