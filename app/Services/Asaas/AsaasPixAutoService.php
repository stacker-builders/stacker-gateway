<?php

namespace App\Services\Asaas;

use App\Gateways\Asaas\AsaasDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;

class AsaasPixAutoService
{
    public const GATEWAY_SLUG = 'asaas';

    public function __construct(
        private readonly AsaasDriver $driver = new AsaasDriver()
    ) {}

    public static function frequencyFromInterval(?string $interval): string
    {
        return match ($interval) {
            SubscriptionPlan::INTERVAL_WEEKLY => 'WEEKLY',
            SubscriptionPlan::INTERVAL_QUARTERLY => 'QUARTERLY',
            SubscriptionPlan::INTERVAL_SEMI_ANNUAL => 'SEMIANNUALLY',
            SubscriptionPlan::INTERVAL_ANNUAL => 'ANNUALLY',
            SubscriptionPlan::INTERVAL_MONTHLY => 'MONTHLY',
            default => throw new \RuntimeException('Asaas: o PIX automático não aceita plano vitalício.'),
        };
    }

    /**
     * Primeiro vencimento recorrente. O QR imediato cobra agora; a vigência começa no próximo ciclo.
     */
    public function startDateForPlan(SubscriptionPlan $plan, mixed $periodEnd = null): string
    {
        $date = $periodEnd instanceof \DateTimeInterface
            ? Carbon::parse($periodEnd)->startOfDay()
            : now()->startOfDay()->add(
                match ($plan->interval) {
                    SubscriptionPlan::INTERVAL_WEEKLY => \DateInterval::createFromDateString('1 week'),
                    SubscriptionPlan::INTERVAL_QUARTERLY => \DateInterval::createFromDateString('3 months'),
                    SubscriptionPlan::INTERVAL_SEMI_ANNUAL => \DateInterval::createFromDateString('6 months'),
                    SubscriptionPlan::INTERVAL_ANNUAL => \DateInterval::createFromDateString('1 year'),
                    default => \DateInterval::createFromDateString('1 month'),
                }
            );
        if ($date->lte(now()->startOfDay())) {
            $date = now()->addDay()->startOfDay();
        }

        return $date->toDateString();
    }

