<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorLoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Typos comuns → painel operador (evita 404)
Route::redirect('/pataform/login', '/plataforma/login', 302);
Route::redirect('/platfform/login', '/plataforma/login', 302);
Route::get('/admin', function () {
    $user = auth()->user();
    if ($user) {
        return redirect($user->defaultAuthenticatedHomeUrl());
    }

    return redirect('/login');
})->name('admin.portal');

// Storage: servir arquivos de storage/app/public (sem symlink) — deve ser uma das primeiras rotas
Route::get('/storage/{path}', \App\Http\Controllers\StorageServeController::class)
    ->where('path', '.+')
    ->name('storage.serve');

// Instalador: fallback quando o servidor envia /install para o Laravel (ex: document root diferente de public/)
Route::any('/install', [\App\Http\Controllers\InstallServeController::class, '__invoke'])
    ->defaults('path', null)
    ->middleware(['installer.access', 'throttle:30,1']);
Route::any('/install/{path}', [\App\Http\Controllers\InstallServeController::class, '__invoke'])
    ->where('path', '.+')
    ->middleware(['installer.access', 'throttle:30,1']);

Route::get('/docker-setup', [\App\Http\Controllers\DockerSetupController::class, 'show'])->name('docker-setup');
Route::post('/docker-setup', [\App\Http\Controllers\DockerSetupController::class, 'store'])->middleware('throttle:10,1');

Route::get('/stacker/licenca', [\App\Http\Controllers\StackerLicenseController::class, 'support'])
    ->name('stacker.license.support');

// Favicon: evita 404 no console quando o navegador solicita /favicon.ico
Route::get('/favicon.ico', function () {
    return redirect('/images/favicon.png', 302);
});

