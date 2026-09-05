<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        $proxyList = is_string($trustedProxies) && trim($trustedProxies) !== ''
            ? array_map('trim', explode(',', trim($trustedProxies)))
            : (env('APP_ENV', 'production') === 'local' ? '*' : null);
        $middleware->trustProxies(
            at: $proxyList,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Convites: painel da plataforma exige login em /plataforma/login
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->is('plataforma') || $request->is('plataforma/*')) {
                return url('/plataforma/login');
            }

            return url('/login');
        });

        // Webhooks recebem POST de gateways externos sem CSRF token
        // Checkout pixel mirror usa sendBeacon + checkout_session_token (público, throttled)
        $middleware->validateCsrfTokens(except: [
            'webhooks/gateways/*',
            'checkout/pixel/events',
            'checkout/pixel/purchase-ack',
            'api/metrics/collect',
        ]);

        $middleware->web(prepend: [
            \App\Http\Middleware\ForceLocalCanonicalHost::class,
            \App\Http\Middleware\ForceHttpsWhenForwardedProto::class,
            \App\Http\Middleware\EnsureDockerSetup::class,
            \App\Http\Middleware\EnsureInstalled::class,
            \App\Http\Middleware\ConfigureCheckoutIframeSession::class,
            \App\Http\Middleware\ApplyBrandingConfig::class,
            \App\Http\Middleware\SetPanelLocale::class,
        ], append: [
            \App\Http\Middleware\BlockMutationsWhenDemoMode::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\PreventCacheForHtml::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RunScheduleFallback::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\BlockMutationsWhenDemoMode::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'team.permission' => \App\Http\Middleware\EnsureTeamPermission::class,
            'audit.log' => \App\Http\Middleware\AuditLogMiddleware::class,
            'guest' => \App\Http\Middleware\EnsureGuest::class,
            'api.application' => \App\Http\Middleware\AuthenticateApiApplication::class,
            'api.scope' => \App\Http\Middleware\RequireApiScope::class,
            'api.request-id' => \App\Http\Middleware\AddApiRequestId::class,
            'member.area.resolve' => \App\Http\Middleware\ResolveMemberAreaProduct::class,
            'member.area.resolve.by.host' => \App\Http\Middleware\ResolveMemberAreaByHost::class,
            'member.area.access' => \App\Http\Middleware\EnsureMemberAreaAccess::class,
            'member.area.magic-access' => \App\Http\Middleware\ValidateMemberAreaMagicAccess::class,
            'admin.tenant' => \App\Http\Middleware\EnsureAdminHasTenant::class,
            'seller.panel' => \App\Http\Middleware\EnsureSellerPanel::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'customer.panel' => \App\Http\Middleware\EnsureCustomerPanel::class,
            'physical.products' => \App\Http\Middleware\EnsurePhysicalProductsEnabled::class,
            'seller.integration' => \App\Http\Middleware\EnsureSellerIntegrationEnabled::class,
            'installer.access' => \App\Http\Middleware\EnsureInstallerAccess::class,
            'mp.balance.tool' => \App\Http\Middleware\EnsureMercadoPagoBalanceToolEnabled::class,
            'stacker.license' => \App\Http\Middleware\EnsureStackerLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('plataforma/configuracoes/storage/test')
                || $request->is('plataforma/configuracoes/storage/migrate')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Erro interno no teste de storage.',
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = 'A requisição excedeu o limite do servidor. Envie um arquivo por vez (até 20 MB).';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['upload' => [$message]]], 413);
            }

            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('error', $message);
            }

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $message = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';

            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('error', $message);
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 429);
            }

            return redirect()->back()->with('error', $message);
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->header('X-Inertia')) {
                if ($request->user()) {
                    return Inertia::location($request->fullUrl());
                }

                $login = ($request->is('plataforma') || $request->is('plataforma/*'))
                    ? url('/plataforma/login')
                    : url('/login');

                return redirect()->to($login)->with('error', 'Sessão expirada. Tente fazer login novamente.');
            }

            return null;
        });

        $exceptions->render(function (\RuntimeException $e, Request $request) {
            if (! $request->header('X-Inertia') || ! $request->isMethod('POST') || ! $request->is('produtos')) {
                return null;
            }

            $message = trim($e->getMessage());
            if ($message === '') {
                return null;
            }

            return redirect()->back()
                ->withErrors(['image' => $message])
                ->with('error', $message)
                ->withInput();
        });

        // Fallback: se der erro por tabela/view inexistente e APP_AUTO_MIGRATE=true, roda migrate e redireciona para tentar de novo
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() && filter_var(config('getfy.auto_migrate', false), FILTER_VALIDATE_BOOLEAN)) {
                $message = $e->getMessage();
                $isTableMissing = $e instanceof QueryException
                    || str_contains($message, '42S02')
                    || str_contains($message, 'Base table or view not found')
                    || str_contains($message, "doesn't exist");
                $previous = $e->getPrevious();
                if (! $isTableMissing && $previous instanceof \Throwable) {
                    $message = $previous->getMessage();
                    $isTableMissing = str_contains($message, '42S02')
                        || str_contains($message, 'Base table or view not found')
                        || str_contains($message, "doesn't exist");
                }
                if ($isTableMissing) {
                    try {
                        Artisan::call('migrate', ['--force' => true]);
                        $url = $request->fullUrl();
                        if ($request->header('X-Inertia')) {
                            return redirect()->to($url)->with('success', 'Migrações executadas automaticamente. Página recarregada.');
                        }

                        return redirect()->to($url)->with('success', 'Migrações executadas automaticamente. Recarregue a página se necessário.');
                    } catch (\Throwable $migrateEx) {
                        report($migrateEx);
                    }
                }
            }

            return null;
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new \App\Jobs\SendSubscriptionRemindersJob)->dailyAt('09:00');
        $schedule->job(new \App\Jobs\ChargeDueSubscriptionsWithSavedCardJob)->dailyAt('07:00');
        $schedule->command('subscriptions:expire-due')->dailyAt('00:10');
        $schedule->command('checkout:fire-abandoned-cart-webhooks --minutes=10')->everyMinute();
        $schedule->command('integrax:process-cart-recovery')->everyMinute();
        $schedule->command('email-campaign:process')->everyMinute();
        $schedule->command('payments:reconcile-pending --limit=200 --days=45 --min-age-minutes=0')->everyTwoMinutes();
        $schedule->command('payments:reconcile-mercadopago --limit=100 --days=45 --min-age-minutes=0')->everyMinute();
        $schedule->command('payments:reconcile-pending --source=pixgo --limit=100 --days=1 --min-age-minutes=1')->everyMinute();
        $schedule->command('payments:reconcile-cajupay-refunds --limit=100')->everyMinute();
        $schedule->command('withdrawals:reconcile-spacepag --limit=80 --min-age-minutes=0')->everyMinute();
        $schedule->command('withdrawals:reconcile-woovi --limit=80 --min-age-minutes=0')->everyMinute();
        $schedule->command('withdrawals:reconcile-bspay --limit=80 --min-age-minutes=0')->everyMinute();
        $schedule->command('withdrawals:reconcile-cajupay --limit=80 --min-age-minutes=0')->everyTwoMinutes();
        $schedule->command('withdrawals:reconcile-versell --limit=80 --min-age-minutes=0')->everyTwoMinutes();
        $schedule->command('versell:reconcile-infractions --hours=72')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('settlement:release')->everyFiveMinutes();
        $schedule->command('schedule:heartbeat')->everyMinute();
        $schedule->command('push:process-schedule')->everyMinute();
        $schedule->command('backup:database')->everyMinute()->withoutOverlapping(30);
        $schedule->command('conquistas:reconcile')->dailyAt('03:30');
        $schedule->command('metrics:aggregate-daily --sync')->dailyAt('01:15');
        // Logs: daily + a cada 6h (evita flood diurno encher disco antes do prune noturno).
        $logsPrune = 'logs:prune --days='.(int) env('LOG_DAILY_DAYS', 7).' --max-mb=50 --max-total-mb=200';
        $schedule->command($logsPrune)->dailyAt('03:45');
        $schedule->command($logsPrune)->everySixHours();
        $schedule->command('queue:prune-failed --hours=168')->dailyAt('04:00');
        $schedule->command('inbound-webhooks:prune --days=14')->dailyAt('04:15');
        $schedule->job(new \App\Jobs\QueueHeartbeatJob)->everyMinute();
    })
    ->create();
