<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\LinaOpenx\LinaOpenxPixAutoService;
use App\Support\GatewayInboundWebhookAuth;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Lina OpenX: payload é sinal — conclusão real em ProcessPaymentWebhook via reconsulta API.
 */
class LinaOpenxWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $paymentRequestId = $this->extractPaymentRequestId($payload);
        $paymentId = $this->extractPaymentId($payload);

        $consentId = $this->extractConsentId($payload);
        $recurringPaymentId = $this->extractRecurringPaymentId($payload);
        if (($paymentRequestId === null || $paymentRequestId === '')
            && ($consentId === null || $consentId === '')
            && ($recurringPaymentId === null || $recurringPaymentId === '')) {
            Log::info('LinaOpenxWebhook: paymentRequestId ausente', [
                'keys' => array_keys($payload),
            ]);

            return response()->json(['message' => 'paymentRequestId required'], 400);
        }
        if ($paymentRequestId === null || $paymentRequestId === '') {
            $paymentRequestId = $recurringPaymentId ?: $consentId;
        }

        $order = Order::query()
            ->where('gateway', 'linaopenx')
            ->where(function ($q) use ($paymentRequestId, $paymentId, $consentId, $recurringPaymentId) {
                $q->where('gateway_id', $paymentRequestId)
                    ->orWhere('metadata->lina_payment_request_id', $paymentRequestId);
                if ($paymentId !== null && $paymentId !== '') {
                    $q->orWhere('gateway_id', $paymentId)
                        ->orWhere('metadata->linaopenx_pix_auto_recurring_payment_id', $paymentId);
                }
                if ($consentId !== null && $consentId !== '') {
                    $q->orWhere('metadata->linaopenx_pix_auto_consent_id', $consentId)
                        ->orWhere('gateway_id', $consentId);
                }
                if ($recurringPaymentId !== null && $recurringPaymentId !== '') {
                    $q->orWhere('gateway_id', $recurringPaymentId)
                        ->orWhere('metadata->linaopenx_pix_auto_recurring_payment_id', $recurringPaymentId);
                }
            })
            ->first();

        if ($order === null) {
            // Aceita 200 para evitar reenvios agressivos; job não processará sem pedido.
            Log::info('LinaOpenxWebhook: order not found', [
                'payment_request_id' => $paymentRequestId,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['received' => true, 'order' => null]);
        }

        if ($this->requiresSignature() && ! GatewayInboundWebhookAuth::verifyHmacSha256Body(
            $request,
            'linaopenx',
            $order->tenant_id,
            'X-Lina-Signature',
            'X-Webhook-Signature',
            'X-Signature'
        )) {
            // Se secret configurado e inválido → 401; se secret não configurado, verifyHmac retorna false.
            // Quando não há secret: permitir (payload reconfirmado na API).
            $secret = GatewayInboundWebhookAuth::webhookSecret('linaopenx', $order->tenant_id);
            if ($secret !== null) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        $this->rememberPixAutoPaymentId($order, $paymentId);

        $eventType = strtoupper(trim((string) ($payload['type'] ?? $payload['event'] ?? $payload['paymentType'] ?? 'PAYMENT')));
        $rejectReason = $payload['rejectReason'] ?? $payload['reject_reason'] ?? null;
        $mappedStatus = 'pending';
        $event = 'order.pending';

        if ($rejectReason !== null && $rejectReason !== '') {
            $mappedStatus = 'rejected';
            $event = 'order.rejected';
        } elseif (str_contains($eventType, 'REJECT') || str_contains($eventType, 'FAIL')) {
            $mappedStatus = 'rejected';
            $event = 'order.rejected';
        } elseif (str_contains($eventType, 'CANCEL')) {
            $mappedStatus = 'cancelled';
            $event = 'order.cancelled';
        } else {
            // PAYMENT / CONSENT / updates — ProcessPaymentWebhook reconfirma via API
            $mappedStatus = 'paid';
            $event = 'order.paid';
        }

        PaymentWebhookDispatcher::dispatch(
            'linaopenx',
            (string) $order->gateway_id,
            $event,
            $mappedStatus,
            array_merge($payload, [
                'webhook_source' => 'linaopenx_webhook',
                'paymentRequestId' => $paymentRequestId,
                'paymentId' => $paymentId,
            ])
        );

        return response()->json(['received' => true]);
    }

    private function requiresSignature(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractPaymentRequestId(array $payload): ?string
    {
        foreach ([
            'paymentRequestId',
            'payment_request_id',
            'paymentLinkId',
            'payment_link_id',
            'id',
        ] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return $this->extractPaymentRequestId($data);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractPaymentId(array $payload): ?string
    {
        foreach (['paymentId', 'payment_id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return $this->extractPaymentId($data);
        }

        return null;
    }

    private function rememberPixAutoPaymentId(Order $order, ?string $paymentId): void
    {
        $paymentId = trim((string) $paymentId);
        if ($paymentId === '' || ! LinaOpenxPixAutoService::isPixAutoOrder($order)) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (trim((string) ($meta['linaopenx_pix_auto_recurring_payment_id'] ?? '')) !== '') {
            return;
        }

        $meta['linaopenx_pix_auto_recurring_payment_id'] = $paymentId;
        $order->update(['metadata' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractConsentId(array $payload): ?string
    {
        foreach (['consentId', 'consent_id', 'recurringConsentId', 'recurring_consent_id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return $this->extractConsentId($data);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractRecurringPaymentId(array $payload): ?string
    {
        foreach (['recurringPaymentId', 'recurring_payment_id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return $this->extractRecurringPaymentId($data);
        }

        return null;
    }
}