// PWA Painel: manifest e service worker
Route::get('/manifest.json', [\App\Http\Controllers\PanelPwaController::class, 'manifest'])->name('panel.pwa.manifest');
Route::get('/painel/push/client-config.json', [\App\Http\Controllers\Platform\AppPushController::class, 'clientConfig'])->name('panel.pwa.push-client-config');
Route::get('/painel-sw.js', function () {
    $path = public_path('painel-sw.js');
    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        // Evita cache agressivo do SW (senão o browser não busca a versão nova e mantém o SW antigo ativo).
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->name('panel.pwa.sw');

Route::get('/firebase-messaging-sw.js', function () {
    $path = public_path('firebase-messaging-sw.js');
    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->name('panel.pwa.firebase-sw');

Route::get('/politica-privacidade', [\App\Http\Controllers\LegalPagesController::class, 'privacy'])->name('legal.privacy');
Route::get('/termos-de-uso', [\App\Http\Controllers\LegalPagesController::class, 'terms'])->name('legal.terms');

Route::get('/', function (\Illuminate\Http\Request $request) {
    $resolved = app(\App\Services\MemberAreaResolver::class)->resolve($request);
    if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
        $request->attributes->set('member_area_product', $resolved['product']);
        $request->attributes->set('member_area_access_type', $resolved['access_type']);
        $request->attributes->set('member_area_slug', $resolved['slug']);

        if (! $request->user()) {
            return redirect()->to('/login')->with('error', 'Faça login para acessar a área de membros.');
        }

        if (! app(\App\Services\MemberAccessGrantService::class)->userHasMemberAreaAccess($request->user(), $resolved['product'])) {
            return redirect()->route('checkout.show', ['slug' => $resolved['product']->checkout_slug])
                ->with('error', 'Você não tem acesso a esta área. Adquira o produto para continuar.');
        }

        return app()->call(\App\Http\Controllers\MemberAreaAppController::class.'@show', [
            'request' => $request,
            'slug' => $resolved['slug'],
        ]);
    }

    if (auth()->check()) {
        return redirect(auth()->user()->defaultAuthenticatedHomeUrl());
    }

    return redirect()->to('/login', 302);
});

// Diagnóstico de deploy/storage (sem auth — não expõe credenciais)
Route::get('/up/storage-check', function () {
    $remoteFile = app_path('Support/RemoteStorage.php');
    $syntaxOk = false;
    if (is_file($remoteFile)) {
        try {
            token_get_all((string) file_get_contents($remoteFile), TOKEN_PARSE);
            $syntaxOk = true;
        } catch (\Throwable) {
            $syntaxOk = false;
        }
    }

    return response()->json([
        'ok' => true,
        'version' => 'storage-v6-no-remote-storage',
        'php' => PHP_VERSION,
        'aws_sdk' => class_exists(\Aws\S3\S3Client::class, false),
        'remote_storage_file' => is_file($remoteFile),
        'remote_storage_syntax_ok' => $syntaxOk,
        'vendor_autoload' => is_file(base_path('vendor/autoload.php')),
    ]);
});

Route::get('/cron', function () {
    $secret = config('getfy.cron_secret');
    $token = request()->header('X-Cron-Token') ?? request()->query('token');
    if (! $secret || $token !== $secret) {
        abort(404);
    }
    \Illuminate\Support\Facades\Artisan::call('schedule:run');

    return response()->json(['ok' => true, 'message' => 'Schedule executed']);
})->middleware('throttle:60,1')->name('cron.url');

Route::middleware(['throttle:60,1', \App\Http\Middleware\LogInboundGatewayWebhook::class])->group(function () {
    Route::post('/webhooks/gateways/linaopenx', [\App\Http\Controllers\Webhooks\LinaOpenxWebhookController::class, 'handle'])->name('webhooks.linaopenx');
    Route::post('/webhooks/gateways/spacepag', [\App\Http\Controllers\Webhooks\SpacepagWebhookController::class, 'handle'])->name('webhooks.spacepag');
    Route::post('/webhooks/gateways/woovi', [\App\Http\Controllers\Webhooks\WooviWebhookController::class, 'handle'])->name('webhooks.woovi');
    Route::post('/webhooks/gateways/bspay', [\App\Http\Controllers\Webhooks\BspayWebhookController::class, 'handle'])->name('webhooks.bspay');
    Route::post('/webhooks/gateways/stripe', [\App\Http\Controllers\Webhooks\StripeWebhookController::class, 'handle'])->name('webhooks.stripe');
    Route::post('/webhooks/gateways/paypal', [\App\Http\Controllers\Webhooks\PayPalWebhookController::class, 'handle'])->name('webhooks.paypal');
    Route::post('/webhooks/gateways/efi/pix', [\App\Http\Controllers\Webhooks\EfiWebhookController::class, 'pix'])->name('webhooks.efi.pix');
    Route::post('/webhooks/gateways/efi/pix-recorrente', [\App\Http\Controllers\Webhooks\EfiWebhookController::class, 'pixRecorrente'])->name('webhooks.efi.pix-recorrente');
    Route::post('/webhooks/gateways/efi/notification', [\App\Http\Controllers\Webhooks\EfiWebhookController::class, 'notification'])->name('webhooks.efi.notification');
    Route::post('/webhooks/gateways/versell/pix', [\App\Http\Controllers\Webhooks\VersellWebhookController::class, 'pix'])->name('webhooks.versell.pix');
    Route::post('/webhooks/gateways/versell/pix-automatico/rec', [\App\Http\Controllers\Webhooks\VersellWebhookController::class, 'pixAutoRec'])->name('webhooks.versell.pix_auto.rec');
    Route::post('/webhooks/gateways/versell/pix-automatico/cobr', [\App\Http\Controllers\Webhooks\VersellWebhookController::class, 'pixAutoCobr'])->name('webhooks.versell.pix_auto.cobr');
    Route::post('/webhooks/gateways/versell/transfer', [\App\Http\Controllers\Webhooks\VersellPayoutWebhookController::class, 'handle'])->name('webhooks.versell.transfer');
    Route::post('/webhooks/gateways/versell/cashout', [\App\Http\Controllers\Webhooks\VersellPayoutWebhookController::class, 'handle'])->name('webhooks.versell.cashout');
    Route::post('/webhooks/gateways/mercadopago', [\App\Http\Controllers\Webhooks\MercadoPagoWebhookController::class, 'handle'])->name('webhooks.mercadopago');
    Route::get('/webhooks/gateways/mercadopago', [\App\Http\Controllers\Webhooks\MercadoPagoWebhookController::class, 'handle'])->name('webhooks.mercadopago.ipn');
    Route::post('/webhooks/gateways/pushinpay', [\App\Http\Controllers\Webhooks\PushinPayWebhookController::class, 'handle'])->name('webhooks.pushinpay');
    Route::post('/webhooks/gateways/asaas', [\App\Http\Controllers\Webhooks\AsaasWebhookController::class, 'handle'])->name('webhooks.asaas');
    Route::post('/webhooks/gateways/pagarme', [\App\Http\Controllers\Webhooks\PagarmeWebhookController::class, 'handle'])->name('webhooks.pagarme');
    Route::post('/webhooks/gateways/cielo', [\App\Http\Controllers\Webhooks\CieloWebhookController::class, 'handle'])->name('webhooks.cielo');
    Route::post('/webhooks/gateways/cajupay/checkout', [\App\Http\Controllers\Webhooks\CajuPayCheckoutWebhookController::class, 'handle'])->name('webhooks.cajupay.checkout');
    Route::post('/webhooks/gateways/cajupay', [\App\Http\Controllers\Webhooks\CajuPayCheckoutWebhookController::class, 'handle'])->name('webhooks.cajupay');
    Route::post('/webhooks/gateways/cajupay/payout', [\App\Http\Controllers\Webhooks\CajuPayPayoutWebhookController::class, 'handle'])->name('webhooks.cajupay.payout');
    Route::post('/checkout/cajupay/webhook', [\App\Http\Controllers\Webhooks\CajuPayCheckoutWebhookController::class, 'handle'])->name('webhooks.cajupay.checkout-alias');
    // Dispatcher genérico para gateways de plugins (webhook_handler na definição do gateway)
    Route::post('/webhooks/gateways/{slug}', \App\Http\Controllers\Webhooks\GenericGatewayWebhookController::class)
        ->where('slug', '[a-z0-9_-]+')
        ->name('webhooks.gateway');
});

// Assets de plugins (imagens, etc.): GET /plugins/{slug}/assets/{path} — arquivos em plugins/{slug}/assets/
Route::get('/plugins/{slug}/assets/{path}', \App\Http\Controllers\PluginAssetController::class)
    ->where('path', '.+')
    ->name('plugins.asset');

Route::get('/renovar/{token}', [\App\Http\Controllers\RenewalController::class, 'show'])->name('renewal.show')->where('token', '[a-zA-Z0-9]{32,64}');
Route::post('/renovar', [\App\Http\Controllers\RenewalController::class, 'process'])
    ->name('renewal.process')
    ->middleware(['throttle:checkout-pay', 'throttle:checkout-pix', 'throttle:checkout-pix-email', 'throttle:checkout-card']);

// Checkout Pro (API): página hospedada – dados do cliente na sessão
Route::get('/api-checkout/{token}', [\App\Http\Controllers\ApiCheckoutController::class, 'show'])->name('api-checkout.show')->where('token', '[a-zA-Z0-9\-]{36,64}');
Route::post('/api-checkout/pay', [\App\Http\Controllers\ApiCheckoutController::class, 'process'])
    ->name('api-checkout.process')
    ->middleware(['throttle:checkout-pay', 'throttle:checkout-pix', 'throttle:checkout-pix-email', 'throttle:checkout-card']);
Route::get('/api-checkout/card-confirm', [\App\Http\Controllers\ApiCheckoutController::class, 'cardConfirm'])->name('api-checkout.card-confirm');
Route::get('/api-checkout/obrigado', [\App\Http\Controllers\ApiCheckoutController::class, 'thankYou'])->name('api-checkout.thank-you');

Route::get('/coproducao/convite/{token}', [\App\Http\Controllers\CoproductionInviteController::class, 'show'])
    ->name('coproduction.invite.show')
    ->where('token', '[A-Za-z0-9]{32,64}');

Route::get('/afiliar/{token}', [\App\Http\Controllers\AffiliateJoinController::class, 'show'])
    ->name('affiliate.join.show')
    ->where('token', '[a-z0-9]{32,64}');

Route::get('/c/{slug}', [\App\Http\Controllers\CheckoutController::class, 'show'])->name('checkout.show')->where('slug', '[a-z0-9]{6,16}');
Route::get('/checkout/pix', [\App\Http\Controllers\CheckoutController::class, 'pixPage'])->name('checkout.pix');
Route::get('/checkout/lina/return/{order}', [\App\Http\Controllers\CheckoutController::class, 'linaReturn'])->name('checkout.lina.return')->where('order', '[0-9]+');
Route::get('/checkout/lina/aguardar', [\App\Http\Controllers\CheckoutController::class, 'linaWaitPage'])->name('checkout.lina.wait');
Route::get('/checkout/boleto', [\App\Http\Controllers\CheckoutController::class, 'boletoPage'])->name('checkout.boleto');
Route::get('/checkout/order-status', [\App\Http\Controllers\CheckoutController::class, 'orderStatus'])->name('checkout.order-status')->middleware('throttle:30,1');
Route::post('/checkout/shipping-quote', [\App\Http\Controllers\CheckoutController::class, 'shippingQuote'])
    ->name('checkout.shipping-quote')
    ->middleware('throttle:checkout-shipping-quote');
Route::post('/checkout/pixel/purchase-ack', [\App\Http\Controllers\CheckoutController::class, 'purchasePixelAck'])->name('checkout.pixel.purchase-ack')->middleware('throttle:120,1');
Route::post('/checkout/pixel/events', [\App\Http\Controllers\CheckoutMetaTrackingController::class, 'store'])->name('checkout.pixel.events')->middleware('throttle:120,1');
Route::post('/checkout', [\App\Http\Controllers\CheckoutController::class, 'process'])
    ->name('checkout.process')
    ->middleware(['throttle:checkout-pay', 'throttle:checkout-pix', 'throttle:checkout-pix-email', 'throttle:checkout-card']);
Route::match(['get', 'post', 'put', 'patch', 'delete', 'options'], '/checkout/cajupay/sdk-api/{path?}', [\App\Http\Controllers\CajuPaySdkProxyController::class, '__invoke'])
    ->where('path', '.*')
    ->name('checkout.cajupay.sdk-api');
Route::post('/checkout/cajupay/session', [\App\Http\Controllers\CheckoutController::class, 'cajupaySession'])
    ->name('checkout.cajupay.session')
    ->middleware('throttle:checkout-cajupay-session');
Route::post('/checkout/cajupay/confirm-order', [\App\Http\Controllers\CheckoutController::class, 'cajupayConfirmOrder'])
    ->name('checkout.cajupay.confirm-order')
    ->middleware('throttle:checkout-cajupay-confirm');
Route::post('/checkout/paypal/create-order', [\App\Http\Controllers\CheckoutController::class, 'paypalCreateOrder'])
    ->name('checkout.paypal.create-order')
    ->middleware('throttle:checkout-card');
Route::post('/checkout/paypal/capture', [\App\Http\Controllers\CheckoutController::class, 'paypalCapture'])
    ->name('checkout.paypal.capture')
    ->middleware('throttle:checkout-card');
Route::post('/checkout/cajupay/sdk-session', [\App\Http\Controllers\CajuPayCheckoutSdkController::class, 'createSession'])
    ->name('checkout.cajupay.sdk-session')
    ->middleware('throttle:checkout-cajupay-session');
Route::get('/checkout/cajupay/session-status', [\App\Http\Controllers\CajuPayCheckoutSdkController::class, 'sessionStatus'])
    ->name('checkout.cajupay.session-status')
    ->middleware('throttle:60,1');
// Pagar.me tokenizecard: se o submit HTML não for cancelado, evita POST na rota GET /c/{slug} (405).
Route::post('/checkout/pagarme-tokenize-sink', fn () => response()->noContent())
    ->name('checkout.pagarme-tokenize-sink')
    ->middleware('throttle:120,1');
Route::post('/checkout/cielo-sop-token', [\App\Http\Controllers\CieloSopController::class, 'token'])
    ->name('checkout.cielo-sop-token')
    ->middleware('throttle:30,1');
Route::post('/api/checkout/track', [\App\Http\Controllers\CheckoutTrackingController::class, 'track'])
    ->name('checkout.track')
    ->middleware('throttle:checkout-track');
Route::post('/api/metrics/collect', [\App\Http\Controllers\Api\MetricsCollectController::class, '__invoke'])
    ->name('metrics.collect')
    ->middleware('throttle:120,1');
Route::post('/checkout/validate-coupon', [\App\Http\Controllers\CheckoutController::class, 'validateCoupon'])
    ->name('checkout.validate-coupon')
    ->middleware('throttle:checkout-coupon');

Route::get('/checkout/upsell', [\App\Http\Controllers\UpsellController::class, 'upsellPage'])->name('checkout.upsell');
Route::get('/checkout/downsell', [\App\Http\Controllers\UpsellController::class, 'downsellPage'])->name('checkout.downsell');
Route::get('/checkout/obrigado', [\App\Http\Controllers\UpsellController::class, 'thankYouPage'])->name('checkout.thank-you');
Route::post('/checkout/upsell/accept', [\App\Http\Controllers\UpsellController::class, 'acceptUpsell'])->name('checkout.upsell.accept')->middleware('throttle:30,1');
Route::post('/checkout/upsell/decline', [\App\Http\Controllers\UpsellController::class, 'declineUpsell'])->name('checkout.upsell.decline')->middleware('throttle:30,1');
Route::post('/checkout/downsell/accept', [\App\Http\Controllers\UpsellController::class, 'acceptDownsell'])->name('checkout.downsell.accept')->middleware('throttle:30,1');
Route::post('/checkout/downsell/decline', [\App\Http\Controllers\UpsellController::class, 'declineDownsell'])->name('checkout.downsell.decline')->middleware('throttle:30,1');

Route::get('/conquistas/{slug}/share', [\App\Http\Controllers\ConquistasController::class, 'share'])
    ->name('conquistas.share')
    ->where('slug', '[a-z0-9-]+');

Route::get('/email/verificar/{id}/{hash}', [\App\Http\Controllers\EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/cadastro', [\App\Http\Controllers\InfoprodutorRegistrationController::class, 'store'])->middleware('throttle:registration-store');
Route::post('/cadastro/validar-email', [\App\Http\Controllers\InfoprodutorRegistrationController::class, 'validateEmail'])->middleware('throttle:registration-validate');
Route::post('/cadastro/validar-documento', [\App\Http\Controllers\InfoprodutorRegistrationController::class, 'validateDocument'])->middleware('throttle:registration-validate');
Route::post('/cadastro/consultar-cnpj', [\App\Http\Controllers\InfoprodutorRegistrationController::class, 'lookupCnpj'])->middleware('throttle:registration-validate');

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/demo/login/admin', [\App\Http\Controllers\DemoLoginController::class, 'loginAdmin'])->name('demo.login.admin');
    Route::post('/demo/login/seller', [\App\Http\Controllers\DemoLoginController::class, 'loginSeller'])->name('demo.login.seller');
});

Route::middleware('guest')->group(function () {
    Route::get('/criar-admin', [\App\Http\Controllers\CreateFirstAdminController::class, 'show'])->name('criar-admin');
    Route::post('/criar-admin', [\App\Http\Controllers\CreateFirstAdminController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/cadastro', [\App\Http\Controllers\InfoprodutorRegistrationController::class, 'create'])->name('cadastro');
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
    Route::get('/login/2fa', [TwoFactorLoginController::class, 'showSeller'])->name('login.two-factor');
    Route::post('/login/2fa', [TwoFactorLoginController::class, 'verifySeller'])->name('login.two-factor.verify')->middleware('throttle:login');
    Route::post('/login/2fa/cancelar', [TwoFactorLoginController::class, 'cancelSeller'])->name('login.two-factor.cancel');
    Route::get('/esqueci-senha', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/esqueci-senha', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email')->middleware('throttle:password-reset');
    Route::get('/redefinir-senha/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/redefinir-senha', [ResetPasswordController::class, 'reset'])->name('password.update')->middleware('throttle:password-reset');
});

Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/cadastro/infoprodutor', [\App\Http\Controllers\InfoprodutorRegistrationController::class, 'createUpgrade'])->name('cadastro.infoprodutor');
    Route::get('/verificar-email', [\App\Http\Controllers\EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verificacao/reenviar', [\App\Http\Controllers\EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification-resend')
        ->name('verification.resend');
});

Route::prefix('plataforma')->name('plataforma.')->group(function () {
    Route::middleware([\App\Http\Middleware\EnsureGuestPlatform::class])->group(function () {
        Route::get('/login', [\App\Http\Controllers\Platform\LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [\App\Http\Controllers\Platform\LoginController::class, 'login'])->middleware('throttle:login');
        Route::get('/login/2fa', [TwoFactorLoginController::class, 'showPlatform'])->name('login.two-factor');
        Route::post('/login/2fa', [TwoFactorLoginController::class, 'verifyPlatform'])->name('login.two-factor.verify')->middleware('throttle:login');
        Route::post('/login/2fa/cancelar', [TwoFactorLoginController::class, 'cancelPlatform'])->name('login.two-factor.cancel');
    });
    // Logout fora de stacker.license: licença inválida não deve impedir sair (evita 404 com sessão presa).
    Route::middleware(['auth', 'platform.admin'])->group(function () {
        Route::post('/logout', [\App\Http\Controllers\Platform\LoginController::class, 'logout'])->name('logout');
    });
    Route::middleware(['auth', 'platform.admin', 'stacker.license'])->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Platform\DashboardController::class, '__invoke'])->name('dashboard');
        Route::get('/meu-perfil', [\App\Http\Controllers\Platform\ProfileController::class, 'index'])->name('profile.index');
        Route::post('/meu-perfil', [\App\Http\Controllers\Platform\ProfileController::class, 'update'])->name('profile.update');
        Route::put('/meu-perfil/senha', [\App\Http\Controllers\Platform\ProfileController::class, 'updatePassword'])->name('profile.update-password');
        Route::post('/seguranca/totp/iniciar', [\App\Http\Controllers\TotpSecurityController::class, 'beginTotp'])->name('security.totp.begin');
        Route::post('/seguranca/totp/confirmar', [\App\Http\Controllers\TotpSecurityController::class, 'confirmTotp'])->name('security.totp.confirm');
        Route::post('/seguranca/totp/desativar', [\App\Http\Controllers\TotpSecurityController::class, 'disableTotp'])->name('security.totp.disable');
        Route::get('/app', [\App\Http\Controllers\Platform\AppController::class, 'index'])->name('app.index');
        Route::get('/app/data', [\App\Http\Controllers\Platform\AppController::class, 'data'])->name('app.data');
        Route::put('/app', [\App\Http\Controllers\Platform\AppController::class, 'update'])->name('app.update');
        Route::post('/app/upload', [\App\Http\Controllers\Platform\AppController::class, 'upload'])->name('app.upload');
        Route::post('/app/clear-field', [\App\Http\Controllers\Platform\AppController::class, 'clearField'])->name('app.clear-field');
        Route::post('/app/push/send', [\App\Http\Controllers\Platform\AppPushController::class, 'sendBroadcast'])->name('app.push.send');
        Route::get('/app/push/data', [\App\Http\Controllers\Platform\AppPushController::class, 'data'])->name('app.push.data');
        Route::put('/app/push', [\App\Http\Controllers\Platform\AppPushController::class, 'update'])->name('app.push.update');
        Route::post('/app/push/upload-service-account', [\App\Http\Controllers\Platform\AppPushController::class, 'uploadServiceAccount'])->name('app.push.upload-service-account');
        Route::post('/app/push/generate-vapid', [\App\Http\Controllers\Platform\AppPushController::class, 'generateVapid'])->name('app.push.generate-vapid');
        Route::post('/app/push/test', [\App\Http\Controllers\Platform\AppPushController::class, 'test'])->name('app.push.test');
        Route::post('/app/push/clear-provider-subscriptions', [\App\Http\Controllers\Platform\AppPushController::class, 'clearOtherProviderSubscriptions'])->name('app.push.clear-provider');
        Route::get('/app/push/subscribers', [\App\Http\Controllers\Platform\AppPushController::class, 'subscribers'])->name('app.push.subscribers');
        Route::delete('/app/push/subscribers/{subscription}', [\App\Http\Controllers\Platform\AppPushController::class, 'destroySubscriber'])->name('app.push.subscribers.destroy');
        Route::get('/app/push/campaigns', [\App\Http\Controllers\Platform\AppPushController::class, 'campaigns'])->name('app.push.campaigns');
        Route::post('/app/push/campaigns/clear-history', [\App\Http\Controllers\Platform\AppPushController::class, 'destroyCampaigns'])->name('app.push.campaigns.clear-history');
        Route::get('/app/push/campaigns/{campaign}', [\App\Http\Controllers\Platform\AppPushController::class, 'showCampaign'])->name('app.push.campaigns.show');
        Route::put('/app/push/campaigns/{campaign}', [\App\Http\Controllers\Platform\AppPushController::class, 'updateCampaign'])->name('app.push.campaigns.update');
        Route::post('/app/push/campaigns/{campaign}/cancel', [\App\Http\Controllers\Platform\AppPushController::class, 'cancelCampaign'])->name('app.push.campaigns.cancel');
        Route::delete('/app/push/campaigns/{campaign}', [\App\Http\Controllers\Platform\AppPushController::class, 'destroyCampaign'])->name('app.push.campaigns.destroy');
        Route::put('/app/push/daily-sales', [\App\Http\Controllers\Platform\AppPushController::class, 'updateDailySalesSettings'])->name('app.push.daily-sales');
        Route::get('/app/push/daily-sales/history', [\App\Http\Controllers\Platform\AppPushController::class, 'dailySummaryHistory'])->name('app.push.daily-sales.history');
        Route::get('/conquistas', [\App\Http\Controllers\Platform\SalesAchievementsController::class, 'index'])->name('conquistas.index');
        Route::get('/metricas', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'index'])->name('metrics.index');
        Route::get('/metricas/origem', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'origins'])->name('metrics.origins');
        Route::get('/metricas/funil', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'funnel'])->name('metrics.funnel');
        Route::get('/metricas/cliques', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'clicks'])->name('metrics.clicks');
        Route::get('/metricas/mapa', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'map'])->name('metrics.map');
        Route::get('/metricas/export.csv', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'exportCsv'])
            ->name('metrics.export');
        Route::get('/metricas/export.xlsx', [\App\Http\Controllers\Platform\MetricsTrackingController::class, 'exportXlsx'])
            ->middleware('throttle:20,1')
            ->name('metrics.export.xlsx');
        Route::post('/conquistas', [\App\Http\Controllers\Platform\SalesAchievementsController::class, 'store'])->name('conquistas.store');
        Route::put('/conquistas/unlocks/{unlock}/reward-status', [\App\Http\Controllers\Platform\SalesAchievementsController::class, 'updateUnlockRewardStatus'])->name('conquistas.unlocks.reward-status');
        Route::put('/conquistas/{salesAchievement}', [\App\Http\Controllers\Platform\SalesAchievementsController::class, 'update'])->name('conquistas.update');
        Route::delete('/conquistas/{salesAchievement}', [\App\Http\Controllers\Platform\SalesAchievementsController::class, 'destroy'])->name('conquistas.destroy');
        Route::post('/conquistas/{salesAchievement}/image', [\App\Http\Controllers\Platform\SalesAchievementsController::class, 'uploadImage'])->name('conquistas.image.upload');
        Route::prefix('usuarios')->name('usuarios.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Platform\UsersController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Platform\UsersController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Platform\UsersController::class, 'store'])->name('store');
            Route::post('/excluir-em-massa', [\App\Http\Controllers\Platform\UsersController::class, 'bulkDestroy'])
                ->middleware('throttle:5,1')
                ->name('bulk-destroy');
            Route::get('/{user}/edit', [\App\Http\Controllers\Platform\UsersController::class, 'edit'])->name('edit');
            Route::get('/{user}', [\App\Http\Controllers\Platform\UsersController::class, 'show'])->name('show');
            Route::get('/{user}/taxas-efetivas', [\App\Http\Controllers\Platform\UsersController::class, 'effectiveFees'])->name('effective-fees');
            Route::get('/{user}/observacoes', [\App\Http\Controllers\Platform\MerchantAdminNotesController::class, 'index'])->name('notes.index');
            Route::post('/{user}/observacoes', [\App\Http\Controllers\Platform\MerchantAdminNotesController::class, 'store'])->name('notes.store');
            Route::post('/{user}/gerente-conta', [\App\Http\Controllers\Platform\UsersController::class, 'assignAccountManager'])
                ->middleware('throttle:60,1')
                ->name('account-manager.assign');
            Route::post('/{user}/ajuste-saldo', [\App\Http\Controllers\Platform\UsersController::class, 'adjustBalance'])->name('adjust-balance');
            Route::post('/{user}/carteira/transacoes/{walletTransaction}/antecipar', [\App\Http\Controllers\Platform\UsersController::class, 'anticipateWalletSale'])
                ->middleware('throttle:30,1')
                ->name('wallet.anticipate');
            Route::post('/{user}/resetar-2fa', [\App\Http\Controllers\Platform\UsersController::class, 'resetTotp'])
                ->middleware('throttle:10,1')
                ->name('reset-totp');
            Route::put('/{user}', [\App\Http\Controllers\Platform\UsersController::class, 'update'])->name('update');
            Route::delete('/{user}', [\App\Http\Controllers\Platform\UsersController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('gerentes-conta')->name('gerentes-conta.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Platform\AccountManagersController::class, 'index'])->name('index');
            Route::get('/criar', [\App\Http\Controllers\Platform\AccountManagersController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Platform\AccountManagersController::class, 'store'])->name('store');
            Route::post('/distribuir/preview', [\App\Http\Controllers\Platform\AccountManagersController::class, 'distributePreview'])
                ->middleware('throttle:30,1')
                ->name('distribute.preview');
            Route::post('/distribuir', [\App\Http\Controllers\Platform\AccountManagersController::class, 'distribute'])
                ->middleware('throttle:10,1')
                ->name('distribute');
            Route::get('/{gerente}', [\App\Http\Controllers\Platform\AccountManagersController::class, 'show'])->name('show');
            Route::get('/{gerente}/editar', [\App\Http\Controllers\Platform\AccountManagersController::class, 'edit'])->name('edit');
            Route::put('/{gerente}', [\App\Http\Controllers\Platform\AccountManagersController::class, 'update'])->name('update');
            Route::post('/{gerente}', [\App\Http\Controllers\Platform\AccountManagersController::class, 'update'])->name('update.post');
            Route::post('/{gerente}/ativacao', [\App\Http\Controllers\Platform\AccountManagersController::class, 'updateActive'])
                ->middleware('throttle:60,1')
                ->name('ativacao');
            Route::post('/{gerente}/transferir', [\App\Http\Controllers\Platform\AccountManagersController::class, 'transfer'])
                ->middleware('throttle:10,1')
                ->name('transfer');
            Route::delete('/{gerente}', [\App\Http\Controllers\Platform\AccountManagersController::class, 'destroy'])
                ->middleware('throttle:30,1')
                ->name('destroy');
        });

        Route::get('/saldo', [\App\Http\Controllers\Platform\BalancesController::class, 'index'])->name('saldo.index');

        Route::middleware(['mp.balance.tool'])->group(function () {
            Route::get('/ops/mercadopago-saldo', \App\Http\Controllers\Platform\MercadoPagoBalanceController::class)
                ->name('ops.mercadopago-balance');
        });

        Route::get('/ops/saude-pagamentos', [\App\Http\Controllers\Platform\PaymentHealthController::class, 'index'])
            ->name('ops.payment-health');
        Route::get('/ops/saude-pagamentos/pedidos/{order}/probe', [\App\Http\Controllers\Platform\PaymentHealthController::class, 'probeOrder'])
            ->name('ops.payment-health.probe');
        Route::post('/ops/saude-pagamentos/reconciliar', [\App\Http\Controllers\Platform\PaymentHealthController::class, 'reconcile'])
            ->middleware('throttle:3,1')
            ->name('ops.payment-health.reconcile');
        Route::post('/ops/saude-pagamentos/pedidos/{order}/reconciliar', [\App\Http\Controllers\Platform\PaymentHealthController::class, 'reconcileOrder'])
            ->middleware('throttle:10,1')
            ->name('ops.payment-health.reconcile-order');
        Route::get('/ops/saude-utmify', [\App\Http\Controllers\Platform\UtmifyMetricsHealthController::class, 'index'])
            ->name('ops.utmify-metrics-health');

        Route::get('/configuracoes', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
        Route::put('/configuracoes', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
        // POST aceito: Inertia/PUT falha em alguns proxies; front envia JSON no body.
        Route::post('/configuracoes', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update.post');
        Route::post('/configuracoes/email/test', [\App\Http\Controllers\EmailTestController::class, 'test'])->name('settings.email.test');
        Route::post('/configuracoes/email/connection-test', [\App\Http\Controllers\EmailTestController::class, 'connectionTest'])->name('settings.email.connection-test');
        Route::post('/configuracoes/email/send-test', [\App\Http\Controllers\EmailTestController::class, 'sendTest'])->name('settings.email.send-test');
        Route::get('/configuracoes/storage/ping', function () {
            return response()->json([
                'ok' => true,
                'version' => 'storage-v6-no-remote-storage',
                'aws_sdk' => class_exists(\Aws\S3\S3Client::class),
                'remote_storage_file' => is_file(app_path('Support/RemoteStorage.php')),
                'storage_test_controller' => is_file(app_path('Http/Controllers/StorageTestController.php')),
            ]);
        })
            ->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])
            ->name('settings.storage.ping');
        Route::post('/configuracoes/storage/test', \App\Http\Controllers\StorageTestController::class)
            ->withoutMiddleware([
                \App\Http\Middleware\HandleInertiaRequests::class,
                \App\Http\Middleware\ApplyBrandingConfig::class,
                \App\Http\Middleware\SetPanelLocale::class,
            ])
            ->name('settings.storage.test');
        Route::post('/configuracoes/storage/migrate', [\App\Http\Controllers\StorageMigrateController::class, '__invoke'])
            ->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])
            ->name('settings.storage.migrate');
        Route::post('/configuracoes/backup/run', [\App\Http\Controllers\Platform\DatabaseBackupController::class, 'run'])
            ->middleware('throttle:3,1')
            ->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])
            ->name('settings.backup.run');
        Route::post('/configuracoes/backup/download', [\App\Http\Controllers\Platform\DatabaseBackupController::class, 'download'])
            ->middleware('throttle:3,1')
            ->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])
            ->name('settings.backup.download');
        Route::get('/configuracoes/backup/arquivos/{filename}', [\App\Http\Controllers\Platform\DatabaseBackupController::class, 'file'])
            ->where('filename', 'stacker-[A-Za-z0-9.-]+\.sql\.gz')
            ->middleware('throttle:20,1')
            ->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])
            ->name('settings.backup.file');
        Route::get('/configuracoes/idiomas/data', [\App\Http\Controllers\Platform\LanguageSettingsController::class, 'data'])->name('settings.languages.data');
        Route::post('/configuracoes/idiomas/languages', [\App\Http\Controllers\Platform\LanguageSettingsController::class, 'addLanguage'])->name('settings.languages.add');
        Route::put('/configuracoes/idiomas/languages/{platformLanguage}', [\App\Http\Controllers\Platform\LanguageSettingsController::class, 'updateLanguage'])->name('settings.languages.update');
        Route::put('/configuracoes/idiomas/translations', [\App\Http\Controllers\Platform\LanguageSettingsController::class, 'saveTranslations'])->name('settings.languages.translations.save');
        Route::post('/configuracoes/idiomas/import-missing', [\App\Http\Controllers\Platform\LanguageSettingsController::class, 'importMissing'])->name('settings.languages.import-missing');
        Route::get('/configuracoes/banners-dashboard/data', [\App\Http\Controllers\Platform\DashboardBannerController::class, 'data'])->name('settings.dashboard-banners.data');
        Route::put('/configuracoes/banners-dashboard', [\App\Http\Controllers\Platform\DashboardBannerController::class, 'update'])->name('settings.dashboard-banners.update');
        Route::post('/configuracoes/banners-dashboard/upload', [\App\Http\Controllers\Platform\DashboardBannerController::class, 'upload'])->name('settings.dashboard-banners.upload');
        Route::get('/configuracoes/template-dashboard/data', [\App\Http\Controllers\Platform\SellerDashboardTemplateController::class, 'data'])->name('settings.dashboard-template.data');
        Route::put('/configuracoes/template-dashboard', [\App\Http\Controllers\Platform\SellerDashboardTemplateController::class, 'update'])->name('settings.dashboard-template.update');
        Route::get('/configuracoes/template-login/data', [\App\Http\Controllers\Platform\LoginTemplateController::class, 'data'])->name('settings.login-template.data');
        Route::put('/configuracoes/template-login', [\App\Http\Controllers\Platform\LoginTemplateController::class, 'update'])->name('settings.login-template.update');
        Route::get('/configuracoes/panel-color-scheme/data', [\App\Http\Controllers\Platform\PanelColorSchemeController::class, 'data'])->name('settings.panel-color-scheme.data');
        Route::put('/configuracoes/panel-color-scheme', [\App\Http\Controllers\Platform\PanelColorSchemeController::class, 'update'])->name('settings.panel-color-scheme.update');
        Route::get('/configuracoes/personalizacao/data', [\App\Http\Controllers\BrandingSettingsController::class, 'data'])->name('settings.branding.data');
        Route::put('/configuracoes/personalizacao', [\App\Http\Controllers\BrandingSettingsController::class, 'update'])->name('settings.branding.update');
        Route::post('/configuracoes/personalizacao/upload', [\App\Http\Controllers\BrandingSettingsController::class, 'upload'])->name('settings.branding.upload');
        Route::post('/configuracoes/personalizacao/clear-field', [\App\Http\Controllers\BrandingSettingsController::class, 'clearField'])->name('settings.branding.clear');
        Route::post('/configuracoes/personalizacao/sync-global', [\App\Http\Controllers\BrandingSettingsController::class, 'syncGlobal'])->name('settings.branding.sync-global');
        Route::post('/configuracoes/suporte-painel/upload', [\App\Http\Controllers\SellerPanelSupportIconController::class, 'upload'])->name('settings.seller-panel-support.upload');
        Route::post('/configuracoes/suporte-painel/clear-icon', [\App\Http\Controllers\SellerPanelSupportIconController::class, 'clearIcon'])->name('settings.seller-panel-support.clear-icon');
        Route::get('/configuracoes/demo/data', [\App\Http\Controllers\Platform\DemoModeController::class, 'data'])->name('settings.demo.data');
        Route::put('/configuracoes/demo', [\App\Http\Controllers\Platform\DemoModeController::class, 'update'])->name('settings.demo.update');
        Route::post('/configuracoes/demo/provision', [\App\Http\Controllers\Platform\DemoModeController::class, 'provision'])->name('settings.demo.provision');
        Route::get('/configuracoes/url-publica/data', [\App\Http\Controllers\Platform\PublicUrlSettingsController::class, 'data'])->name('settings.public-url.data');
        Route::put('/configuracoes/url-publica', [\App\Http\Controllers\Platform\PublicUrlSettingsController::class, 'update'])->name('settings.public-url.update');
        Route::post('/configuracoes/url-publica', [\App\Http\Controllers\Platform\PublicUrlSettingsController::class, 'update'])->name('settings.public-url.update.post');
        Route::get('/configuracoes/url-publica/reiniciar-containers', [\App\Http\Controllers\Platform\PublicUrlSettingsController::class, 'restartStatus'])->name('settings.public-url.restart-status');
        Route::post('/configuracoes/url-publica/reiniciar-containers', [\App\Http\Controllers\Platform\PublicUrlSettingsController::class, 'restartContainers'])
            ->middleware('throttle:6,1')
            ->name('settings.public-url.restart');
        Route::get('/configuracoes/update/check', [\App\Http\Controllers\UpdateController::class, 'check'])->name('settings.update.check');
        Route::get('/configuracoes/update/integrity', [\App\Http\Controllers\UpdateController::class, 'integrity'])->name('settings.update.integrity');
        Route::post('/configuracoes/update/migrate', [\App\Http\Controllers\UpdateController::class, 'migrateNow'])->name('settings.update.migrate')->middleware('throttle:10,1');
        Route::post('/configuracoes/update/run', [\App\Http\Controllers\UpdateController::class, 'run'])->name('settings.update.run')->middleware('throttle:10,1');
        // Rotas literais /gateways/order antes de /gateways/{slug}, senão "order" é capturado como slug.
        Route::put('/configuracoes/gateways/order', [\App\Http\Controllers\GatewaysController::class, 'updateOrder'])->name('gateways.order');
        Route::get('/configuracoes/gateways/{slug}', [\App\Http\Controllers\GatewaysController::class, 'show'])->name('gateways.show');
        Route::put('/configuracoes/gateways/{slug}', [\App\Http\Controllers\GatewaysController::class, 'update'])->name('gateways.update');
        Route::put('/configuracoes/gateways/{slug}/enabled', [\App\Http\Controllers\GatewaysController::class, 'updateEnabled'])->name('gateways.enabled');
        Route::post('/configuracoes/gateways/{slug}/test', [\App\Http\Controllers\GatewaysController::class, 'test'])->name('gateways.test');
        Route::post('/configuracoes/gateways/{slug}/certificate', [\App\Http\Controllers\GatewaysController::class, 'updateCertificate'])->name('gateways.certificate');
        Route::put('/configuracoes/gateways/{slug}/certificate', [\App\Http\Controllers\GatewaysController::class, 'updateCertificate']);

        Route::get('/financeiro', [\App\Http\Controllers\Platform\FinancialController::class, 'index'])->name('financeiro.index');
        Route::put('/financeiro/gateways/order', [\App\Http\Controllers\GatewaysController::class, 'updateOrder'])->name('financeiro.gateways.order');
        Route::get('/financeiro/gateways/{slug}', [\App\Http\Controllers\GatewaysController::class, 'show'])->name('financeiro.gateways.show');
        Route::put('/financeiro/gateways/{slug}', [\App\Http\Controllers\GatewaysController::class, 'update'])->name('financeiro.gateways.update');
        Route::put('/financeiro/gateways/{slug}/enabled', [\App\Http\Controllers\GatewaysController::class, 'updateEnabled'])->name('financeiro.gateways.enabled');
        Route::post('/financeiro/gateways/{slug}/test', [\App\Http\Controllers\GatewaysController::class, 'test'])->name('financeiro.gateways.test');
        Route::post('/financeiro/gateways/{slug}/certificate', [\App\Http\Controllers\GatewaysController::class, 'updateCertificate'])->name('financeiro.gateways.certificate');
        Route::put('/financeiro/gateways/{slug}/certificate', [\App\Http\Controllers\GatewaysController::class, 'updateCertificate']);
        Route::post('/financeiro/cajupay-contas', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'store'])->name('financeiro.cajupay-contas.store');
        Route::get('/financeiro/cajupay-contas/{cajuPayAccount}', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'show'])->name('financeiro.cajupay-contas.show');
        Route::put('/financeiro/cajupay-contas/{cajuPayAccount}', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'update'])->name('financeiro.cajupay-contas.update');
        Route::post('/financeiro/cajupay-contas/{cajuPayAccount}/test', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'test'])->name('financeiro.cajupay-contas.test');
        Route::post('/financeiro/cajupay-contas/{cajuPayAccount}/rotate-webhook-secret', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'rotateWebhookSecret'])->name('financeiro.cajupay-contas.rotate-webhook');
        Route::patch('/financeiro/cajupay-contas/{cajuPayAccount}/default', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'setDefault'])->name('financeiro.cajupay-contas.default');
        Route::delete('/financeiro/cajupay-contas/{cajuPayAccount}', [\App\Http\Controllers\Platform\CajuPayAccountsController::class, 'destroy'])->name('financeiro.cajupay-contas.destroy');
        Route::put('/financeiro/metodos-pagamento', [\App\Http\Controllers\Platform\FinancialController::class, 'updatePaymentMethods'])->name('financeiro.payment-methods.update');
        Route::put('/financeiro/taxas', [\App\Http\Controllers\Platform\FinancialController::class, 'updateFees'])->name('financeiro.taxas.update');
        Route::put('/financeiro/parcelamento', [\App\Http\Controllers\Platform\FinancialController::class, 'updateInstallments'])->name('financeiro.parcelamento.update');
        Route::put('/financeiro/pixgo', [\App\Http\Controllers\Platform\FinancialController::class, 'updatePixGo'])->name('financeiro.pixgo.update');
        Route::put('/financeiro/limites', [\App\Http\Controllers\Platform\FinancialController::class, 'updateChargeLimits'])->name('financeiro.limites.update');
        Route::put('/financeiro/liquidacao', [\App\Http\Controllers\Platform\FinancialController::class, 'updateSettlement'])->name('financeiro.liquidacao.update');
        Route::put('/financeiro/payout-gateway', [\App\Http\Controllers\Platform\FinancialController::class, 'updatePayoutGatewayPreference'])->name('financeiro.payout-gateway.update');
        Route::put('/financeiro/saques-politica', [\App\Http\Controllers\Platform\FinancialController::class, 'updateWithdrawalPolicy'])->name('financeiro.saques-politica.update');
        Route::post('/financeiro/saques-politica/pin-reset', [\App\Http\Controllers\Platform\FinancialController::class, 'resetManualApprovalPin'])->name('financeiro.saques-politica.pin-reset');
        Route::post('/financeiro/saques/{withdrawal}/aprovar', [\App\Http\Controllers\Platform\FinancialController::class, 'approveWithdrawal'])->name('financeiro.saques.approve');
        Route::post('/financeiro/saques/{withdrawal}/reconciliar-cajupay', [\App\Http\Controllers\Platform\FinancialController::class, 'reconcileCajuPayWithdrawal'])
            ->name('financeiro.saques.reconcile-cajupay')
            ->middleware('throttle:60,1');
        Route::post('/financeiro/saques/{withdrawal}/reprocessar-cajupay', [\App\Http\Controllers\Platform\FinancialController::class, 'retryCajuPayWithdrawal'])
            ->name('financeiro.saques.reprocessar-cajupay')
            ->middleware('throttle:30,1');
        Route::post('/financeiro/saques/{withdrawal}/rejeitar', [\App\Http\Controllers\Platform\FinancialController::class, 'rejectWithdrawal'])->name('financeiro.saques.reject');

        Route::get('/produtos', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'index'])->name('produtos.index');
        Route::get('/produtos/{product}/area-membros/preview', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'previewMemberArea'])
            ->name('produtos.member-area.preview')
            ->middleware('throttle:30,1');
        Route::post('/produtos/{product}/aprovar', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'approve'])
            ->name('produtos.approve')
            ->middleware('throttle:60,1');
        Route::post('/produtos/{product}/rejeitar', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'reject'])
            ->name('produtos.reject')
            ->middleware('throttle:60,1');
        Route::post('/produtos/{product}/ativacao', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'updateActive'])
            ->name('produtos.ativacao')
            ->middleware('throttle:60,1');
        Route::post('/produtos/{product}/bloqueio', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'updateBlock'])
            ->name('produtos.bloqueio')
            ->middleware('throttle:60,1');
        Route::delete('/produtos/{product}', [\App\Http\Controllers\Platform\PlatformProductsController::class, 'destroy'])
            ->name('produtos.destroy')
            ->middleware('throttle:30,1');

        Route::get('/saques', [\App\Http\Controllers\Platform\WithdrawalsController::class, 'index'])->name('saques.index');
        Route::get('/saques/{withdrawal}/comprovante', [\App\Http\Controllers\WithdrawalReceiptController::class, 'platform'])
            ->name('saques.receipt');

        Route::get('/indique-e-ganhe', [\App\Http\Controllers\Platform\ReferralProgramAdminController::class, 'index'])
            ->name('indique-ganhe.index');
        Route::put('/indique-e-ganhe', [\App\Http\Controllers\Platform\ReferralProgramAdminController::class, 'updateSettings'])
            ->name('indique-ganhe.update');
        Route::post('/indique-e-ganhe/atribuir', [\App\Http\Controllers\Platform\ReferralProgramAdminController::class, 'assign'])
            ->middleware('throttle:30,1')
            ->name('indique-ganhe.assign');
        Route::post('/indique-e-ganhe/saques/{withdrawal}/aprovar', [\App\Http\Controllers\Platform\ReferralProgramAdminController::class, 'approveWithdrawal'])
            ->middleware('throttle:20,1')
            ->name('indique-ganhe.saques.approve');
        Route::post('/indique-e-ganhe/saques/{withdrawal}/rejeitar', [\App\Http\Controllers\Platform\ReferralProgramAdminController::class, 'rejectWithdrawal'])
            ->middleware('throttle:20,1')
            ->name('indique-ganhe.saques.reject');

        Route::get('/transacoes', [\App\Http\Controllers\Platform\TransactionsController::class, 'index'])->name('transacoes.index');
        Route::get('/transacoes-api', [\App\Http\Controllers\Platform\TransactionsController::class, 'apiIndex'])->name('transacoes-api.index');
        Route::post('/transacoes/pedidos/excluir-em-massa', [\App\Http\Controllers\Platform\TransactionsController::class, 'bulkDestroyPending'])
            ->name('transacoes.pedidos.bulk-destroy')
            ->middleware('throttle:10,1');
        Route::get('/clientes', [\App\Http\Controllers\Platform\CustomersController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/export', [\App\Http\Controllers\Platform\CustomersController::class, 'export'])
            ->name('clientes.export')
            ->middleware('throttle:10,1');
        Route::get('/clientes/{user}', [\App\Http\Controllers\Platform\CustomersController::class, 'show'])->name('clientes.show');
        Route::delete('/clientes/{user}', [\App\Http\Controllers\Platform\CustomersController::class, 'destroy'])
            ->name('clientes.destroy')
            ->middleware('throttle:30,1');
        Route::delete('/clientes/{user}/historico-pedidos', [\App\Http\Controllers\Platform\CustomersController::class, 'destroyOrderHistory'])
            ->name('clientes.historico.destroy')
            ->middleware('throttle:30,1');
        Route::post('/transacoes/pedidos/{order}/aprovar-manualmente', [\App\Http\Controllers\Platform\TransactionsController::class, 'approveManualOrder'])
            ->name('transacoes.pedidos.approve-manual')
            ->middleware('throttle:10,1');
        Route::post('/transacoes/pedidos/{order}/cancelar', [\App\Http\Controllers\Platform\TransactionsController::class, 'cancelOrder'])
            ->name('transacoes.pedidos.cancel')
            ->middleware('throttle:10,1');
        Route::post('/transacoes/pedidos/{order}/reembolsar', [\App\Http\Controllers\Platform\TransactionsController::class, 'refundOrder'])
            ->name('transacoes.pedidos.refund')
            ->middleware('throttle:10,1');
        Route::post('/transacoes/pedidos/{order}/reembolso-manual', [\App\Http\Controllers\Platform\TransactionsController::class, 'refundOrderOffline'])
            ->name('transacoes.pedidos.refund-offline')
            ->middleware('throttle:10,1');
        Route::post('/transacoes/pedidos/{order}/marcar-med', [\App\Http\Controllers\Platform\TransactionsController::class, 'markDisputedOrder'])
            ->name('transacoes.pedidos.disputed')
            ->middleware('throttle:10,1');
        Route::delete('/transacoes/pedidos/{order}', [\App\Http\Controllers\Platform\TransactionsController::class, 'destroyOrder'])
            ->name('transacoes.pedidos.destroy')
            ->middleware('throttle:30,1');

        Route::get('/disputas', [\App\Http\Controllers\Platform\MedDisputesController::class, 'index'])->name('disputas.index');
        Route::get('/disputas/{dispute}', [\App\Http\Controllers\Platform\MedDisputesController::class, 'show'])->name('disputas.show');
        Route::post('/disputas/{dispute}/gerar-dossie', [\App\Http\Controllers\Platform\MedDisputesController::class, 'generateDossier'])
            ->name('disputas.generate-dossier')
            ->middleware('throttle:15,1');
        Route::get('/disputas/{dispute}/dossie', [\App\Http\Controllers\Platform\MedDisputesController::class, 'downloadDossier'])
            ->name('disputas.download-dossier');
        Route::post('/disputas/{dispute}/defesa', [\App\Http\Controllers\Platform\MedDisputesController::class, 'submitDefense'])
            ->name('disputas.defense')
            ->middleware('throttle:15,1');
        Route::post('/disputas/{dispute}/resolver', [\App\Http\Controllers\Platform\MedDisputesController::class, 'resolve'])
            ->name('disputas.resolve')
            ->middleware('throttle:15,1');

        Route::get('/verificacoes-kyc', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'index'])->name('kyc.index');
        Route::get('/verificacoes-kyc/documento/{document}', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'downloadDocument'])
            ->name('kyc.document')
            ->whereUuid('document');
        Route::get('/verificacoes-kyc/usuario/{user}', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'show'])->name('kyc.show');
        Route::post('/verificacoes-kyc/usuario/{user}/aprovar', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'approve'])->name('kyc.approve')->middleware('throttle:30,1');
        Route::post('/verificacoes-kyc/usuario/{user}/rejeitar', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'reject'])->name('kyc.reject')->middleware('throttle:30,1');
        Route::post('/verificacoes-kyc/usuario/{user}/aprovar-migracao-pj', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'approvePjConversion'])->name('kyc.approve-pj-conversion')->middleware('throttle:30,1');
        Route::post('/verificacoes-kyc/usuario/{user}/rejeitar-migracao-pj', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'rejectPjConversion'])->name('kyc.reject-pj-conversion')->middleware('throttle:30,1');
        Route::post('/verificacoes-kyc/usuario/{user}/consultar-cnpj', [\App\Http\Controllers\Platform\KycVerificationsController::class, 'refreshCnpj'])->name('kyc.refresh-cnpj')->middleware('throttle:20,1');

        Route::get('/log-infoprodutor', [\App\Http\Controllers\Platform\SellerActivityLogsController::class, 'index'])
            ->name('seller-activity-logs.index');
        Route::get('/webhooks', [\App\Http\Controllers\Platform\InboundWebhooksController::class, 'index'])
            ->name('webhooks.index');

        Route::get('/gerenciar-plugins', [\App\Http\Controllers\PluginsController::class, 'index'])->name('plugins.index');
        Route::get('/gerenciar-plugins/store-plugins-list', [\App\Http\Controllers\PluginsController::class, 'storePluginsList'])->name('plugins.store.list');
        Route::get('/gerenciar-plugins/store-plugin/{slug}', [\App\Http\Controllers\PluginStoreController::class, 'show'])->name('plugins.store.show')->where('slug', '[a-z0-9\-]+');
        Route::post('/gerenciar-plugins/install/{slug}', [\App\Http\Controllers\PluginInstallController::class, '__invoke'])->name('plugins.install')->where('slug', '[a-z0-9\-]+')->middleware('throttle:10,1');
        Route::post('/gerenciar-plugins/install-from-zip', [\App\Http\Controllers\PluginInstallController::class, 'installFromZip'])->name('plugins.install.from-zip')->middleware('throttle:10,1');
        Route::post('/gerenciar-plugins/register-plugin/{slug}', [\App\Http\Controllers\PluginsController::class, 'registerPlugin'])->name('plugins.register')->where('slug', '[a-z0-9\-_]+')->middleware('throttle:10,1');

        Route::get('/email-marketing', [\App\Http\Controllers\EmailMarketingController::class, 'index'])->name('email-marketing.index');
        Route::get('/email-marketing/create', [\App\Http\Controllers\EmailMarketingController::class, 'create'])->name('email-marketing.create');
        Route::post('/email-marketing/preview-recipients', [\App\Http\Controllers\EmailMarketingController::class, 'previewRecipientsByFilter'])->name('email-marketing.preview-recipients-by-filter');
        Route::post('/email-marketing', [\App\Http\Controllers\EmailMarketingController::class, 'store'])->name('email-marketing.store');
        Route::get('/email-marketing/{campaign}/edit', [\App\Http\Controllers\EmailMarketingController::class, 'edit'])->name('email-marketing.edit');
        Route::put('/email-marketing/{campaign}', [\App\Http\Controllers\EmailMarketingController::class, 'update'])->name('email-marketing.update');
        Route::post('/email-marketing/{campaign}/preview-recipients', [\App\Http\Controllers\EmailMarketingController::class, 'previewRecipients'])->name('email-marketing.preview-recipients');
        Route::post('/email-marketing/{campaign}/send', [\App\Http\Controllers\EmailMarketingController::class, 'send'])->name('email-marketing.send');

        Route::get('/integrax', [\App\Http\Controllers\Platform\IntegraxController::class, 'index'])->name('integrax.index');
        Route::put('/integrax', [\App\Http\Controllers\Platform\IntegraxController::class, 'update'])->name('integrax.update');
        Route::post('/integrax/test', [\App\Http\Controllers\Platform\IntegraxController::class, 'test'])->name('integrax.test');
    });
});

