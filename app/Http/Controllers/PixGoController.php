<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Support\GatewayPaymentCredentials;
use App\Models\Order;
use App\Models\User;
use App\Services\MerchantOperationalGuard;
use App\Services\MinimumChargeService;
use App\Services\PixGo\PixGoChargeService;
use App\Services\PixGoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PixGoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertPixGoAvailable($request);

        $user = $request->user();
        $tenantId = (int) ($user->tenant_id ?? $user->id);

        return Inertia::render('PixGo/Index', [
            'sidebar_label' => PixGoAccess::sidebarLabel(),
            'minimum_charge_brl' => app(MinimumChargeService::class)->platformMinimumBrlForTenant($tenantId),
            'maximum_charge_brl' => app(MinimumChargeService::class)->platformMaximumBrlForTenant($tenantId),
        ]);
    }

    public function charge(Request $request, PixGoChargeService $service): RedirectResponse
    {
        $this->assertPixGoAvailable($request);

        $user = $request->user();
        $tenantId = (int) ($user->tenant_id ?? $user->id);

        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1', 'max:9999999'],
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:255'],
            'buyer.email' => ['nullable', 'email', 'max:255'],
            'buyer.cpf' => ['nullable', 'string', 'max:14'],
        ]);

        $amountBrl = round(((int) $validated['amount_cents']) / 100, 2);
        $buyer = $validated['buyer'] ?? [];

        $result = $service->charge($tenantId, $amountBrl, $buyer);

        return redirect()->route('pixgo.cobranca', ['token' => $result['token']]);
    }

    public function showCharge(Request $request, string $token, PixGoChargeService $service): Response
    {
        $this->assertPixGoAvailable($request);

        $resolved = $this->resolveAuthorizedToken($request, $token, $service);
        $order = Order::findOrFail($resolved['order_id']);
        $cached = \Illuminate\Support\Facades\Cache::get(PixGoChargeService::CACHE_PREFIX.$token);
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $copyPaste = (is_array($cached) ? ($cached['copy_paste'] ?? null) : null)
            ?? ($meta['pix_copy_paste'] ?? null)
            ?? ($meta['copy_paste'] ?? null);
        if (is_string($copyPaste)) {
            $copyPaste = trim($copyPaste);
        } else {
            $copyPaste = null;
        }

        return Inertia::render('PixGo/Pix', [
            'token' => $token,
            'order_id' => $order->id,
            'qrcode' => (is_array($cached) ? ($cached['qrcode'] ?? null) : null)
                ?? ($meta['pix_qrcode'] ?? null)
                ?? ($meta['qrcode'] ?? null),
            'copy_paste' => $copyPaste,
            'amount' => (float) $order->amount,
            'amount_formatted' => 'R$ '.number_format((float) $order->amount, 2, ',', '.'),
            'status' => $order->status,
            'created_at' => $order->created_at?->timestamp ?? time(),
            'expiry_seconds' => 86400,
            'sidebar_label' => PixGoAccess::sidebarLabel(),
        ]);
    }

    public function status(Request $request, PixGoChargeService $service): JsonResponse
    {
        $this->assertPixGoAvailable($request);

        $token = $request->query('token');
        if (! is_string($token) || $token === '') {
            return response()->json(['status' => 'invalid'], 400);
        }

        $resolved = $this->resolveAuthorizedToken($request, $token, $service);
        $order = Order::find($resolved['order_id']);

        if (! $order) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($order->status === 'pending') {
            $this->pollGatewayStatus($order);
            $order->refresh();
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $reconcileUntil = isset($meta['reconcile_until']) ? strtotime((string) $meta['reconcile_until']) : null;
        $expired = $reconcileUntil !== false && $reconcileUntil !== null && time() > $reconcileUntil;

        return response()->json([
            'status' => $order->status,
            'order_id' => $order->status === 'completed' ? $order->id : null,
            'amount_formatted' => 'R$ '.number_format((float) $order->amount, 2, ',', '.'),
            'expired' => $expired && $order->status === 'pending',
        ]);
    }

    private function assertPixGoAvailable(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $tenantId = (int) ($user->tenant_id ?: $user->id);
        if (! PixGoAccess::effectiveForTenant($tenantId > 0 ? $tenantId : null)) {
            throw new NotFoundHttpException;
        }

        MerchantOperationalGuard::assertCanOperateSellerPanel($user);
    }

    /**
     * @return array{order_id: int, tenant_id: int}
     */
    private function resolveAuthorizedToken(Request $request, string $token, PixGoChargeService $service): array
    {
        $resolved = $service->resolveToken($token);
        if ($resolved === null) {
            abort(404);
        }

        $user = $request->user();
        $tenantId = (int) ($user->tenant_id ?? $user->id);

        if ($resolved['tenant_id'] !== $tenantId) {
            abort(403);
        }

        return $resolved;
    }

    private function pollGatewayStatus(Order $order): void
    {
        $gatewaySlug = is_string($order->gateway) ? $order->gateway : '';
        $transactionId = is_string($order->gateway_id) ? $order->gateway_id : (string) $order->gateway_id;

        if ($gatewaySlug === '' || $transactionId === '') {
            return;
        }

        try {
            $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, $gatewaySlug, $order);
            if ($credentials === null) {
                return;
            }

            $driver = GatewayRegistry::driver($gatewaySlug);
            $efiNeedsCert = $gatewaySlug === 'efi' && empty($credentials['certificate_path'] ?? '');

            if (! $driver || $efiNeedsCert) {
                return;
            }

            $apiStatus = $driver->getTransactionStatus($transactionId, $credentials);

            if ($apiStatus === 'paid') {
                ProcessPaymentWebhook::dispatchSync(
                    $gatewaySlug,
                    $transactionId,
                    $gatewaySlug === 'cajupay' ? 'checkout.payment.paid' : 'order.paid',
                    'paid',
                    [
                        'source' => 'pixgo_status_poll',
                        'webhook_source' => $gatewaySlug === 'cajupay' ? 'pixgo_status_poll' : '',
                    ]
                );
            }
        } catch (\Throwable) {
            // polling silencioso
        }
    }
}
