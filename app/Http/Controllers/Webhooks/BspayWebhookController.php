<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Services\Bspay\BspayMedService;
use App\Services\MerchantWithdrawalService;
use App\Support\GatewayInboundWebhookAuth;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BspayWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $this->eventName($request);

        if (str_starts_with($event, 'chargeback.')) {
            return $this->handleChargeback($request, $event);
        }

        $transactionId = $this->transactionId($request);
        if ($transactionId === null) {
            return response()->json(['message' => 'transaction_id required'], 400);
        }

        if (in_array($event, ['cashout.confirmed', 'cashout.failed', 'cashout.refunded'], true)) {
            return $this->handleCashout($request, $event, $transactionId);
        }

        if ($event === 'cashin.refunded') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $isPaid = $event === 'cashin.confirmed' || $this->status($request) === 'confirmed';
        if (! $isPaid) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $order = $this->findBspayOrder($request, $transactionId);

        if ($order === null) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyBspay($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        PaymentWebhookDispatcher::dispatch(
            'bspay',
            (string) $order->gateway_id,
            'order.paid',
            'paid',
            array_merge($request->all(), ['webhook_source' => 'bspay_webhook'])
        );

        return response()->json(['received' => true]);
    }

    private function handleChargeback(Request $request, string $event): JsonResponse
    {
        $transactionId = $this->transactionId($request);
        $order = $this->findBspayOrder($request, $transactionId);

        if ($order === null) {
            Log::warning('Bspay webhook: order not found for chargeback', [
                'event' => $event,
                'transaction_id' => $transactionId,
                'infraction_id' => $this->scalar($request, 'infraction_id'),
            ]);

            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyBspay($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            app(BspayMedService::class)->handleWebhookEvent($event, $request->all(), $order);
        } catch (\Throwable $e) {
            Log::warning('Bspay webhook: falha ao processar MED', [
                'event' => $event,
                'order_id' => $order->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return response()->json(['message' => 'MED processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function findBspayOrder(Request $request, ?string $transactionId): ?Order
    {
        if ($transactionId !== null && $transactionId !== '') {
            $byTx = Order::query()
                ->where('gateway', 'bspay')
                ->where('gateway_id', $transactionId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        $externalId = $this->scalar($request, 'external_id');
        if ($externalId !== null) {
            $byExternal = Order::query()
                ->where('gateway', 'bspay')
                ->where('id', $externalId)
                ->first();
            if ($byExternal !== null) {
                return $byExternal;
            }
        }

        $e2e = $this->scalar($request, 'e2e_id');
        if ($e2e !== null) {
            return Order::query()
                ->where('gateway', 'bspay')
                ->where(function ($q) use ($e2e) {
                    $q->where('metadata->e2e_id', $e2e)
                        ->orWhere('metadata->end_to_end_id', $e2e);
                })
                ->first();
        }

        return null;
    }

    private function handleCashout(Request $request, string $event, string $transactionId): JsonResponse
    {
        $withdrawal = $this->findBspayWithdrawal($request, $transactionId);
        if ($withdrawal === null) {
            Log::warning('Bspay webhook: withdrawal not found for cashout', [
                'transaction_id' => $transactionId,
                'event' => $event,
                'external_id' => $this->scalar($request, 'external_id'),
            ]);

            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyBspay($request, $withdrawal->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($event === 'cashout.confirmed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());
        } elseif (in_array($event, ['cashout.failed', 'cashout.refunded'], true)
            && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout BSPay falhou (webhook '.$event.').'
            );
        }

        return response()->json(['received' => true]);
    }

    private function findBspayWithdrawal(Request $request, string $transactionId): ?Withdrawal
    {
        $byTx = Withdrawal::query()
            ->where('payout_provider', 'bspay')
            ->where('payout_external_id', $transactionId)
            ->first();
        if ($byTx !== null) {
            return $byTx;
        }

        $externalId = $this->scalar($request, 'external_id');
        if ($externalId === null || ! ctype_digit($externalId)) {
            return null;
        }

        return Withdrawal::query()
            ->where('payout_provider', 'bspay')
            ->where('id', (int) $externalId)
            ->first();
    }

    private function eventName(Request $request): string
    {
        foreach (['X-Webhook-Event', 'X-BSPay-Event'] as $headerName) {
            $header = $request->header($headerName);
            if (is_string($header) && trim($header) !== '') {
                return strtolower(trim($header));
            }
        }

        $event = $this->scalar($request, 'event');

        return $event !== null ? strtolower($event) : '';
    }

    private function transactionId(Request $request): ?string
    {
        return $this->scalar($request, 'transaction_id');
    }

    private function status(Request $request): string
    {
        $status = $this->scalar($request, 'status');

        return $status !== null ? strtolower($status) : '';
    }

    private function scalar(Request $request, string $key): ?string
    {
        foreach ([$key, 'data.'.$key] as $path) {
            if (! $request->has($path)) {
                continue;
            }
            $value = $request->input($path);
            if (is_string($value)) {
                $trim = trim($value);

                return $trim !== '' ? $trim : null;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