// Equipe: cargos e membros (infoprodutor; equipe apenas se tiver permissão)
Route::middleware(['auth', 'admin.tenant', 'seller.panel', 'stacker.license', 'role:infoprodutor|team|admin', 'team.permission:equipe.manage'])
    ->prefix('usuarios/equipe')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\EquipeController::class, 'index'])->name('usuarios.equipe');

        Route::post('/cargos', [\App\Http\Controllers\EquipeController::class, 'storeRole'])->name('usuarios.equipe.cargos.store');
        Route::put('/cargos/{role}', [\App\Http\Controllers\EquipeController::class, 'updateRole'])->name('usuarios.equipe.cargos.update');
        Route::delete('/cargos/{role}', [\App\Http\Controllers\EquipeController::class, 'destroyRole'])->name('usuarios.equipe.cargos.destroy');

        Route::post('/membros', [\App\Http\Controllers\EquipeController::class, 'storeMember'])->name('usuarios.equipe.membros.store');
        Route::put('/membros/{member}', [\App\Http\Controllers\EquipeController::class, 'updateMember'])->name('usuarios.equipe.membros.update');
        Route::delete('/membros/{member}', [\App\Http\Controllers\EquipeController::class, 'destroyMember'])->name('usuarios.equipe.membros.destroy');

        Route::post('/logs/clear', [\App\Http\Controllers\EquipeController::class, 'clearLogs'])->name('usuarios.equipe.logs.clear');
    });

