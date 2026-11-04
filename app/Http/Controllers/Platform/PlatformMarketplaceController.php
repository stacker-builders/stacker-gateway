<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Support\MarketplaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class PlatformMarketplaceController extends Controller
{
    public function index(Request $request): Response
    {
        $seller = $this->searchTerm($request->query('seller'));
        $status = $request->query('status', 'all');
        if (! in_array($status, ['all', 'pending', 'authorized'], true)) {
            $status = 'all';
        }
        $category = Product::normalizeCategoryFilter($request->query('category'));

        $paginator = new LengthAwarePaginator([], 0, 30, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
        $counts = ['all' => 0, 'pending' => 0, 'authorized' => 0];

        if ($this->optInColumnReady()) {
            $query = Product::query()
                ->where('show_in_marketplace', true)
                ->with(['tenantOwner:id,name,email,phone,trade_name,company_name'])
                ->orderByDesc('updated_at');

            if ($seller !== '') {
                $this->applySellerFilter($query, $seller);
            }

            if (Schema::hasColumn('products', 'category')) {
                $query->ofCategory($category);
            }

            $approvalReady = $this->approvalColumnReady();
            $counts = [
                'all' => (clone $query)->count(),
                'pending' => $approvalReady
                    ? (clone $query)->where('marketplace_approved', false)->count()
                    : 0,
                'authorized' => $approvalReady
                    ? (clone $query)->where('marketplace_approved', true)->count()
                    : (clone $query)->count(),
            ];

            if ($approvalReady && $status === 'pending') {
                $query->where('marketplace_approved', false);
            } elseif ($approvalReady && $status === 'authorized') {
                $query->where('marketplace_approved', true);
            }

            $paginator = $query->paginate(30)->withQueryString()->through(function (Product $product) use ($approvalReady) {
                $owner = $product->tenantOwner;
                $approved = $approvalReady ? (bool) $product->marketplace_approved : true;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'category_label' => Product::categoryLabels()[$product->category] ?? null,
                    'checkout_slug' => $product->checkout_slug,
                    'price' => (float) $product->price,
                    'currency' => $product->currency ?? 'BRL',
                    'is_active' => (bool) $product->is_active,
                    'marketplace_approved' => $approved,
                    'infoprodutor_name' => $this->sellerDisplayName($owner),
                    'infoprodutor_email' => $owner?->email,
                    'infoprodutor_phone' => $owner?->phone,
                    'can_authorize' => $approvalReady && ! $approved,
                    'can_revoke' => $approvalReady && $approved,
                ];
            });
        }

        return Inertia::render('Platform/Marketplace/Index', [
            'products' => $paginator,
            'filters' => [
                'seller' => $seller !== '' ? $seller : null,
                'category' => $category !== '' ? $category : null,
                'status' => $status,
            ],
            'categories' => Product::categoriesForSelect(),
            'auto_accept' => MarketplaceSettings::autoAcceptEnabled(),
            'public_enabled' => MarketplaceSettings::publicEnabled(),
            'public_url' => url('/marketplace'),
            'counts' => $counts,
        ]);
    }

    public function updatePublic(Request $request): RedirectResponse
    {
        $request->validate([
            'public_enabled' => ['required'],
        ]);

        $enabled = $request->boolean('public_enabled');
        $was = MarketplaceSettings::publicEnabled();
        MarketplaceSettings::setPublicEnabled($enabled);

        PlatformAuditService::log('marketplace.public_updated', [
            'public_enabled' => $enabled,
            'previous' => $was,
        ], $request);

        return redirect()->back()->with(
            'success',
            $enabled
                ? 'Marketplace público ligado. O link '.url('/marketplace').' está no ar.'
                : 'Marketplace público desligado. O link não abre.'
        );
    }

    public function updateAutoAccept(Request $request): RedirectResponse
    {
        $request->validate([
            'auto_accept' => ['required'],
        ]);

        $enabled = $request->boolean('auto_accept');
        $was = MarketplaceSettings::autoAcceptEnabled();
        MarketplaceSettings::setAutoAccept($enabled);

        PlatformAuditService::log('marketplace.auto_accept_updated', [
            'auto_accept' => $enabled,
            'previous' => $was,
        ], $request);

        return redirect()->back()->with(
            'success',
            $enabled
                ? 'Aceitação automática ligada. Novos produtos marcados pelo infoprodutor entram no marketplace.'
                : 'Aceitação automática desligada. Cada produto precisa de autorização para aparecer no marketplace.'
        );
    }

    public function authorizeProduct(Request $request, Product $product): RedirectResponse
    {
        if (! $this->optInColumnReady() || ! (bool) $product->show_in_marketplace) {
            return redirect()->back()->with('error', 'Este produto não está marcado para o marketplace.');
        }

        if (! $this->approvalColumnReady()) {
            return redirect()->back()->with('error', 'Execute as migrações do banco para autorizar o marketplace.');
        }

        if ((bool) $product->marketplace_approved) {
            return redirect()->back()->with('success', 'Este produto já está autorizado no marketplace.');
        }

        $product->marketplace_approved = true;
        $product->save();

        PlatformAuditService::log('marketplace.product_authorized', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
            'product_name' => $product->name,
        ], $request);

        return redirect()->back()->with('success', 'Produto autorizado. Ele aparece no marketplace se o infoprodutor mantiver a opção ativa.');
    }

    public function revokeProduct(Request $request, Product $product): RedirectResponse
    {
        if (! $this->optInColumnReady() || ! (bool) $product->show_in_marketplace) {
            return redirect()->back()->with('error', 'Este produto não está marcado para o marketplace.');
        }

        if (! $this->approvalColumnReady()) {
            return redirect()->back()->with('error', 'Execute as migrações do banco para gerenciar o marketplace.');
        }

        $product->marketplace_approved = false;
        $product->save();

        PlatformAuditService::log('marketplace.product_revoked', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
            'product_name' => $product->name,
        ], $request);

        return redirect()->back()->with('success', 'Autorização removida. O produto deixa de aparecer no marketplace até uma nova autorização.');
    }

    private function optInColumnReady(): bool
    {
        return Schema::hasTable('products') && Schema::hasColumn('products', 'show_in_marketplace');
    }

    private function approvalColumnReady(): bool
    {
        return Schema::hasTable('products') && Schema::hasColumn('products', 'marketplace_approved');
    }

    private function searchTerm(mixed $value): string
    {
        $search = trim((string) $value);
        $search = str_replace(['%', '_'], ['\\%', '\\_'], $search);

        return mb_substr($search, 0, 80);
    }

    private function applySellerFilter($query, string $seller): void
    {
        $like = '%'.$seller.'%';
        $digits = preg_replace('/\D+/', '', str_replace(['\\%', '\\_'], '', $seller)) ?? '';

        $query->whereHas('tenantOwner', function ($owner) use ($like, $digits) {
            $owner->where(function ($inner) use ($like, $digits) {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('trade_name', 'like', $like)
                    ->orWhere('company_name', 'like', $like)
                    ->orWhere('phone', 'like', $like);

                if (is_string($digits) && strlen($digits) >= 4) {
                    $inner->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        });
    }

    private function sellerDisplayName(?User $owner): string
    {
        if (! $owner) {
            return '—';
        }

        $trade = trim((string) ($owner->trade_name ?: ''));
        if ($trade !== '') {
            return $trade;
        }

        $name = trim((string) $owner->name);

        return $name !== '' ? $name : '—';
    }
}
