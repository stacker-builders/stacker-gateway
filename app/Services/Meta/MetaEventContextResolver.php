<?php

namespace App\Services\Meta;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;

class MetaEventContextResolver
{
    public function forCheckoutSession(CheckoutSession $session, array $overrides = []): MetaEventContext
    {
        $session->loadMissing('product');

        $product = $session->product;
        $contentIds = [];
        if ($session->checkout_slug) {
            $contentIds[] = (string) $session->checkout_slug;
        } elseif ($product?->checkout_slug) {
            $contentIds[] = (string) $product->checkout_slug;
        }

        $value = isset($overrides['value']) ? (float) $overrides['value'] : null;
        $currency = isset($overrides['currency']) && is_string($overrides['currency'])
            ? strtoupper(trim($overrides['currency']))
            : 'BRL';

        [$firstName, $lastName] = $this->splitName($session->name ?? ($overrides['name'] ?? null));

        return new MetaEventContext(
            fbp: $this->firstNonEmpty($overrides['fbp'] ?? null, $session->meta_fbp),
            fbc: $this->resolveFbcForSession($session, $overrides),
            clientIp: $session->customer_ip,
            clientUserAgent: $this->firstNonEmpty($overrides['user_agent'] ?? null, $session->meta_user_agent),
            eventSourceUrl: $this->firstNonEmpty($overrides['event_source_url'] ?? null, $session->meta_page_url),
            email: $session->email ?? ($overrides['email'] ?? null),
            phone: $session->phone ?? ($overrides['phone'] ?? null),
            firstName: $firstName,
            lastName: $lastName,
            externalId: $session->email ? (string) $session->email : null,
            value: $value,
            currency: $currency,
            contentIds: $this->normalizeContentIds($overrides['content_ids'] ?? $contentIds),
            contentName: $this->firstNonEmpty($overrides['content_name'] ?? null, $product?->name),
            numItems: 1,
            eventTime: isset($overrides['event_time']) ? (int) $overrides['event_time'] : time(),
        );
    }

    public function forOrder(Order $order, array $overrides = []): MetaEventContext
    {
        $order->loadMissing(['product', 'user', 'checkoutSession', 'orderItems']);

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $session = $order->checkoutSession;

        $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];

        [$firstName, $lastName] = $this->splitName($order->user?->name ?? ($meta['customer_name'] ?? null));

        $contentIds = [(string) $order->id];
        if ($order->product?->checkout_slug) {
            $contentIds[] = (string) $order->product->checkout_slug;
        }

        $sessionFbc = $session ? $this->resolveFbcForSession($session, []) : null;

        return new MetaEventContext(
            fbp: $this->firstNonEmpty($overrides['fbp'] ?? null, $meta['fbp'] ?? null, $session?->meta_fbp),
            fbc: $this->firstNonEmpty($overrides['fbc'] ?? null, $meta['fbc'] ?? null, $sessionFbc),
            clientIp: $order->customer_ip ?: ($session?->customer_ip),
            clientUserAgent: $this->firstNonEmpty($overrides['user_agent'] ?? null, $meta['user_agent'] ?? null, $session?->meta_user_agent),
            eventSourceUrl: $this->firstNonEmpty(
                $overrides['event_source_url'] ?? null,
                $meta['event_source_url'] ?? null,
                $session?->meta_page_url,
            ),
            email: $order->email ?: ($order->user?->email ?? null),
            phone: $order->phone ?: null,
            firstName: $firstName,
            lastName: $lastName,
            city: isset($shipping['city']) ? (string) $shipping['city'] : null,
            state: isset($shipping['state']) ? (string) $shipping['state'] : null,
            country: isset($shipping['country']) ? (string) $shipping['country'] : 'br',
            zip: isset($shipping['cep']) ? (string) $shipping['cep'] : (isset($shipping['zip']) ? (string) $shipping['zip'] : null),
            externalId: $order->user_id ? (string) $order->user_id : ($order->email ?: null),
            value: isset($overrides['value']) ? (float) $overrides['value'] : (float) $order->amount,
            currency: 'BRL',
            contentIds: $this->normalizeContentIds($overrides['content_ids'] ?? $contentIds),
            contentName: $this->firstNonEmpty($overrides['content_name'] ?? null, $order->product?->name),
            numItems: max(1, $order->orderItems->count()),
            eventTime: (int) ($order->updated_at?->timestamp ?? time()),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resolveFbcForSession(CheckoutSession $session, array $overrides): ?string
    {
        $fbc = $this->firstNonEmpty($overrides['fbc'] ?? null, $session->meta_fbc);
        if ($fbc !== null) {
            return $fbc;
        }

        $fbclid = is_string($session->meta_fbclid) ? trim($session->meta_fbclid) : '';
        if ($fbclid !== '') {
            return CheckoutSession::buildFbcFromFbclid($fbclid);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>|null  $raw
     * @return array<int, string>
     */
    private function normalizeContentIds(?array $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            $raw
        ), fn ($id) => $id !== ''));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(mixed $name): array
    {
        if (! is_string($name) || trim($name) === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