Route::middleware(['auth', 'admin.tenant', 'seller.panel', 'stacker.license', 'role:infoprodutor|team|admin', 'audit.log'])->group(function () {
    Route::post('/coproducao/convite/{token}/aceitar', [\App\Http\Controllers\CoproductionInviteController::class, 'accept'])
        ->name('coproduction.invite.accept')
        ->middleware('throttle:20,1')
        ->where('token', '[A-Za-z0-9]{32,64}');
    Route::post('/afiliar/{token}', [\App\Http\Controllers\AffiliateJoinController::class, 'enroll'])
        ->name('affiliate.join.enroll')
        ->middleware('throttle:30,1')
        ->where('token', '[a-z0-9]{32,64}');
    Route::post('/painel/idioma', [\App\Http\Controllers\PanelLanguageController::class, 'switch'])->name('panel.language.switch');
    Route::post('/painel/push-subscribe', [\App\Http\Controllers\PanelPwaController::class, 'pushSubscribe'])->name('panel.pwa.push-subscribe')->middleware('throttle:20,1');
    Route::get('/painel/notifications', [\App\Http\Controllers\PanelNotificationsController::class, 'index'])->name('panel.notifications.index');
    Route::patch('/painel/notifications/{notification}/read', [\App\Http\Controllers\PanelNotificationsController::class, 'markRead'])->name('panel.notifications.mark-read');
    Route::post('/painel/notifications/mark-read', [\App\Http\Controllers\PanelNotificationsController::class, 'markReadBatch'])->name('panel.notifications.mark-read-batch');
    Route::post('/painel/notifications/mark-all-read', [\App\Http\Controllers\PanelNotificationsController::class, 'markAllRead'])->name('panel.notifications.mark-all-read');
    Route::delete('/painel/notifications', [\App\Http\Controllers\PanelNotificationsController::class, 'clearAll'])->name('panel.notifications.clear-all');
    Route::get('/cloud/billing/status', function (Request $request) {
        if (! config('getfy.cloud_mode')) {
            abort(404);
        }

        $token = (string) env('GETFY_CLOUD_INSTALL_TOKEN', '');
        $base = (string) config('getfy.cloud.orch_api_base_url', '');
        if ($token === '' || $base === '') {
            return response()->json(['enabled' => false]);
        }

        $cacheMinutes = max(1, (int) config('getfy.cloud.billing_cache_minutes', 10));
        $cacheKey = 'cloud:billing:status';
        $lastGoodKey = 'cloud:billing:status:last_good';

        try {
            $payload = Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($base, $token, $lastGoodKey) {
                $url = $base.'/v1/public/billing/status';
                $hostHeader = parse_url($url, PHP_URL_HOST);
                $headers = array_filter([
                    'Authorization' => 'Bearer '.$token,
                    'Host' => $hostHeader ?: null,
                ]);

                $res = Http::timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders($headers)
                    ->get($url);

                if ($res->status() === 401) {
                    return ['enabled' => false];
                }

                if (! $res->successful()) {
                    throw new \RuntimeException('Orchestrator retornou HTTP '.$res->status().'.');
                }

                $json = $res->json();
                if (! is_array($json)) {
                    throw new \RuntimeException('Resposta inválida do Orchestrator.');
                }

                $payload = ['enabled' => true] + $json;
                $payload['portalUrl'] = 'http://getfy.cloud/login';
                Cache::put($lastGoodKey, $payload, now()->addMinutes(60));

                return $payload;
            });

            return response()->json(is_array($payload) ? $payload : ['enabled' => false]);
        } catch (\Throwable $e) {
            $last = Cache::get($lastGoodKey);
            if (is_array($last) && isset($last['enabled'])) {
                return response()->json($last);
            }

            report($e);

            return response()->json(['enabled' => false]);
        }
    })->name('cloud.billing.status')->middleware('throttle:60,1');
    Route::get('/conquistas', [\App\Http\Controllers\ConquistasController::class, 'index'])->name('conquistas.index');
    Route::get('/meu-perfil', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile.index');
    Route::post('/meu-perfil', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/meu-perfil/username', [\App\Http\Controllers\ProfileController::class, 'updateUsername'])->name('profile.update-username');
    Route::put('/meu-perfil/senha', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.update-password');
    Route::put('/meu-perfil/preferencias-push', [\App\Http\Controllers\ProfileController::class, 'updatePushPreferences'])->name('profile.push-preferences');
    Route::post('/meu-perfil/migrar-para-cnpj', [\App\Http\Controllers\PjConversionController::class, 'start'])->middleware('throttle:10,1')->name('profile.pj-conversion.start');
    Route::post('/meu-perfil/migrar-para-cnpj/consultar', [\App\Http\Controllers\PjConversionController::class, 'lookupCnpj'])->middleware('throttle:20,1')->name('profile.pj-conversion.lookup');
    Route::post('/meu-perfil/migrar-para-cnpj/cancelar', [\App\Http\Controllers\PjConversionController::class, 'cancel'])->middleware('throttle:10,1')->name('profile.pj-conversion.cancel');
    Route::post('/seguranca/totp/iniciar', [\App\Http\Controllers\TotpSecurityController::class, 'beginTotp'])->name('security.totp.begin');
    Route::post('/seguranca/totp/confirmar', [\App\Http\Controllers\TotpSecurityController::class, 'confirmTotp'])->name('security.totp.confirm');
    Route::post('/seguranca/totp/desativar', [\App\Http\Controllers\TotpSecurityController::class, 'disableTotp'])->name('security.totp.disable');

    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, '__invoke'])
        ->middleware('team.permission:dashboard.view')
        ->name('dashboard');

    Route::redirect('/reembolsos', '/vendas/reembolsos')->middleware('team.permission:vendas.view');

    Route::get('/kyc', [\App\Http\Controllers\SellerKycController::class, 'show'])->name('kyc.upload');
    Route::post('/kyc/preferences', [\App\Http\Controllers\SellerKycController::class, 'updatePreferences'])->middleware('throttle:30,1')->name('kyc.preferences');
    Route::post('/kyc/document', [\App\Http\Controllers\SellerKycController::class, 'uploadDocument'])->middleware('throttle:30,1')->name('kyc.document');
    Route::post('/kyc/finalize', [\App\Http\Controllers\SellerKycController::class, 'finalize'])->middleware('throttle:15,1')->name('kyc.finalize');
    Route::post('/kyc', [\App\Http\Controllers\SellerKycController::class, 'store'])->middleware('throttle:15,1')->name('kyc.store');

    Route::middleware('team.permission:financeiro.view')->group(function () {
        Route::get('/financeiro', [\App\Http\Controllers\SellerFinancialController::class, 'index'])->name('financeiro.seller.index');
        Route::post('/financeiro/saque', [\App\Http\Controllers\SellerFinancialController::class, 'storeWithdrawal'])
            ->middleware('throttle:20,1')
            ->name('financeiro.seller.saque');
        Route::post('/financeiro/pix-saque', [\App\Http\Controllers\SellerFinancialController::class, 'storePayoutPixKey'])
            ->middleware('throttle:15,1')
            ->name('financeiro.seller.pix-saque');
        Route::get('/financeiro/saques/{withdrawal}/comprovante', [\App\Http\Controllers\WithdrawalReceiptController::class, 'seller'])
            ->name('financeiro.seller.receipt');
    });

    Route::get('/indique-e-ganhe', [\App\Http\Controllers\ReferralProgramController::class, 'index'])
        ->name('indique-ganhe.index');
    Route::post('/indique-e-ganhe/gerar-codigo', [\App\Http\Controllers\ReferralProgramController::class, 'ensureCode'])
        ->middleware('throttle:10,1')
        ->name('indique-ganhe.ensure-code');
    Route::post('/indique-e-ganhe/saque', [\App\Http\Controllers\ReferralProgramController::class, 'storeWithdrawal'])
        ->middleware('throttle:20,1')
        ->name('indique-ganhe.saque');

    Route::middleware('team.permission:pixgo.view')->group(function () {
        Route::get('/pixgo', [\App\Http\Controllers\PixGoController::class, 'index'])->name('pixgo.index');
        Route::post('/pixgo/cobrar', [\App\Http\Controllers\PixGoController::class, 'charge'])
            ->middleware('throttle:30,1')
            ->name('pixgo.cobrar');
        Route::get('/pixgo/cobranca/{token}', [\App\Http\Controllers\PixGoController::class, 'showCharge'])->name('pixgo.cobranca');
        Route::get('/pixgo/status', [\App\Http\Controllers\PixGoController::class, 'status'])->name('pixgo.status');
    });

    Route::middleware('team.permission:vendas.view')->group(function () {
        Route::get('/vendas', [\App\Http\Controllers\VendasController::class, 'index'])->name('vendas.index');
        Route::get('/vendas/export', [\App\Http\Controllers\VendasController::class, 'export'])->name('vendas.export');
        Route::post('/vendas/{order}/resend-access-email', [\App\Http\Controllers\VendasController::class, 'resendAccessEmail'])->name('vendas.resend-access-email');
        Route::post('/vendas/{order}/reembolsar', [\App\Http\Controllers\VendasController::class, 'refundManually'])->name('vendas.refund-manually');
        Route::post('/vendas/{order}/approve-manually', [\App\Http\Controllers\VendasController::class, 'approveManually'])->name('vendas.approve-manually');
        Route::get('/vendas/disputas', [\App\Http\Controllers\SellerMedDisputesController::class, 'index'])->name('disputas.index');
        Route::get('/vendas/disputas/{dispute}', [\App\Http\Controllers\SellerMedDisputesController::class, 'show'])->name('disputas.show');
        Route::post('/vendas/disputas/{dispute}/defesa', [\App\Http\Controllers\SellerMedDisputesController::class, 'submitDefense'])
            ->middleware('throttle:15,1')
            ->name('disputas.defense');
        Route::post('/vendas/disputas/{dispute}/gerar-dossie', [\App\Http\Controllers\SellerMedDisputesController::class, 'generateDossier'])
            ->middleware('throttle:15,1')
            ->name('disputas.generate-dossier');
        Route::get('/vendas/disputas/{dispute}/dossie', [\App\Http\Controllers\SellerMedDisputesController::class, 'downloadDossier'])
            ->name('disputas.download-dossier');
        Route::get('/vendas/reembolsos', [\App\Http\Controllers\SellerRefundRequestsController::class, 'index'])
            ->name('reembolsos.index');
        Route::post('/vendas/reembolsos/{refundRequest}/aprovar', [\App\Http\Controllers\SellerRefundRequestsController::class, 'approve'])
            ->middleware('throttle:30,1')
            ->name('reembolsos.approve');
        Route::post('/vendas/reembolsos/{refundRequest}/recusar', [\App\Http\Controllers\SellerRefundRequestsController::class, 'reject'])
            ->middleware('throttle:30,1')
            ->name('reembolsos.reject');
    });

    Route::redirect('/disputas', '/vendas/disputas')->middleware('team.permission:vendas.view');
    Route::get('/disputas/{dispute}', function (\App\Models\MedDispute $dispute) {
        return redirect()->route('disputas.show', $dispute);
    })->middleware('team.permission:vendas.view')->whereNumber('dispute');

    Route::middleware('team.permission:produtos.view')->group(function () {
        Route::get('/afiliados', [\App\Http\Controllers\AffiliateManagementController::class, 'index'])->name('afiliados.index');
        Route::post('/afiliados/enrollments/{enrollment}/approve', [\App\Http\Controllers\AffiliateManagementController::class, 'approve'])
            ->name('afiliados.enrollments.approve')
            ->middleware('throttle:60,1');
        Route::post('/afiliados/enrollments/{enrollment}/reject', [\App\Http\Controllers\AffiliateManagementController::class, 'reject'])
            ->name('afiliados.enrollments.reject')
            ->middleware('throttle:60,1');
        Route::post('/afiliados/enrollments/{enrollment}/revoke', [\App\Http\Controllers\AffiliateManagementController::class, 'revoke'])
            ->name('afiliados.enrollments.revoke')
            ->middleware('throttle:60,1');
        Route::middleware('physical.products')->group(function () {
            Route::get('/frete', [\App\Http\Controllers\ShippingController::class, 'index'])->name('frete.index');
            Route::post('/frete/lojas', [\App\Http\Controllers\ShippingController::class, 'storeStore'])->name('frete.stores.store');
            Route::put('/frete/lojas/{store}', [\App\Http\Controllers\ShippingController::class, 'updateStore'])->name('frete.stores.update');
            Route::delete('/frete/lojas/{store}', [\App\Http\Controllers\ShippingController::class, 'destroyStore'])->name('frete.stores.destroy');
            Route::get('/frete/lojas/{store}/regras', [\App\Http\Controllers\ShippingController::class, 'rules'])->name('frete.rules.index');
            Route::post('/frete/lojas/{store}/regras', [\App\Http\Controllers\ShippingController::class, 'storeRule'])->name('frete.rules.store');
            Route::put('/frete/lojas/{store}/regras/{rule}', [\App\Http\Controllers\ShippingController::class, 'updateRule'])->name('frete.rules.update');
            Route::delete('/frete/lojas/{store}/regras/{rule}', [\App\Http\Controllers\ShippingController::class, 'destroyRule'])->name('frete.rules.destroy');
            Route::post('/frete/lojas/{store}/regras/reorder', [\App\Http\Controllers\ShippingController::class, 'reorderRules'])->name('frete.rules.reorder');
        });
        Route::get('/produtos', [\App\Http\Controllers\ProdutosController::class, 'index'])->name('produtos.index');
        Route::get('/produtos/afiliados', [\App\Http\Controllers\AffiliateProductPanelController::class, 'index'])->name('produtos.afiliados.index');
        Route::get('/produtos/afiliados/dashboard', \App\Http\Controllers\AffiliateDashboardController::class)->name('produtos.afiliados.dashboard');
        Route::get('/produtos/afiliados/vendas', [\App\Http\Controllers\AffiliateSalesController::class, 'index'])->name('produtos.afiliados.vendas');
        Route::get('/produtos/afiliados/relatorios', [\App\Http\Controllers\AffiliateReportsController::class, 'index'])->name('produtos.afiliados.relatorios');
        Route::get('/produtos/afiliados/metricas', [\App\Http\Controllers\AffiliateMetricsTrackingController::class, 'index'])->name('produtos.afiliados.metrics.index');
        Route::get('/produtos/afiliados/metricas/origem', [\App\Http\Controllers\AffiliateMetricsTrackingController::class, 'origins'])->name('produtos.afiliados.metrics.origins');
        Route::get('/produtos/afiliados/metricas/funil', [\App\Http\Controllers\AffiliateMetricsTrackingController::class, 'funnel'])->name('produtos.afiliados.metrics.funnel');
        Route::get('/produtos/afiliados/metricas/cliques', [\App\Http\Controllers\AffiliateMetricsTrackingController::class, 'clicks'])->name('produtos.afiliados.metrics.clicks');
        Route::get('/produtos/{produto}/painel-afiliado', [\App\Http\Controllers\AffiliateProductPanelController::class, 'show'])->name('produtos.painel-afiliado.show');
        Route::put('/produtos/{produto}/painel-afiliado', [\App\Http\Controllers\AffiliateProductPanelController::class, 'updatePixels'])->name('produtos.painel-afiliado.update');
        Route::get('/coproducao', [\App\Http\Controllers\CoproductionPanelController::class, 'index'])->name('coproducao.index');
        Route::redirect('/produtos/coproducao', '/coproducao')->name('produtos.coproducao');
        Route::get('/produtos/coproducao/metricas', [\App\Http\Controllers\CoproducerMetricsTrackingController::class, 'index'])->name('produtos.coproducao.metrics.index');
        Route::get('/produtos/coproducao/metricas/origem', [\App\Http\Controllers\CoproducerMetricsTrackingController::class, 'origins'])->name('produtos.coproducao.metrics.origins');
        Route::get('/produtos/coproducao/metricas/funil', [\App\Http\Controllers\CoproducerMetricsTrackingController::class, 'funnel'])->name('produtos.coproducao.metrics.funnel');
        Route::get('/produtos/coproducao/metricas/cliques', [\App\Http\Controllers\CoproducerMetricsTrackingController::class, 'clicks'])->name('produtos.coproducao.metrics.clicks');
        Route::get('/produtos/vitrine-afiliacao', [\App\Http\Controllers\AffiliateShowcaseController::class, 'index'])->name('produtos.vitrine-afiliacao');
        Route::post('/produtos/vitrine-afiliacao/{product}/solicitar', [\App\Http\Controllers\AffiliateShowcaseController::class, 'enroll'])
            ->name('produtos.vitrine-afiliacao.solicitar')
            ->middleware('throttle:30,1');
        Route::get('/produtos/create', [\App\Http\Controllers\ProdutosController::class, 'create'])->name('produtos.create');
        Route::post('/produtos', [\App\Http\Controllers\ProdutosController::class, 'store'])->name('produtos.store');
        Route::get('/produtos/{produto}/edit', [\App\Http\Controllers\ProdutosController::class, 'edit'])->name('produtos.edit');
        Route::get('/produtos/{produto}/checkout/edit', [\App\Http\Controllers\CheckoutConfigController::class, 'edit'])->name('checkout.builder');
        Route::post('/produtos/{produto}/checkout/ensure-slug', [\App\Http\Controllers\ProdutosController::class, 'ensureCheckoutSlug'])->name('produtos.checkout.ensure-slug');
        Route::delete('/produtos/{produto}/checkout/remove-slug', [\App\Http\Controllers\ProdutosController::class, 'removeCheckoutSlug'])->name('produtos.checkout.remove-slug');
        Route::put('/produtos/{produto}/checkout-config', [\App\Http\Controllers\CheckoutConfigController::class, 'update'])->name('checkout.config.update');
        Route::post('/produtos/{produto}/checkout-upload', [\App\Http\Controllers\CheckoutConfigController::class, 'uploadImage'])->name('checkout.upload');
        Route::post('/produtos/{produto}/coproducers', [\App\Http\Controllers\ProductCoproductionController::class, 'store'])->name('produtos.coproducers.store')->middleware('throttle:30,1');
        Route::delete('/produtos/{produto}/coproducers/{coproducer}', [\App\Http\Controllers\ProductCoproductionController::class, 'destroy'])->name('produtos.coproducers.destroy');
        Route::put('/produtos/{produto}/affiliate-settings', [\App\Http\Controllers\ProductAffiliateController::class, 'updateSettings'])->name('produtos.affiliate-settings.update');
        Route::post('/produtos/{produto}/affiliate-invite-token/regenerate', [\App\Http\Controllers\ProductAffiliateController::class, 'regenerateInviteToken'])->name('produtos.affiliate-invite-token.regenerate');
        Route::post('/produtos/{produto}/affiliate-enrollments/{enrollment}/approve', [\App\Http\Controllers\ProductAffiliateController::class, 'approve'])->name('produtos.affiliate-enrollments.approve');
        Route::post('/produtos/{produto}/affiliate-enrollments/{enrollment}/reject', [\App\Http\Controllers\ProductAffiliateController::class, 'reject'])->name('produtos.affiliate-enrollments.reject');
        Route::post('/produtos/{produto}/affiliate-enrollments/{enrollment}/revoke', [\App\Http\Controllers\ProductAffiliateController::class, 'revoke'])->name('produtos.affiliate-enrollments.revoke');
    });
    Route::middleware('team.permission:produtos.view')->group(function () {
        Route::get('/produtos/{produto}/upsell-page/edit', [\App\Http\Controllers\UpsellDownsellPageController::class, 'editUpsellPage'])->name('upsell-page.edit');
        Route::put('/produtos/{produto}/upsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateUpsellPage'])->name('upsell-page.update');
        Route::post('/produtos/{produto}/upsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateUpsellPage'])->name('upsell-page.update.post');
        Route::get('/produtos/{produto}/downsell-page/edit', [\App\Http\Controllers\UpsellDownsellPageController::class, 'editDownsellPage'])->name('downsell-page.edit');
        Route::put('/produtos/{produto}/downsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateDownsellPage'])->name('downsell-page.update');
        Route::post('/produtos/{produto}/downsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateDownsellPage'])->name('downsell-page.update.post');
        Route::put('/produtos/{produto}', [\App\Http\Controllers\ProdutosController::class, 'update'])->name('produtos.update');
        Route::post('/produtos/{produto}/email-template-logo', [\App\Http\Controllers\ProdutosController::class, 'uploadEmailTemplateLogo'])->name('produtos.email-template-logo');
        Route::delete('/produtos/{produto}', [\App\Http\Controllers\ProdutosController::class, 'destroy'])->name('produtos.destroy');
        Route::post('/produtos/{produto}/duplicate', [\App\Http\Controllers\ProdutosController::class, 'duplicate'])->name('produtos.duplicate');
        Route::post('/produtos/{produto}/reenviar-analise', [\App\Http\Controllers\ProdutosController::class, 'resubmitForReview'])
            ->name('produtos.resubmit')
            ->middleware('throttle:30,1');
        Route::post('/produtos/{produto}/alunos', [\App\Http\Controllers\ProdutosController::class, 'addAluno'])->name('produtos.alunos.add');
        Route::post('/produtos/{produto}/offers', [\App\Http\Controllers\ProdutosController::class, 'storeOffer'])->name('produtos.offers.store');
        Route::put('/produtos/{produto}/offers/{offer}', [\App\Http\Controllers\ProdutosController::class, 'updateOffer'])->name('produtos.offers.update');
        Route::delete('/produtos/{produto}/offers/{offer}', [\App\Http\Controllers\ProdutosController::class, 'destroyOffer'])->name('produtos.offers.destroy');
        Route::post('/produtos/{produto}/order-bumps', [\App\Http\Controllers\ProdutosController::class, 'storeOrderBump'])->name('produtos.order-bumps.store');
        Route::put('/produtos/{produto}/order-bumps/{bump}', [\App\Http\Controllers\ProdutosController::class, 'updateOrderBump'])->name('produtos.order-bumps.update');
        Route::delete('/produtos/{produto}/order-bumps/{bump}', [\App\Http\Controllers\ProdutosController::class, 'destroyOrderBump'])->name('produtos.order-bumps.destroy');
        Route::post('/produtos/{produto}/subscription-plans', [\App\Http\Controllers\ProdutosController::class, 'storeSubscriptionPlan'])->name('produtos.subscription-plans.store');
        Route::put('/produtos/{produto}/subscription-plans/{plan}', [\App\Http\Controllers\ProdutosController::class, 'updateSubscriptionPlan'])->name('produtos.subscription-plans.update');
        Route::delete('/produtos/{produto}/subscription-plans/{plan}', [\App\Http\Controllers\ProdutosController::class, 'destroySubscriptionPlan'])->name('produtos.subscription-plans.destroy');
        Route::put('/produtos/{produto}/external-member-area', [\App\Http\Controllers\ProdutosController::class, 'updateExternalMemberArea'])->name('produtos.external-member-area.update');
        Route::get('/produtos/cupons', [\App\Http\Controllers\CuponsController::class, 'index'])->name('cupons.index');
        Route::post('/produtos/cupons', [\App\Http\Controllers\CuponsController::class, 'store'])->name('cupons.store');
        Route::put('/produtos/cupons/{coupon}', [\App\Http\Controllers\CuponsController::class, 'update'])->name('cupons.update');
        Route::delete('/produtos/cupons/{coupon}', [\App\Http\Controllers\CuponsController::class, 'destroy'])->name('cupons.destroy');
        Route::get('/produtos/alunos', [\App\Http\Controllers\AlunosController::class, 'index'])->name('alunos.index');
        Route::get('/produtos/alunos/{aluno}', [\App\Http\Controllers\AlunosController::class, 'show'])->name('alunos.show')->where('aluno', '[0-9]+');
        Route::post('/produtos/alunos', [\App\Http\Controllers\AlunosController::class, 'store'])->name('alunos.store');
        Route::get('/produtos/alunos/import-example', [\App\Http\Controllers\AlunosController::class, 'downloadImportExample'])->name('alunos.import-example');
        Route::post('/produtos/alunos/import', [\App\Http\Controllers\AlunosController::class, 'import'])->name('alunos.import');
        Route::put('/produtos/alunos/{aluno}', [\App\Http\Controllers\AlunosController::class, 'update'])->name('alunos.update')->where('aluno', '[0-9]+');
        Route::delete('/produtos/alunos/{aluno}', [\App\Http\Controllers\AlunosController::class, 'destroy'])->name('alunos.destroy')->where('aluno', '[0-9]+');
        Route::delete('/produtos/alunos/{aluno}/produtos/{produto}', [\App\Http\Controllers\AlunosController::class, 'removeProduct'])->name('alunos.remove-product')->where('aluno', '[0-9]+');

        // Member Builder (área de membros do produto)
        Route::get('/produtos/{produto}/member-builder', [\App\Http\Controllers\MemberBuilderController::class, 'index'])->name('member-builder.index');
        Route::put('/produtos/{produto}/member-builder/config', [\App\Http\Controllers\MemberBuilderController::class, 'updateConfig'])->name('member-builder.config.update');
        // POST aceito para config: frontend envia JSON e em muitos ambientes _method não é aplicado a body JSON
        Route::post('/produtos/{produto}/member-builder/config', [\App\Http\Controllers\MemberBuilderController::class, 'updateConfig'])->name('member-builder.config.update.post');
        Route::post('/produtos/{produto}/member-builder/upload', [\App\Http\Controllers\MemberBuilderController::class, 'uploadImage'])->name('member-builder.upload');
        Route::post('/produtos/{produto}/member-builder/upload-pdf', [\App\Http\Controllers\MemberBuilderController::class, 'uploadPdf'])->name('member-builder.upload-pdf');
        Route::post('/produtos/{produto}/member-builder/upload-badge', [\App\Http\Controllers\MemberBuilderController::class, 'uploadBadge'])->name('member-builder.upload-badge');
        Route::post('/produtos/{produto}/member-builder/reorder', [\App\Http\Controllers\MemberBuilderController::class, 'reorder'])->name('member-builder.reorder');
        Route::post('/produtos/{produto}/member-builder/sections', [\App\Http\Controllers\MemberBuilderController::class, 'storeSection'])->name('member-builder.sections.store');
        Route::put('/produtos/{produto}/member-builder/sections/{section}', [\App\Http\Controllers\MemberBuilderController::class, 'updateSection'])->name('member-builder.sections.update');
        Route::delete('/produtos/{produto}/member-builder/sections/{section}', [\App\Http\Controllers\MemberBuilderController::class, 'destroySection'])->name('member-builder.sections.destroy');
        Route::post('/produtos/{produto}/member-builder/sections/{section}/modules', [\App\Http\Controllers\MemberBuilderController::class, 'storeModule'])->name('member-builder.modules.store');
        Route::put('/produtos/{produto}/member-builder/modules/{module}', [\App\Http\Controllers\MemberBuilderController::class, 'updateModule'])->name('member-builder.modules.update');
        Route::delete('/produtos/{produto}/member-builder/modules/{module}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyModule'])->name('member-builder.modules.destroy');
        Route::post('/produtos/{produto}/member-builder/modules/{module}/lessons', [\App\Http\Controllers\MemberBuilderController::class, 'storeLesson'])->name('member-builder.lessons.store');
        Route::put('/produtos/{produto}/member-builder/lessons/{lesson}', [\App\Http\Controllers\MemberBuilderController::class, 'updateLesson'])->name('member-builder.lessons.update');
        Route::delete('/produtos/{produto}/member-builder/lessons/{lesson}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyLesson'])->name('member-builder.lessons.destroy');
        Route::post('/produtos/{produto}/member-builder/internal-products', [\App\Http\Controllers\MemberBuilderController::class, 'storeInternalProduct'])->name('member-builder.internal-products.store');
        Route::delete('/produtos/{produto}/member-builder/internal-products/{internalProduct}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyInternalProduct'])->name('member-builder.internal-products.destroy');
        Route::post('/produtos/{produto}/member-builder/turmas', [\App\Http\Controllers\MemberBuilderController::class, 'storeTurma'])->name('member-builder.turmas.store');
        Route::put('/produtos/{produto}/member-builder/turmas/{turma}', [\App\Http\Controllers\MemberBuilderController::class, 'updateTurma'])->name('member-builder.turmas.update');
        Route::delete('/produtos/{produto}/member-builder/turmas/{turma}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyTurma'])->name('member-builder.turmas.destroy');
        Route::post('/produtos/{produto}/member-builder/turmas/{turma}/users', [\App\Http\Controllers\MemberBuilderController::class, 'attachTurmaUser'])->name('member-builder.turmas.users.attach');
        Route::delete('/produtos/{produto}/member-builder/turmas/{turma}/users/{user}', [\App\Http\Controllers\MemberBuilderController::class, 'detachTurmaUser'])->name('member-builder.turmas.users.detach');
        Route::post('/produtos/{produto}/member-builder/alunos', [\App\Http\Controllers\MemberBuilderController::class, 'storeNewAluno'])->name('member-builder.alunos.store');
        Route::get('/produtos/{produto}/member-builder/comments', [\App\Http\Controllers\MemberBuilderController::class, 'commentsIndex'])->name('member-builder.comments.index');
        Route::put('/produtos/{produto}/member-builder/comments/{comment}', [\App\Http\Controllers\MemberBuilderController::class, 'updateComment'])->name('member-builder.comments.update');
        Route::post('/produtos/{produto}/member-builder/community-pages', [\App\Http\Controllers\MemberBuilderController::class, 'storeCommunityPage'])->name('member-builder.community-pages.store');
        Route::put('/produtos/{produto}/member-builder/community-pages/{page}', [\App\Http\Controllers\MemberBuilderController::class, 'updateCommunityPage'])->name('member-builder.community-pages.update')->whereNumber('page');
        // POST aceito para update/delete: JSON + PUT/DELETE falham em alguns proxies/servidores
        Route::post('/produtos/{produto}/member-builder/community-pages/{page}', [\App\Http\Controllers\MemberBuilderController::class, 'updateCommunityPage'])->name('member-builder.community-pages.update.post')->whereNumber('page');
        Route::delete('/produtos/{produto}/member-builder/community-pages/{page}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyCommunityPage'])->name('member-builder.community-pages.destroy')->whereNumber('page');
        Route::post('/produtos/{produto}/member-builder/community-pages/{page}/delete', [\App\Http\Controllers\MemberBuilderController::class, 'destroyCommunityPage'])->name('member-builder.community-pages.destroy.post')->whereNumber('page');
        Route::post('/produtos/{produto}/member-builder/send-push', [\App\Http\Controllers\MemberBuilderController::class, 'sendPushNotification'])->name('member-builder.send-push');
    });

    Route::middleware('team.permission:vendas.view')->group(function () {
        Route::get('/vendas/assinaturas', [\App\Http\Controllers\AssinaturasController::class, 'index'])->name('assinaturas.index');
        Route::get('/vendas/assinaturas/{subscription}', [\App\Http\Controllers\AssinaturasController::class, 'show'])
            ->name('assinaturas.show')
            ->whereNumber('subscription');
        Route::post('/vendas/assinaturas/{subscription}/cancel', [\App\Http\Controllers\AssinaturasController::class, 'cancel'])
            ->name('assinaturas.cancel')
            ->whereNumber('subscription');
    });
    Route::get('/relatorios', [\App\Http\Controllers\RelatoriosController::class, 'index'])
        ->middleware('team.permission:relatorios.view')
        ->name('relatorios.index');
    Route::get('/relatorios/carrinhos-abandonados/export', [\App\Http\Controllers\RelatoriosController::class, 'exportAbandonedCarts'])
        ->middleware(['throttle:30,1', 'team.permission:relatorios.view'])
        ->name('relatorios.abandoned-carts.export');

    Route::middleware('team.permission:metrics.view')->group(function () {
        Route::get('/metricas', [\App\Http\Controllers\MetricsTrackingController::class, 'index'])->name('metrics.index');
        Route::get('/metricas/origem', [\App\Http\Controllers\MetricsTrackingController::class, 'origins'])->name('metrics.origins');
        Route::get('/metricas/funil', [\App\Http\Controllers\MetricsTrackingController::class, 'funnel'])->name('metrics.funnel');
        Route::get('/metricas/cliques', [\App\Http\Controllers\MetricsTrackingController::class, 'clicks'])->name('metrics.clicks');
        Route::get('/metricas/mapa', [\App\Http\Controllers\MetricsTrackingController::class, 'map'])->name('metrics.map');
        Route::get('/metricas/export.csv', [\App\Http\Controllers\MetricsTrackingController::class, 'exportCsv'])
            ->middleware('throttle:30,1')
            ->name('metrics.export');
        Route::get('/metricas/export.xlsx', [\App\Http\Controllers\MetricsTrackingController::class, 'exportXlsx'])
            ->middleware('throttle:20,1')
            ->name('metrics.export.xlsx');
    });

    Route::get('/integracoes', [\App\Http\Controllers\IntegrationsController::class, 'index'])
        ->middleware('team.permission:integracoes.view')
        ->name('integrations.index');

    // API de pagamentos – aplicações
    Route::middleware('team.permission:api_pagamentos.view')->group(function () {
        Route::get('/aplicacoes-api', [\App\Http\Controllers\ApiApplicationsController::class, 'index'])->name('api-applications.index');
        Route::get('/aplicacoes-api/create', [\App\Http\Controllers\ApiApplicationsController::class, 'create'])->name('api-applications.create');
        Route::post('/aplicacoes-api', [\App\Http\Controllers\ApiApplicationsController::class, 'store'])->name('api-applications.store');
        Route::get('/aplicacoes-api/{apiApplication}/edit', [\App\Http\Controllers\ApiApplicationsController::class, 'edit'])->name('api-applications.edit');
        Route::put('/aplicacoes-api/{apiApplication}', [\App\Http\Controllers\ApiApplicationsController::class, 'update'])->name('api-applications.update');
        Route::delete('/aplicacoes-api/{apiApplication}', [\App\Http\Controllers\ApiApplicationsController::class, 'destroy'])->name('api-applications.destroy');
        Route::post('/aplicacoes-api/{apiApplication}/keys', [\App\Http\Controllers\ApiApplicationsController::class, 'storeApiKey'])->name('api-applications.keys.store');
        Route::put('/aplicacoes-api/{apiApplication}/keys/{apiKey}', [\App\Http\Controllers\ApiApplicationsController::class, 'updateApiKey'])->name('api-applications.keys.update');
        Route::post('/aplicacoes-api/{apiApplication}/keys/{apiKey}/regenerate', [\App\Http\Controllers\ApiApplicationsController::class, 'regenerateApiKey'])->name('api-applications.keys.regenerate');
        Route::post('/aplicacoes-api/{apiApplication}/keys/{apiKey}/reveal-secret', [\App\Http\Controllers\ApiApplicationsController::class, 'revealApiKeySecret'])
            ->middleware('throttle:30,1')
            ->name('api-applications.keys.reveal-secret');
        Route::delete('/aplicacoes-api/{apiApplication}/keys/{apiKey}', [\App\Http\Controllers\ApiApplicationsController::class, 'destroyApiKey'])->name('api-applications.keys.destroy');
        Route::patch('/aplicacoes-api/{apiApplication}/webhook', [\App\Http\Controllers\ApiApplicationsController::class, 'updateWebhook'])->name('api-applications.webhook.update');
        Route::post('/aplicacoes-api/{apiApplication}/webhook/rotate-secret', [\App\Http\Controllers\ApiApplicationsController::class, 'rotateWebhookSecret'])->name('api-applications.webhook.rotate-secret');
        Route::get('/aplicacoes-api/{apiApplication}/webhook/deliveries', [\App\Http\Controllers\ApiApplicationsController::class, 'webhookDeliveries'])->name('api-applications.webhook.deliveries');
        Route::post('/aplicacoes-api/{apiApplication}/regenerate-key', [\App\Http\Controllers\ApiApplicationsController::class, 'regenerateKey'])->name('api-applications.regenerate-key');
        Route::post('/aplicacoes-api/{apiApplication}/reveal-secret', [\App\Http\Controllers\ApiApplicationsController::class, 'revealSecret'])
            ->middleware('throttle:30,1')
            ->name('api-applications.reveal-secret');
        Route::post('/aplicacoes-api/toggle', [\App\Http\Controllers\ApiApplicationsController::class, 'updateApiPixToggle'])->name('api-applications.toggle');
    });
    Route::middleware('team.permission:integracoes.view')->group(function () {
        Route::post('/integracoes/plugins/{slug}/enable', [\App\Http\Controllers\IntegrationsController::class, 'enablePlugin'])->name('integrations.plugins.enable');
        Route::post('/integracoes/plugins/{slug}/disable', [\App\Http\Controllers\IntegrationsController::class, 'disablePlugin'])->name('integrations.plugins.disable');
        Route::delete('/integracoes/plugins/{slug}', [\App\Http\Controllers\IntegrationsController::class, 'uninstallPlugin'])->name('integrations.plugins.uninstall');

        Route::middleware('seller.integration:utmify')->group(function () {
            Route::post('/integracoes/utmify', [\App\Http\Controllers\UtmifyController::class, 'store'])->name('integrations.utmify.store');
            Route::put('/integracoes/utmify/{utmify}', [\App\Http\Controllers\UtmifyController::class, 'update'])->name('integrations.utmify.update');
            Route::delete('/integracoes/utmify/{utmify}', [\App\Http\Controllers\UtmifyController::class, 'destroy'])->name('integrations.utmify.destroy');
            Route::post('/integracoes/utmify/{utmify}/test', [\App\Http\Controllers\UtmifyController::class, 'test'])->name('integrations.utmify.test');
        });

        Route::middleware('seller.integration:spedy')->group(function () {
            Route::post('/integracoes/spedy', [\App\Http\Controllers\SpedyController::class, 'store'])->name('integrations.spedy.store');
            Route::put('/integracoes/spedy/{spedy}', [\App\Http\Controllers\SpedyController::class, 'update'])->name('integrations.spedy.update');
            Route::delete('/integracoes/spedy/{spedy}', [\App\Http\Controllers\SpedyController::class, 'destroy'])->name('integrations.spedy.destroy');
        });

        Route::middleware('seller.integration:cademi')->group(function () {
            Route::post('/integracoes/cademi', [\App\Http\Controllers\CademiController::class, 'store'])->name('integrations.cademi.store');
            Route::put('/integracoes/cademi/{cademi}', [\App\Http\Controllers\CademiController::class, 'update'])->name('integrations.cademi.update');
            Route::delete('/integracoes/cademi/{cademi}', [\App\Http\Controllers\CademiController::class, 'destroy'])->name('integrations.cademi.destroy');
            Route::get('/integracoes/cademi/{cademi}/tags', [\App\Http\Controllers\CademiController::class, 'tags'])->name('integrations.cademi.tags');
        });

        Route::middleware('seller.integration:webhook')->group(function () {
            Route::get('/integracoes/webhooks', [\App\Http\Controllers\WebhookController::class, 'index'])->name('integrations.webhooks.index');
            Route::post('/integracoes/webhooks', [\App\Http\Controllers\WebhookController::class, 'store'])->name('integrations.webhooks.store');
            Route::put('/integracoes/webhooks/{webhook}', [\App\Http\Controllers\WebhookController::class, 'update'])->name('integrations.webhooks.update');
            Route::delete('/integracoes/webhooks/{webhook}', [\App\Http\Controllers\WebhookController::class, 'destroy'])->name('integrations.webhooks.destroy');
            Route::post('/integracoes/webhooks/{webhook}/test', [\App\Http\Controllers\WebhookController::class, 'test'])->name('integrations.webhooks.test');
            Route::get('/integracoes/webhooks/{webhook}/logs', [\App\Http\Controllers\WebhookController::class, 'logs'])->name('integrations.webhooks.logs');
            Route::get('/integracoes/webhooks/{webhook}/logs/{log}', [\App\Http\Controllers\WebhookController::class, 'showLog'])->name('integrations.webhooks.logs.show');
        });
    });

});

