<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\DeliverableAccessLinkService;
use App\Services\MemberAreaResolver;
use App\Services\RefundRequestService;
use App\Services\StorageService;
use App\Support\RefundEligibility;
use App\Support\SaleOrigin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPanelController extends Controller
{
    public function __construct(
        protected RefundRequestService $refundRequestService
    ) {}

    public function index(Request $request, MemberAreaResolver $resolver): Response
    {
        $user = $request->user();
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->with(['product.tenantOwner', 'orderItems.product.tenantOwner', 'refundRequests'])
            ->orderByDesc('id')
            ->get();

        $items = [];
        foreach ($orders as $order) {
            if ($order->orderItems->isNotEmpty()) {
                foreach ($order->orderItems as $line) {
                    $items[] = $this->purchaseRowFromOrder($order, $line->product, (float) $line->amount, (int) $line->position, $resolver, $user);
                }
            } else {
                $items[] = $this->purchaseRowFromOrder($order, $order->product, (float) $order->amount, 0, $resolver, $user);
            }
        }

        $purchasedProductIds = collect($items)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $grantedProducts = $user->products()
            ->with('tenantOwner')
            ->when($purchasedProductIds !== [], fn ($q) => $q->whereNotIn('products.id', $purchasedProductIds))
            ->orderBy('name')
            ->get();

        foreach ($grantedProducts as $product) {
            $items[] = $this->grantedAccessRow($product, $resolver, $user);
        }

        $items = $this->collapseSubscriptionPurchases($items);

        return Inertia::render('Cliente/Index', [
            'purchases' => $items,
            'pageTitle' => 'Minhas compras',
        ]);
    }

    public function requestRefund(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $user = $request->user();
        $order = Order::query()->where('id', $validated['order_id'])->where('user_id', $user->id)->firstOrFail();

        if (! RefundEligibility::canCustomerRequestRefund($order)) {
            return back()->with('error', 'Este pedido não está elegível para reembolso.');
        }

        try {
            DB::transaction(function () use ($order, $user, $validated) {
                $rr = $this->refundRequestService->createFromCustomer($order, $user, $validated['reason']);
                $this->refundRequestService->notifySeller($rr);
                $this->refundRequestService->notifyPlatformAdmins($rr);
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Não foi possível registrar a solicitação. Tente novamente.');
        }

        return back()->with('success', 'Solicitação de reembolso enviada ao vendedor.');
    }

    /**
     * Uma linha em "Minhas compras" (produto principal ou order bump do mesmo pedido).
     *
     * @return array<string, mixed>
     */
    private function purchaseRowFromOrder(
        Order $order,
        ?Product $product,
        float $lineAmount,
        int $position,
        MemberAreaResolver $resolver,
        User $user
    ): array {
        $productId = $product?->id ?? $order->product_id;
        $accessUrl = $this->productAccessUrl($product, $resolver, $user);

        return [
            'purchase_key' => $order->id.'-'.($productId ?? 'main').'-'.$position,
            'order_id' => $order->id,
            'public_reference' => $order->public_reference,
            'amount' => $lineAmount,
            'product_id' => $productId,
            'product_name' => $product?->name ?? 'Produto',
            'product_type' => $product?->type,
            'product_type_label' => $this->productTypeLabel($product),
            'product_image_url' => $this->productImageUrl($product),
            'access_url' => $accessUrl,
            'access_cta' => $this->accessCta($product, $accessUrl),
            'access_hint' => $this->accessHint($product, $accessUrl),
            'purchased_at' => $this->dateIso($order->created_at),
            'purchased_at_label' => $this->dateLabel($order->created_at),
            'payment_method_label' => $this->paymentMethodLabel($order),
            'seller_name' => $this->sellerName($product),
            'support_email' => $this->supportEmail($product),
            'shipping' => $this->shippingSummary($order, $product),
            'refund_status' => $position === 0 ? $this->latestRefundStatus($order) : null,
            'is_order_bump' => $position > 0,
            'is_manual_grant' => false,
            'can_request_refund' => $position === 0 && RefundEligibility::canCustomerRequestRefund($order),
            'is_renewal' => (bool) $order->is_renewal,
            'billing_type' => $product?->billing_type,
            'sale_origin' => $order->sale_origin ?: (is_array($order->metadata) ? ($order->metadata['sale_origin'] ?? null) : null),
            'renewal_count' => 0,
        ];
    }

    /**
     * Produto liberado manualmente (product_user) sem pedido associado.
     *
     * @return array<string, mixed>
     */
    private function grantedAccessRow(Product $product, MemberAreaResolver $resolver, User $user): array
    {
        $accessUrl = $this->productAccessUrl($product, $resolver, $user);
        $grantedAt = $product->pivot?->created_at;

        return [
            'purchase_key' => 'granted-'.$product->id,
            'order_id' => null,
            'public_reference' => null,
            'amount' => null,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_type' => $product->type,
            'product_type_label' => $this->productTypeLabel($product),
            'product_image_url' => $this->productImageUrl($product),
            'access_url' => $accessUrl,
            'access_cta' => $this->accessCta($product, $accessUrl),
            'access_hint' => $this->accessHint($product, $accessUrl),
            'purchased_at' => $this->dateIso($grantedAt),
            'purchased_at_label' => $this->dateLabel($grantedAt),
            'payment_method_label' => null,
            'seller_name' => $this->sellerName($product),
            'support_email' => $this->supportEmail($product),
            'shipping' => null,
            'refund_status' => null,
            'is_order_bump' => false,
            'is_manual_grant' => true,
            'can_request_refund' => false,
            'is_renewal' => false,
            'billing_type' => $product->billing_type,
            'sale_origin' => null,
            'renewal_count' => 0,
        ];
    }

    /**
     * Assinaturas/renovações do mesmo produto viram um único card (data do último pagamento).
     * Se qualquer linha do produto for assinatura/renovação, todas as compras desse
     * produto (não bump) colapsam — evita duplicar quando o 1º pedido veio sem flag.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function collapseSubscriptionPurchases(array $items): array
    {
        $collapsibleIds = [];
        foreach ($items as $item) {
            if (! empty($item['is_order_bump']) || ! empty($item['is_manual_grant'])) {
                continue;
            }
            $productId = $item['product_id'] ?? null;
            if ($productId === null || $productId === '') {
                continue;
            }
            if ($this->purchaseRowIsSubscriptionLike($item)) {
                $collapsibleIds[(string) $productId] = true;
            }
        }

        $seen = [];
        $collapsed = [];

        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            $key = ($productId !== null && $productId !== '') ? (string) $productId : null;
            $isBump = (bool) ($item['is_order_bump'] ?? false);
            $isGrant = (bool) ($item['is_manual_grant'] ?? false);

            if ($isBump || $isGrant || $key === null || ! isset($collapsibleIds[$key])) {
                $collapsed[] = $item;

                continue;
            }

            if (isset($seen[$key])) {
                $collapsed[$seen[$key]]['renewal_count'] = (int) ($collapsed[$seen[$key]]['renewal_count'] ?? 0) + 1;
                $collapsed[$seen[$key]]['is_renewal'] = true;

                continue;
            }

            $seen[$key] = count($collapsed);
            $item['renewal_count'] = 0;
            $collapsed[] = $item;
        }

        return $collapsed;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function purchaseRowIsSubscriptionLike(array $item): bool
    {
        if (($item['billing_type'] ?? null) === Product::BILLING_SUBSCRIPTION) {
            return true;
        }
        if (! empty($item['is_renewal'])) {
            return true;
        }

        return ($item['sale_origin'] ?? null) === SaleOrigin::MEMBER_MODULE_RENEWAL;
    }

    private function productAccessUrl(?Product $product, MemberAreaResolver $resolver, User $user): ?string
    {
        if ($product === null) {
            return null;
        }

        if ($product->type === Product::TYPE_AREA_MEMBROS && $product->checkout_slug) {
            return $resolver->baseUrlForProduct($product);
        }

        if (in_array($product->type, DeliverableAccessLinkService::trackedProductTypes(), true)) {
            return app(DeliverableAccessLinkService::class)->trackedUrl($user, $product);
        }

        return null;
    }

    private function dateIso(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return Carbon::parse($date)->toIso8601String();
    }

    private function dateLabel(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return Carbon::parse($date)->timezone(config('app.timezone'))->format('d/m/Y');
    }

    private function productTypeLabel(?Product $product): ?string
    {
        if ($product === null || $product->type === null) {
            return null;
        }

        return Product::typeConfig()[$product->type]['label'] ?? null;
    }

    private function accessCta(?Product $product, ?string $accessUrl): ?string
    {
        if ($accessUrl === null || $accessUrl === '') {
            return null;
        }

        return match ($product?->type) {
            Product::TYPE_AREA_MEMBROS => 'Entrar na área de membros',
            Product::TYPE_AREA_MEMBROS_EXTERNA => 'Acessar plataforma',
            Product::TYPE_LINK => 'Abrir conteúdo',
            Product::TYPE_APLICATIVO => 'Abrir aplicativo',
            default => 'Acessar',
        };
    }

    private function accessHint(?Product $product, ?string $accessUrl): ?string
    {
        if ($accessUrl !== null && $accessUrl !== '') {
            return null;
        }

        return match ($product?->type) {
            Product::TYPE_PRODUTO_FISICO => 'Produto físico. O envio segue para o endereço informado na compra.',
            Product::TYPE_LINK_PAGAMENTO => 'Este pedido não inclui conteúdo digital para acessar aqui.',
            Product::TYPE_AREA_MEMBROS_EXTERNA => 'O acesso é liberado na plataforma externa. Confira o e-mail da compra.',
            default => 'O acesso é enviado por e-mail pelo vendedor.',
        };
    }

    private function paymentMethodLabel(Order $order): ?string
    {
        return match ($order->resolveCheckoutPaymentMethodKey()) {
            'pix' => 'Pix',
            'pix_auto' => 'Pix automático',
            'card' => 'Cartão',
            'boleto' => 'Boleto',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'open_finance' => 'Open Finance',
            default => null,
        };
    }

    private function sellerName(?Product $product): ?string
    {
        $owner = $product?->tenantOwner;
        if ($owner === null) {
            return null;
        }

        $name = trim((string) ($owner->trade_name ?: $owner->name));

        return $name !== '' ? $name : null;
    }

    private function supportEmail(?Product $product): ?string
    {
        $email = trim((string) ($product?->support_email ?? ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @return array{lines: list<string>, delivery_label: ?string}|null
     */
    private function shippingSummary(Order $order, ?Product $product): ?array
    {
        if ($product?->type !== Product::TYPE_PRODUTO_FISICO) {
            return null;
        }

        $addr = is_array($order->shipping_address) ? $order->shipping_address : [];
        $street = trim((string) ($addr['street'] ?? ''));
        $number = trim((string) ($addr['number'] ?? ''));
        $streetLine = $street !== ''
            ? ($number !== '' ? $street.', '.$number : $street)
            : null;

        $city = trim((string) ($addr['city'] ?? ''));
        $state = trim((string) ($addr['state'] ?? ''));
        $cityLine = ($city !== '' || $state !== '')
            ? trim($city.($state !== '' ? ' — '.$state : ''))
            : null;

        $zip = trim((string) ($addr['zip'] ?? ''));

        $lines = array_values(array_filter([
            $streetLine,
            trim((string) ($addr['complement'] ?? '')) ?: null,
            trim((string) ($addr['neighborhood'] ?? '')) ?: null,
            $cityLine,
            $zip !== '' ? 'CEP '.$zip : null,
        ]));

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $min = $meta['delivery_days_min'] ?? null;
        $max = $meta['delivery_days_max'] ?? null;
        $deliveryLabel = null;
        if ($min !== null && $min !== '') {
            $deliveryLabel = (int) $min === (int) ($max ?? $min)
                ? ((int) $min).' dias úteis'
                : ((int) $min).'–'.((int) $max).' dias úteis';
        }

        if ($lines === [] && $deliveryLabel === null) {
            return null;
        }

        return [
            'lines' => $lines,
            'delivery_label' => $deliveryLabel,
        ];
    }

    private function latestRefundStatus(Order $order): ?string
    {
        $latest = $order->refundRequests->sortByDesc('id')->first();
        if ($latest === null) {
            return null;
        }

        return in_array($latest->status, [
            RefundRequest::STATUS_PENDING,
            RefundRequest::STATUS_APPROVED,
            RefundRequest::STATUS_REJECTED,
        ], true) ? $latest->status : null;
    }

    private function productImageUrl(?Product $product): ?string
    {
        if ($product?->image === null || $product->image === '') {
            return null;
        }

        return (new StorageService($product->tenant_id))->url($product->image);
    }
}
