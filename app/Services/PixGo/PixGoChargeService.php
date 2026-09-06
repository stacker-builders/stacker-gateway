<?php

namespace App\Services\PixGo;

use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Models\Order;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\MerchantOperationalGuard;
use App\Services\MinimumChargeService;
use App\Services\PaymentService;
use App\Services\PixGoAccess;
use App\Support\FakeConsumerData;
use App\Support\SaleOrigin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PixGoChargeService
{
    public const CACHE_PREFIX = 'pixgo.charge.';

    public const CACHE_TTL_HOURS = 25;

    /**
     * @param  array{name?: string|null, email?: string|null, cpf?: string|null}  $buyer
     * @return array{order: Order, token: string, qrcode: ?string, copy_paste: ?string, transaction_id: ?string}
     */
    public function charge(int $tenantId, float $amountBrl, array $buyer = []): array
    {
        if (! PixGoAccess::globalEnabled()) {
            abort(404);
        }

        MerchantOperationalGuard::assertCanAcceptPayments($tenantId);
        app(MinimumChargeService::class)->assertPlatformCheckout($amountBrl, $tenantId);

        $consumer = $this->resolveConsumer($buyer, $tenantId);

        $metadata = [
            'source' => 'pixgo',
            'sale_origin' => SaleOrigin::PIXGO,
            'checkout_payment_method' => 'pix',
            'consumer_name' => $consumer['name'],
            'reconcile_until' => now()->addHours(24)->toIso8601String(),
        ];

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'product_id' => null,
            'status' => 'pending',
            'amount' => round($amountBrl, 2),
            'email' => $consumer['email'],
            'cpf' => $buyer['cpf'] ?? null,
            'phone' => null,
            'customer_ip' => request()->ip(),
            'gateway' => null,
            'gateway_id' => null,
            'payment_method' => 'pix',
            'sale_origin' => SaleOrigin::PIXGO,
            'cajupay_account_id' => app(CajuPayAccountResolver::class)->accountIdForTenant($tenantId),
            'metadata' => $metadata,
        ]);

        event(new OrderPending($order));

        $paymentService = app(PaymentService::class);

        try {
            $result = $paymentService->createPixPayment($order, null, $consumer);
        } catch (\Throwable $e) {
            $order->delete();

            throw ValidationException::withMessages([
                'amount' => $e->getMessage() ?: 'Não foi possível gerar o PIX.',
            ]);
        }

        $qrcode = $result['qrcode'] ?? null;
        $copyPaste = $result['copy_paste'] ?? null;

        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'pix_qrcode' => $qrcode,
                'pix_copy_paste' => $copyPaste,
            ]),
        ]);

        event(new PixGenerated($order, [
            'qrcode' => $qrcode,
            'copy_paste' => $copyPaste,
            'transaction_id' => $result['transaction_id'] ?? null,
        ]));

        $token = Str::random(32);
        Cache::put(self::CACHE_PREFIX.$token, [
            'order_id' => $order->id,
            'tenant_id' => $tenantId,
            'qrcode' => $qrcode,
            'copy_paste' => $copyPaste,
        ], now()->addHours(self::CACHE_TTL_HOURS));

        return [
            'order' => $order->fresh(),
            'token' => $token,
            'qrcode' => $result['qrcode'] ?? null,
            'copy_paste' => $result['copy_paste'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
        ];
    }

    /**
     * @return array{order_id: int, tenant_id: int}|null
     */
    public function resolveToken(string $token): ?array
    {
        $stored = Cache::get(self::CACHE_PREFIX.$token);

        if (! is_array($stored)) {
            return null;
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $tenantId = (int) ($stored['tenant_id'] ?? 0);

        if ($orderId < 1 || $tenantId < 1) {
            return null;
        }

        return ['order_id' => $orderId, 'tenant_id' => $tenantId];
    }

    /**
     * @param  array{name?: string|null, email?: string|null, cpf?: string|null}  $buyer
     * @return array{name: string, document: string, email: string}
     */
    private function resolveConsumer(array $buyer, int $tenantId): array
    {
        $fake = FakeConsumerData::getForGateway($tenantId + mt_rand(1, 999999));

        $name = trim((string) ($buyer['name'] ?? ''));
        if ($name === '') {
            $name = $fake['name'];
        }

        $email = trim((string) ($buyer['email'] ?? ''));
        if ($email === '') {
            $email = 'pixgo+'.Str::uuid()->toString().'@pixgo.local';
        }

        $document = preg_replace('/\D/', '', (string) ($buyer['cpf'] ?? ''));
        if (strlen($document) < 11) {
            $document = $fake['document'];
        }

        return [
            'name' => $name,
            'document' => $document,
            'email' => $email,
        ];
    }
}