Route::get('/docs/api-pagamentos', [\App\Http\Controllers\ApiDocsController::class, '__invoke'])->name('api-docs.pagamentos');
Route::get('/docs/api-pagamentos/ia', [\App\Http\Controllers\ApiDocsController::class, 'ia'])->name('api-docs.pagamentos.ia');
Route::get('/docs/api-pagamentos/llm/download', [\App\Http\Controllers\ApiDocsController::class, 'llmBundle'])->name('api-docs.pagamentos.llm');
Route::get('/docs/api-pagamentos/llm/full.md', [\App\Http\Controllers\ApiDocsController::class, 'llmBundle']);
Route::get('/docs/api-pagamentos/testar', [\App\Http\Controllers\ApiDocsController::class, 'testar'])->name('api-docs.pagamentos.testar');

// URLs antigas do painel do vendedor → painel da plataforma (operador)
Route::middleware(['auth'])->group(function () {
    Route::redirect('/configuracoes', '/plataforma/configuracoes', 302);
    Route::get('/configuracoes/{path}', function (string $path) {
        return redirect('/plataforma/configuracoes/'.$path, 302);
    })->where('path', '.*');
    Route::redirect('/gerenciar-plugins', '/plataforma/gerenciar-plugins', 302);
    Route::get('/gerenciar-plugins/{path}', function (string $path) {
        return redirect('/plataforma/gerenciar-plugins/'.$path, 302);
    })->where('path', '.*');
    Route::redirect('/email-marketing', '/plataforma/email-marketing', 302);
    Route::get('/email-marketing/{path}', function (string $path) {
        return redirect('/plataforma/email-marketing/'.$path, 302);
    })->where('path', '.*');
});

