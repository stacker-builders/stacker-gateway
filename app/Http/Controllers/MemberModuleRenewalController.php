<?php

namespace App\Http\Controllers;

use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Models\MemberModule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\MemberModuleAccessService;
use App\Services\PaymentService;
use App\Support\CheckoutPaymentConsumer;
use App\Support\MemberAreaAdminPreview;
use App\Support\SaleOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class MemberModuleRenewalController extends Controller
{
    public function __construct(
        protected MemberModuleAccessService $accessService
    ) {}

    public function createPix(Request $request): JsonResponse
    {
        $module = $this->moduleFromRequest($request);
        $product = $this->productFromRequest($request);
        $user = $request->user();
        $this->assertModuleBelongsToProduct($module, $product);
        $this->assertNotAdminPreview($request, $product);

        $lock = $this->accessService->moduleLockPayload($module, $product, $user, now());
        if (($lock['lock_reason'] ?? null) !== 'expired' || empty($lock['can_renew'])) {
            return response()->json([
                'message' => 'Este módulo não está disponível para renovação.',
            ], 422);
        }

        $amount = (float) ($lock['renewal_amount'] ?? 0);
        if ($amount <= 0) {
            return response()->json(['message' => 'Valor de renovação não configurado.'], 422);
        }

        $pending = $this->pendingRenewalOrder($user->id, $module->id);
        if ($pending) {
            $meta = is_array($pending->metadata) ? $pending->metadata : [];
            $qrcode = $meta['pix_qrcode'] ?? null;
            $copyPaste = $meta['pix_copy_paste'] ?? null;
            if (is_string($copyPaste) && $copyPaste !== '') {
                return response()->json([
                    'success' => true,
                    'order_id' => $pending->id,
                    'amount' => (float) $pending->amount,
                    'qrcode' => is_string($qrcode) ? $qrcode : null,
                    'copy_paste' => $copyPaste,
                ]);
            }
        }

        $consumerInput = [
            'name' => $user->name ?? $user->email,
            'email' => $user->email,
            'cpf' => $this->documentForUser($user, $product),
            'phone' => $user->phone ?? '',
        ];

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => $amount,
            'email' => $user->email,
            'cpf' => $consumerInput['cpf'] ?: null,
            'phone' => $consumerInput['phone'] ?: null,
            'customer_ip' => $request->ip(),
            'payment_method' => 'pix',
            'sale_origin' => SaleOrigin::MEMBER_MODULE_RENEWAL,
            'metadata' => [
                'sale_origin' => SaleOrigin::MEMBER_MODULE_RENEWAL,
                MemberModuleAccessService::META_RENEWAL => true,
                MemberModuleAccessService::META_MODULE_ID => $module->id,
                'checkout_payment_method' => 'pix',
                'module_title' => $module->title,
            ],
        ]);

        if (Schema::hasTable('order_items')) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => $amount,
                'position' => 0,
            ]);
        }

        event(new OrderPending($order));

        try {
            $consumer = CheckoutPaymentConsumer::build($consumerInput, $order->id);
            $pixResult = app(PaymentService::class)->createPixPayment($order, $product, $consumer);
            $order->refresh();
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $meta['pix_qrcode'] = $pixResult['qrcode'] ?? null;
            $meta['pix_copy_paste'] = $pixResult['copy_paste'] ?? null;
            $order->update(['metadata' => $meta]);
            event(new PixGenerated($order, [
                'qrcode' => $pixResult['qrcode'] ?? null,
                'copy_paste' => $pixResult['copy_paste'] ?? null,
                'transaction_id' => $pixResult['transaction_id'] ?? null,
            ]));
        } catch (\Throwable $e) {
            $order->delete();

            return response()->json([
                'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'amount' => $amount,
            'qrcode' => $pixResult['qrcode'] ?? null,
            'copy_paste' => $pixResult['copy_paste'] ?? null,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $module = $this->moduleFromRequest($request);
        $order = $this->orderFromRequest($request);
        $product = $this->productFromRequest($request);
        $user = $request->user();
        $this->assertModuleBelongsToProduct($module, $product);

        if ((int) $order->user_id !== (int) $user->id || (string) $order->product_id !== (string) $product->id) {
            abort(404);
        }
        if (! MemberModuleAccessService::isRenewalOrder($order) || MemberModuleAccessService::renewalModuleId($order) !== (int) $module->id) {
            abort(404);
        }

        $paid = $order->status === 'completed';
        $lock = $this->accessService->moduleLockPayload($module->fresh(), $product, $user, now());

        return response()->json([
            'status' => $order->status,
            'paid' => $paid,
            'is_locked' => (bool) ($lock['is_locked'] ?? false),
        ]);
    }

    private function productFromRequest(Request $request): Product
    {
        $product = $request->attributes->get('member_area_product')
            ?? $request->route('product');
        if (! $product instanceof Product) {
            abort(404);
        }

        return $product;
    }

    private function moduleFromRequest(Request $request): MemberModule
    {
        $module = $request->route('module');
        if ($module instanceof MemberModule) {
            return $module;
        }
        if ($module === null || $module === '') {
            abort(404);
        }

        return MemberModule::query()->findOrFail($module);
    }

    private function orderFromRequest(Request $request): Order
    {
        $order = $request->route('order');
        if ($order instanceof Order) {
            return $order;
        }
        if ($order === null || $order === '') {
            abort(404);
        }

        return Order::query()->findOrFail($order);
    }

    private function assertModuleBelongsToProduct(MemberModule $module, Product $product): void
    {
        if ((string) $module->product_id !== (string) $product->id) {
            abort(404);
        }
    }

    private function assertNotAdminPreview(Request $request, Product $product): void
    {
        if (MemberAreaAdminPreview::isActive($request, $product)) {
            abort(403, 'Modo de visualização administrativa: esta ação não está disponível.');
        }
    }

    private function pendingRenewalOrder(int $userId, int $moduleId): ?Order
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->where('payment_method', 'pix')
            ->where('created_at', '>=', now()->subMinutes(45))
            ->latest('id')
            ->limit(20)
            ->get()
            ->first(function (Order $order) use ($moduleId) {
                return MemberModuleAccessService::isRenewalOrder($order)
                    && MemberModuleAccessService::renewalModuleId($order) === $moduleId;
            });
    }

    private function documentForUser($user, Product $product): string
    {
        $fromUser = preg_replace('/\D/', '', (string) ($user->document ?? ''));
        if (strlen($fromUser) >= 11) {
            return $fromUser;
        }

        $fromOrder = Order::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->whereNotNull('cpf')
            ->latest('id')
            ->value('cpf');

        return preg_replace('/\D/', '', (string) $fromOrder) ?: '';
    }
}