    /**
     * Dias úteis entre hoje e o vencimento, sem contar o dia de hoje.
     */
    public function businessDaysUntil(\DateTimeInterface $dueDate): int
    {
        $cursor = now()->startOfDay();
        $due = Carbon::parse($dueDate)->startOfDay();
        if ($due->lte($cursor)) {
            return 0;
        }
        $days = 0;
        while ($cursor->lt($due)) {
            $cursor = $cursor->copy()->addDay();
            if ($cursor->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }

    public function withinInstructionWindow(\DateTimeInterface $dueDate): bool
    {
        $days = $this->businessDaysUntil($dueDate);

        return $days >= 2 && $days <= 10;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string, phone?: string, address?: array}  $consumer
     * @return array{authorization_id: string, customer_id: string, copy_paste: ?string, qrcode: ?string, status: string, payment_id: ?string}
     */
    public function createCheckoutAuthorization(
        array $credentials,
        array $consumer,
        Order $order,
        SubscriptionPlan $plan,
        float $amount,
        mixed $periodEnd = null
    ): array {
        if ($plan->isLifetime()) {
            throw new \RuntimeException('Asaas: o PIX automático não aceita plano vitalício.');
        }

        return $this->driver->createPixAutomaticAuthorization(
            $credentials,
            $consumer,
            (string) $order->id,
            $amount,
            self::frequencyFromInterval($plan->interval),
            $this->startDateForPlan($plan, $periodEnd),
            (string) ($order->product?->name ?? 'Assinatura')
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function authorizationIsActive(array $credentials, string $authorizationId): bool
    {
        $data = $this->driver->getPixAutomaticAuthorization($credentials, $authorizationId);

        return strtoupper((string) ($data['status'] ?? '')) === 'ACTIVE';
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function createRecurringPayment(
        array $credentials,
        string $customerId,
        string $authorizationId,
        float $amount,
        string $dueDate,
        string $externalId
    ): string {
        return $this->driver->createPixAutomaticPayment(
            $credentials,
            $customerId,
            $authorizationId,
            $amount,
            $dueDate,
            $externalId
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForTenant(?int $tenantId): ?array
    {
        $credential = GatewayCredential::resolveForPayment($tenantId, self::GATEWAY_SLUG);
        if ($credential === null) {
            return null;
        }
        $credentials = $credential->getDecryptedCredentials();
        if (trim((string) ($credentials['api_key'] ?? '')) === '') {
            return null;
        }

        return $credentials;
    }

    public function findOrderByAuthorization(string $authorizationId): ?Order
    {
        $authorizationId = trim($authorizationId);
        if ($authorizationId === '') {
            return null;
        }

        return Order::query()
            ->where('gateway', self::GATEWAY_SLUG)
            ->where('metadata->asaas_pix_auto_authorization_id', $authorizationId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    public function findOrderForPayment(array $payment): ?Order
    {
        $paymentId = trim((string) ($payment['id'] ?? ''));
        if ($paymentId !== '') {
            $byId = Order::query()
                ->where('gateway', self::GATEWAY_SLUG)
                ->where('gateway_id', $paymentId)
                ->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        $external = trim((string) ($payment['externalReference'] ?? ''));
        if ($external !== '' && ctype_digit($external)) {
            $byReference = Order::query()
                ->where('gateway', self::GATEWAY_SLUG)
                ->where('id', (int) $external)
                ->first();
            if ($byReference !== null) {
                return $byReference;
            }
        }

        $authorizationId = trim((string) ($payment['pixAutomaticAuthorizationId'] ?? ''));
        if ($authorizationId !== '') {
            $byAuthorization = $this->findOrderByAuthorization($authorizationId);
            if ($byAuthorization !== null && ($byAuthorization->gateway_id === null || $byAuthorization->gateway_id === '' || $byAuthorization->gateway_id === $paymentId)) {
                return $byAuthorization;
            }
        }

        $customerId = trim((string) ($payment['customer'] ?? ''));
        if ($customerId === '') {
            return null;
        }

        return Order::query()
            ->where('gateway', self::GATEWAY_SLUG)
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->where('metadata->asaas_customer_id', $customerId)
            ->where(function ($query) {
                $query->whereNull('gateway_id')->orWhere('gateway_id', '');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $authorization
     */
    public function applyAuthorizationEvent(string $event, array $authorization): void
    {
        $authorizationId = trim((string) ($authorization['id'] ?? ''));
        $order = $this->findOrderByAuthorization($authorizationId);
        if ($order === null) {
            return;
        }

        $status = match ($event) {
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_ACTIVATED' => 'ACTIVE',
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_REFUSED' => 'REFUSED',
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_CANCELLED' => 'CANCELLED',
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_EXPIRED' => 'EXPIRED',
            default => strtoupper((string) ($authorization['status'] ?? 'CREATED')),
        };
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['asaas_pix_auto_status'] = $status;
        $order->update(['metadata' => $meta]);

        if ($status !== 'ACTIVE') {
            return;
        }

        $subscription = Subscription::query()
            ->where('user_id', $order->user_id)
            ->where('product_id', $order->product_id)
            ->where('subscription_plan_id', $order->subscription_plan_id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->orderByDesc('id')
            ->first();
        if ($subscription === null) {
            return;
        }
        if (trim((string) $subscription->gateway_subscription_id) === '') {
            $subscription->update(['gateway_subscription_id' => $authorizationId]);
        }
        app(AsaasPixAutoRenewalService::class)->ensureNextPayment($subscription->fresh() ?? $subscription, $order);
    }
}
