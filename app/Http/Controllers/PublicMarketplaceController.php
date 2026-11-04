<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StorageService;
use App\Support\MarketplaceSettings;
use Inertia\Inertia;
use Inertia\Response;

class PublicMarketplaceController extends Controller
{
    public function index(): Response
    {
        if (! MarketplaceSettings::publicEnabled()) {
            abort(404);
        }

        $platformName = trim((string) config('getfy.app_name', 'plataforma')) ?: 'plataforma';
        $products = Product::query()
            ->listedOnMarketplace()
            ->availableForPurchase()
            ->whereNotNull('checkout_slug')
            ->where('checkout_slug', '!=', '')
            ->with('tenantOwner:id,name,trade_name')
            ->orderBy('name')
            ->get();

        $sections = [];
        foreach (Product::categoryLabels() as $key => $label) {
            $sections[$key] = [
                'key' => $key,
                'label' => $label,
                'products' => [],
            ];
        }
        $sections['uncategorized'] = [
            'key' => 'uncategorized',
            'label' => 'Sem categoria',
            'products' => [],
        ];

        foreach ($products as $product) {
            $key = is_string($product->category) && isset($sections[$product->category]) && $product->category !== 'uncategorized'
                ? $product->category
                : 'uncategorized';
            $sections[$key]['products'][] = $this->card($product);
        }

        $sections = array_values(array_filter(
            $sections,
            fn (array $section) => $section['products'] !== []
        ));

        return Inertia::render('Marketplace/Public', [
            'sections' => $sections,
            'platformName' => $platformName,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Product $product): array
    {
        $imageUrl = null;
        if (is_string($product->image) && $product->image !== '') {
            $imageUrl = (new StorageService($product->tenant_id))->url($product->image);
        }

        $owner = $product->tenantOwner;
        $sellerName = trim((string) ($owner?->trade_name ?: $owner?->name));
        $currency = strtoupper((string) ($product->currency ?: 'BRL'));

        return [
            'id' => $product->id,
            'name' => $product->name,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'price' => (float) $product->price,
            'currency' => $currency,
            'seller_name' => $sellerName !== '' ? $sellerName : null,
            'checkout_url' => url('/c/'.$product->checkout_slug).'?origem=marketplace',
        ];
    }
}
