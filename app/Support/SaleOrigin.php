<?php

namespace App\Support;

use App\Models\Order;

final class SaleOrigin
{
    public const CHECKOUT_PUBLIC = 'checkout_public';

    public const CHECKOUT_DIRECT = 'checkout_direct';

    public const AFFILIATE_LINK = 'affiliate_link';

    public const MARKETPLACE = 'marketplace';

    public const UPSELL = 'upsell';

    public const ORDER_BUMP = 'order_bump';

    public const API = 'api';

    public const API_CHECKOUT = 'api_checkout';

    public const PIXGO = 'pixgo';

    public const MEMBER_MODULE_RENEWAL = 'member_module_renewal';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::CHECKOUT_PUBLIC => 'Checkout Público',
            self::CHECKOUT_DIRECT => 'Checkout Direto',
            self::AFFILIATE_LINK => 'Link do Afiliado',
            self::MARKETPLACE => 'Marketplace',
            self::UPSELL => 'Upsell',
            self::ORDER_BUMP => 'Order Bump',
            self::API => 'API',
            self::API_CHECKOUT => 'Checkout Personalizado',
            self::PIXGO => 'PixGO',
            self::MEMBER_MODULE_RENEWAL => 'Renovação de módulo',
        ];
    }

    public static function label(?string $origin): string
    {
        if ($origin === null || $origin === '') {
            return '—';
        }

        return self::labels()[$origin] ?? $origin;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function resolveForOrder(Order $order, array $context = []): string
    {
        if (! empty($context['sale_origin']) && is_string($context['sale_origin'])) {
            return $context['sale_origin'];
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $source = is_string($meta['source'] ?? null) ? $meta['source'] : null;
        if ($source === 'pixgo') {
            return self::PIXGO;
        }
        if (in_array($source, ['api', 'api_checkout_pro'], true)) {
            return $source === 'api_checkout_pro' ? self::API_CHECKOUT : self::API;
        }

        if ($order->sale_origin) {
            return (string) $order->sale_origin;
        }

        if (! empty($meta['sale_origin']) && is_string($meta['sale_origin'])) {
            return $meta['sale_origin'];
        }

        if ($order->isAffiliateSale() || ! empty($meta['affiliate_enrollment_id'])) {
            return self::AFFILIATE_LINK;
        }

        if ($order->api_checkout_session_id) {
            return self::API_CHECKOUT;
        }

        if ($order->api_application_id) {
            return self::API;
        }

        if (! empty($meta[self::MEMBER_MODULE_RENEWAL])) {
            return self::MEMBER_MODULE_RENEWAL;
        }

        if (! empty($meta['upsell']) || ! empty($meta['upsell_token']) || ! empty($context['upsell'])) {
            return self::UPSELL;
        }

        if (! empty($meta['order_bump']) || ! empty($context['order_bump'])) {
            return self::ORDER_BUMP;
        }

        if (! empty($meta['from_showcase']) || ! empty($meta['marketplace'])) {
            return self::MARKETPLACE;
        }

        if (! empty($meta['checkout_direct'])) {
            return self::CHECKOUT_DIRECT;
        }

        return self::CHECKOUT_PUBLIC;
    }

    public static function applyToOrder(Order $order, array $context = []): void
    {
        $origin = self::resolveForOrder($order, $context);
        $order->sale_origin = $origin;

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['sale_origin'] = $origin;
        $order->metadata = $meta;
    }
}