Route::middleware(['auth', 'customer.panel'])->group(function () {
    Route::get('/area-membros', [\App\Http\Controllers\MemberAreaController::class, 'index'])->name('member-area.index');
    Route::get('/painel-cliente', [\App\Http\Controllers\CustomerPanelController::class, 'index'])->name('painel-cliente.index');
    Route::post('/painel-cliente/reembolso', [\App\Http\Controllers\CustomerPanelController::class, 'requestRefund'])->name('painel-cliente.refund')->middleware('throttle:20,1');
});

Route::post('/painel/trocar', [\App\Http\Controllers\PanelSwitchController::class, 'switch'])->middleware(['auth', 'throttle:30,1'])->name('panel.switch');

// Área de membros por produto (path: /m/{slug})
Route::prefix('m/{slug}')->where(['slug' => '[a-zA-Z0-9]{6,16}'])->middleware('member.area.resolve')->group(function () {
    Route::get('manifest.json', [\App\Http\Controllers\MemberAreaAppController::class, 'manifest'])->name('member-area-app.manifest');
    Route::get('sw.js', function () {
        $path = public_path('member-area-sw.js');
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'application/javascript']);
    })->name('member-area-app.sw');
    Route::get('login', [\App\Http\Controllers\MemberAreaLoginController::class, 'showLoginForm'])->name('member-area.login')->middleware('guest');
    Route::post('login', [\App\Http\Controllers\MemberAreaLoginController::class, 'login'])->name('member-area.login.post')->middleware(['guest', 'throttle:login']);
    Route::post('login-without-password', [\App\Http\Controllers\MemberAreaLoginController::class, 'loginWithoutPassword'])->name('member-area.login.without-password')->middleware(['guest', 'throttle:login']);
    Route::get('esqueci-senha', [\App\Http\Controllers\MemberAreaForgotPasswordController::class, 'showLinkRequestForm'])->name('member-area.password.request')->middleware('guest');
    Route::post('esqueci-senha', [\App\Http\Controllers\MemberAreaForgotPasswordController::class, 'sendResetLinkEmail'])->name('member-area.password.email')->middleware(['guest', 'throttle:6,1']);
    Route::get('access', [\App\Http\Controllers\MemberAreaLoginController::class, 'magicAccess'])->name('member-area.magic-access')->middleware('member.area.magic-access');

    Route::middleware(['member.area.access'])->group(function () {
        Route::get('/', [\App\Http\Controllers\MemberAreaAppController::class, 'show'])->name('member-area-app.show');
        Route::get('modulos', fn (string $slug) => redirect()->route('member-area-app.show', $slug))->name('member-area-app.modulos');
        Route::get('modulo/{module}', [\App\Http\Controllers\MemberAreaAppController::class, 'moduleContent'])->name('member-area-app.module');
        Route::get('aula/{lesson}', [\App\Http\Controllers\MemberAreaAppController::class, 'lesson'])->name('member-area-app.lesson');
        Route::post('modulo/{module}/renovar-pix', [\App\Http\Controllers\MemberModuleRenewalController::class, 'createPix'])->middleware('throttle:10,1')->name('member-area-app.module.renew-pix');
        Route::get('modulo/{module}/renovar-pix/{order}', [\App\Http\Controllers\MemberModuleRenewalController::class, 'status'])->middleware('throttle:60,1')->name('member-area-app.module.renew-pix.status');
        Route::post('aula/{lesson}/complete', [\App\Http\Controllers\MemberAreaAppController::class, 'completeLesson'])->name('member-area-app.lesson.complete');
        Route::post('aula/{lesson}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeLessonComment'])->name('member-area-app.lesson.comments.store');
        Route::get('loja', [\App\Http\Controllers\MemberAreaAppController::class, 'loja'])->name('member-area-app.loja');
        Route::get('comunidade', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidade'])->name('member-area-app.comunidade');
        Route::get('comunidade/{pageSlug}', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidadePage'])->name('member-area-app.comunidade.page');
        Route::post('comunidade/{pageSlug}/posts', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPost'])->name('member-area-app.comunidade.posts.store');
        Route::delete('comunidade/{pageSlug}/posts/{post}', [\App\Http\Controllers\MemberAreaAppController::class, 'destroyCommunityPost'])->name('member-area-app.comunidade.posts.destroy');
        Route::post('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'likeCommunityPost'])->name('member-area-app.comunidade.posts.like');
        Route::delete('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'unlikeCommunityPost'])->name('member-area-app.comunidade.posts.unlike');
        Route::post('comunidade/{pageSlug}/posts/{post}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPostComment'])->name('member-area-app.comunidade.posts.comments.store');
        Route::get('certificado', [\App\Http\Controllers\MemberAreaAppController::class, 'certificado'])->name('member-area-app.certificado');
        Route::post('push-subscribe', [\App\Http\Controllers\MemberAreaAppController::class, 'pushSubscribe'])->name('member-area-app.push.subscribe');
        Route::get('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'index'])->name('member-area-app.notifications.index');
        Route::patch('notifications/{notification}/read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markRead'])->name('member-area-app.notifications.mark-read');
        Route::post('notifications/mark-all-read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markAllRead'])->name('member-area-app.notifications.mark-all-read');
        Route::delete('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'clearAll'])->name('member-area-app.notifications.clear-all');
        Route::put('conta', [\App\Http\Controllers\MemberAreaAccountController::class, 'updateProfile'])->name('member-area-app.conta.update');
        Route::put('conta/senha', [\App\Http\Controllers\MemberAreaAccountController::class, 'updatePassword'])->name('member-area-app.conta.password');
    });
});

// PWA e login da área de membros quando acessada por subdomínio ou domínio próprio (sem prefixo /m/slug)
Route::middleware(['web', 'member.area.resolve.by.host'])->group(function () {
    Route::get('sw.js', function () {
        $path = public_path('member-area-sw.js');
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'application/javascript']);
    })->name('member-area-app.sw.host');
    Route::get('access', [\App\Http\Controllers\MemberAreaLoginController::class, 'magicAccessHost'])->name('member-area.magic-access.host')->middleware('member.area.magic-access');
    // Login da área de membros por host: não registramos GET/POST /login aqui para não sobrescrever
    // o login da plataforma. O Auth\LoginController delega para MemberAreaLoginController quando
    // o host for de área de membros (subdomínio ou domínio próprio).
    Route::post('login-without-password', function (\Illuminate\Http\Request $request) {
        $slug = $request->attributes->get('member_area_slug');
        if (! $slug) {
            abort(404);
        }

        return app()->call(\App\Http\Controllers\MemberAreaLoginController::class.'@loginWithoutPassword', [
            'request' => $request,
            'slug' => $slug,
        ]);
    })->name('member-area.login.without-password.host')->middleware(['guest', 'throttle:login']);

    Route::middleware(['member.area.access'])->group(function () {
        Route::get('modulos', [\App\Http\Controllers\MemberAreaAppController::class, 'modulos'])->name('member-area-app.modulos.host');
        Route::get('modulo/{module}', [\App\Http\Controllers\MemberAreaAppController::class, 'moduleContent'])->name('member-area-app.module.host');
        Route::get('aula/{lesson}', [\App\Http\Controllers\MemberAreaAppController::class, 'lesson'])->name('member-area-app.lesson.host');
        Route::post('modulo/{module}/renovar-pix', [\App\Http\Controllers\MemberModuleRenewalController::class, 'createPix'])->middleware('throttle:10,1')->name('member-area-app.module.renew-pix.host');
        Route::get('modulo/{module}/renovar-pix/{order}', [\App\Http\Controllers\MemberModuleRenewalController::class, 'status'])->middleware('throttle:60,1')->name('member-area-app.module.renew-pix.status.host');
        Route::post('aula/{lesson}/complete', [\App\Http\Controllers\MemberAreaAppController::class, 'completeLesson'])->name('member-area-app.lesson.complete.host');
        Route::post('aula/{lesson}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeLessonComment'])->name('member-area-app.lesson.comments.store.host');
        Route::get('loja', [\App\Http\Controllers\MemberAreaAppController::class, 'loja'])->name('member-area-app.loja.host');
        Route::get('comunidade', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidade'])->name('member-area-app.comunidade.host');
        Route::get('comunidade/{pageSlug}', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidadePage'])->name('member-area-app.comunidade.page.host');
        Route::post('comunidade/{pageSlug}/posts', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPost'])->name('member-area-app.comunidade.posts.store.host');
        Route::delete('comunidade/{pageSlug}/posts/{post}', [\App\Http\Controllers\MemberAreaAppController::class, 'destroyCommunityPost'])->name('member-area-app.comunidade.posts.destroy.host');
        Route::post('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'likeCommunityPost'])->name('member-area-app.comunidade.posts.like.host');
        Route::delete('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'unlikeCommunityPost'])->name('member-area-app.comunidade.posts.unlike.host');
        Route::post('comunidade/{pageSlug}/posts/{post}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPostComment'])->name('member-area-app.comunidade.posts.comments.store.host');
        Route::get('certificado', [\App\Http\Controllers\MemberAreaAppController::class, 'certificado'])->name('member-area-app.certificado.host');
        Route::post('push-subscribe', [\App\Http\Controllers\MemberAreaAppController::class, 'pushSubscribe'])->name('member-area-app.push.subscribe.host');
        Route::get('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'index'])->name('member-area-app.notifications.index.host');
        Route::patch('notifications/{notification}/read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markRead'])->name('member-area-app.notifications.mark-read.host');
        Route::post('notifications/mark-all-read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markAllRead'])->name('member-area-app.notifications.mark-all-read.host');
        Route::delete('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'clearAll'])->name('member-area-app.notifications.clear-all.host');
        Route::put('conta', [\App\Http\Controllers\MemberAreaAccountController::class, 'updateProfile'])->name('member-area-app.conta.update.host');
        Route::put('conta/senha', [\App\Http\Controllers\MemberAreaAccountController::class, 'updatePassword'])->name('member-area-app.conta.password.host');
    });
});
