<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class GatewayWebhookUrl
{
    public static function forGateway(string $gatewaySlug): string
    {
        $path = self::pathForGateway($gatewaySlug);
        $base = rtrim(PublicAppUrl::base(), '/');

        return $base.$path;
    }

    public static function pathForGateway(string $gatewaySlug): string
    {
        return match ($gatewaySlug) {
            'efi' => '/webhooks/gateways/efi/pix',
            'efi.pix' => '/webhooks/gateways/efi/pix',
            'efi.notification' => '/webhooks/gateways/efi/notification',
            'spacepag' => '/webhooks/gateways/spacepag',
            'cajupay' => '/webhooks/gateways/cajupay',
            'cajupay.checkout' => '/webhooks/gateways/cajupay/checkout',
            'cajupay.payout' => '/webhooks/gateways/cajupay/payout',
            'pushinpay' => '/webhooks/gateways/pushinpay',
            'mercadopago' => '/webhooks/gateways/mercadopago',
            'pagarme' => '/webhooks/gateways/pagarme',
            'cielo' => '/webhooks/gateways/cielo',
            'onlyup' => '/webhooks/gateways/onlyup',
            'bspay' => '/webhooks/gateways/bspay',
            'linaopenx' => '/webhooks/gateways/linaopenx',
            // Base URL Cash In (a Versell anexa /pix automaticamente ao notificar)
            'versell' => '/webhooks/gateways/versell',
            'versell.pix' => '/webhooks/gateways/versell/pix',
            'versell.transfer' => '/webhooks/gateways/versell/transfer',
            'versell.cashout' => '/webhooks/gateways/versell/cashout',
            'versell.infractions' => '/webhooks/gateways/versell/infractions',
            // Base Pix Automático (Versell anexa /rec e /cobr)
            'versell.pix_auto' => '/webhooks/gateways/versell/pix-automatico',
            'versell.pix_auto.rec' => '/webhooks/gateways/versell/pix-automatico/rec',
            'versell.pix_auto.cobr' => '/webhooks/gateways/versell/pix-automatico/cobr',
            default => '/webhooks/gateways/'.$gatewaySlug,
        };
    }

    /**
     * Monta URL absoluta a partir de um nome de rota de webhook, sem herdar APP_URL local.
     */
    public static function forRoute(string $routeName, array $parameters = []): ?string
    {
        if (! Route::has($routeName)) {
            return null;
        }

        $relative = route($routeName, $parameters, false);
        if (! is_string($relative) || $relative === '') {
            return null;
        }

        if (! str_starts_with($relative, '/')) {
            $relative = '/'.$relative;
        }

        return rtrim(PublicAppUrl::base(), '/').$relative;
    }
}
