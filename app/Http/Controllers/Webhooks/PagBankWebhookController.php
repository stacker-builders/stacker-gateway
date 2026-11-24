<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\GatewayRegistry;
use App\Gateways\PagBank\PagBankDriver;
use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagBankWebhookController extends Controller
{
    private const SLUG = 'pagbank';

    /**
     * POST /webhooks/gateways/pagbank
     *
     * O corpo repete o pedido ou a cobrança. A confirmação é GET /charges/{id}.
     * Se vier x-authenticity-token, valida SHA-256 de "{token}-{body}".
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = $request->all();
        if (! is_array($payload)) {
            return response()->json(['received' => true]);
        }

        [$chargeId, $orderId, $referenceId] = $this->resolveIds($payload);
        $order = $this->findOrder($chargeId, $orderId, $referenceId);
        if (! $order) {
            Log::debug('PagBankWebhook: order not found', [
                'charge_id' => $chargeId,
                'order_id' => $orderId,
                'reference_id' => $referenceId,
            ]);

            return response()->json(['received' => true]);
        }

        $transactionId = $chargeId !== '' ? $chargeId : trim((string) $order->gateway_id);
        if ($transactionId === '') {
            return response()->json(['received' => true]);
        }

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, self::SLUG);
        $token = '';
        if ($credential) {
            $token = trim((string) ($credential->getDecryptedCredentials()['token'] ?? ''));
        }
        if ($token !== '' && ! $this->verifyAuthenticity($raw, $request->header('x-authenticity-token'), $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = 'order.pending';
        $status = 'pending';
        $driver = GatewayRegistry::driver(self::SLUG);
        if ($credential && $driver instanceof PagBankDriver && $token !== '') {
            $outcome = $driver->chargeOutcome($transactionId, $credential->getDecryptedCredentials());
            if ($outcome === 'paid') {
                $event = 'order.paid';
                $status = 'paid';
            } elseif ($outcome === 'refunded') {
                $event = 'order.refunded';
                $status = 'refunded';
            } elseif ($outcome === 'cancelled') {
                $event = 'order.rejected';
                $status = 'rejected';
            }
        }

        PaymentWebhookDispatcher::dispatch(self::SLUG, $transactionId, $event, $status, $payload);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveIds(array $payload): array
    {
        $id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        $chargeId = str_starts_with($id, 'CHAR_') ? $id : '';
        $orderId = str_starts_with($id, 'ORDE_') ? $id : '';
        $charges = $payload['charges'] ?? null;
        if ($chargeId === '' && is_array($charges) && isset($charges[0]) && is_array($charges[0])) {
            $cid = $charges[0]['id'] ?? null;
            if (is_string($cid) && $cid !== '') {
                $chargeId = $cid;
            }
        }
        $reference = isset($payload['reference_id']) && is_string($payload['reference_id'])
            ? $payload['reference_id']
            : '';

        return [$chargeId, $orderId, $reference];
    }

    private function findOrder(string $chargeId, string $orderId, string $referenceId): ?Order
    {
        if ($chargeId !== '') {
            $order = Order::query()->where('gateway', self::SLUG)->where('gateway_id', $chargeId)->first();
            if ($order) {
                return $order;
            }
        }
        if ($orderId !== '') {
            $order = Order::query()->where('gateway', self::SLUG)->where('metadata->pagbank_order_id', $orderId)->first();
            if ($order) {
                return $order;
            }
        }
        if ($referenceId !== '') {
            return Order::query()->where('gateway', self::SLUG)->where('id', $referenceId)->first();
        }

        return null;
    }

    private function verifyAuthenticity(string $rawBody, mixed $header, string $token): bool
    {
        if (! is_string($header) || trim($header) === '') {
            return true;
        }
        $expected = hash('sha256', $token.'-'.$rawBody);
        if (hash_equals($expected, strtolower(trim($header)))) {
            return true;
        }
        Log::warning('PagBankWebhook: invalid x-authenticity-token');

        return false;
    }
}
