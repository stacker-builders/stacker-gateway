<?php

namespace App\Http\Middleware;

use App\Support\CheckoutEmbed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! CheckoutEmbed::isEmbeddableRequest($request)) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // CSP só em produção — no local o Vite usa localhost:5173 e seria bloqueado.
        if (config('app.env') === 'production') {
            $cspParts = [
                "default-src 'self'",
                // CajuPay + carteiras: script, estilo (link), fonte, iframe, worker (ex.: Google Pay)
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://sdk.mercadopago.com https://http2.mlstatic.com https://*.mlstatic.com https://static.cloudflareinsights.com https://challenges.cloudflare.com https://*.challenges.cloudflare.com https://checkout.pagar.me https://connect.facebook.net https://www.googletagmanager.com https://analytics.tiktok.com https://cdn.cajupay.com.br https://*.cajupay.com.br https://pkgs.rinne.com.br https://*.rinne.com.br https://js.evervault.com https://*.evervault.com https://pay.google.com https://www.gstatic.com https://www.youtube.com https://youtube.com https://s.ytimg.com https://*.pagador.com.br https://www.paypal.com https://www.sandbox.paypal.com https://*.paypal.com https://*.paypalobjects.com",
                "script-src-elem 'self' 'unsafe-inline' https://js.stripe.com https://sdk.mercadopago.com https://http2.mlstatic.com https://*.mlstatic.com https://static.cloudflareinsights.com https://challenges.cloudflare.com https://*.challenges.cloudflare.com https://checkout.pagar.me https://connect.facebook.net https://www.googletagmanager.com https://analytics.tiktok.com https://cdn.cajupay.com.br https://*.cajupay.com.br https://pkgs.rinne.com.br https://*.rinne.com.br https://js.evervault.com https://*.evervault.com https://pay.google.com https://www.gstatic.com https://www.youtube.com https://youtube.com https://s.ytimg.com https://*.pagador.com.br https://www.paypal.com https://www.sandbox.paypal.com https://*.paypal.com https://*.paypalobjects.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.cajupay.com.br https://*.cajupay.com.br https://api.cajupay.com.br https://pay.google.com https://www.gstatic.com",
                "img-src 'self' data: https: blob:",
                "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com https://cdn.cajupay.com.br https://*.cajupay.com.br",
                "connect-src 'self' https://api.stripe.com https://api.mercadopago.com https://*.mercadopago.com https://*.mercadopago.com.br https://http2.mlstatic.com https://*.mlstatic.com https://api.mercadolibre.com https://www.mercadolibre.com https://*.mercadolibre.com https://viacep.com.br https://api.pagar.me https://connect.facebook.net https://www.facebook.com https://facebook.com https://graph.facebook.com https://*.facebook.com https://*.facebook.net https://*.a.run.app https://*.run.app https://*.on.aws https://www.googletagmanager.com https://analytics.tiktok.com https://api.cajupay.com.br https://*.cajupay.com.br https://pkgs.rinne.com.br https://api.rinne.com.br https://*.rinne.com.br https://js.evervault.com https://keys.evervault.com https://api.evervault.com https://*.evervault.com https://pay.google.com https://google.com https://www.google.com https://www.gstatic.com https://apple.com https://*.apple.com https://appleid.cdn-apple.com https://challenges.cloudflare.com https://*.challenges.cloudflare.com https://*.pagador.com.br https://*.cieloecommerce.cielo.com.br https://www.paypal.com https://www.sandbox.paypal.com https://*.paypal.com https://*.paypalobjects.com https://api.frankfurter.dev https://vimeo.com https://*.vimeo.com https://*.vimeocdn.com wss:",
                "frame-src 'self' https://js.stripe.com https://www.mercadopago.com https://*.mercadopago.com https://*.mercadopago.com.br https://www.mercadolibre.com https://*.mercadolibre.com https://www.youtube-nocookie.com https://youtube-nocookie.com https://www.youtube.com https://youtube.com https://api.cajupay.com.br https://cdn.cajupay.com.br https://*.cajupay.com.br https://pkgs.rinne.com.br https://*.rinne.com.br https://js.evervault.com https://ui-components.evervault.com https://*.evervault.com https://pay.google.com https://*.apple.com https://challenges.cloudflare.com https://*.challenges.cloudflare.com https://www.facebook.com https://*.facebook.com https://www.paypal.com https://www.sandbox.paypal.com https://*.paypal.com https://player.vimeo.com https://vimeo.com https://*.vimeo.com https://fast.wistia.net https://fast.wistia.com https://*.wistia.net https://*.wistia.com https://www.loom.com https://loom.com https://*.loom.com",
                "worker-src 'self' blob:",
                "media-src 'self' https: blob:",
            ];

            if (CheckoutEmbed::isEmbeddableRequest($request)) {
                $cspParts[] = 'frame-ancestors *';
            }

            $response->headers->set('Content-Security-Policy', implode('; ', $cspParts));
        }

        if (config('app.env') === 'production' && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
