<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ClearsAuthSessionCookies;
use App\Http\Controllers\Concerns\HandlesLoginTotpChallenge;
use App\Http\Controllers\Concerns\ValidatesAuthTurnstile;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\TeamAuditLog;
use App\Services\MemberAreaResolver;
use App\Services\SellerActivityLogService;
use App\Services\Platform\PlatformTotpService;
use App\Support\CustomerImpersonation;
use App\Support\DockerSetupState;
use App\Support\LoginTurnstileSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LoginController extends Controller
{
    use ClearsAuthSessionCookies;
    use HandlesLoginTotpChallenge;
    use ValidatesAuthTurnstile;

    /**
     * Exibe o login da plataforma ou, se o host for de área de membros (subdomínio/domínio próprio),
     * delega para o login da área de membros do produto.
     */
    public function showLoginForm(Request $request): Response|RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        if (User::count() === 0) {
            return redirect()->route('criar-admin');
        }

        $resolved = app(MemberAreaResolver::class)->resolve($request);
        if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
            $request->attributes->set('member_area_product', $resolved['product']);
            $request->attributes->set('member_area_access_type', $resolved['access_type']);
            $request->attributes->set('member_area_slug', $resolved['slug']);

            return app()->call(\App\Http\Controllers\MemberAreaLoginController::class.'@showLoginForm', [
                'request' => $request,
                'slug' => $resolved['slug'],
            ]);
        }

        // Evita redirecionar para URL antiga (ex.: /produtos/.../edit) após login via Inertia.
        // Mantém /oauth/authorize para o ChatGPT voltar ao consentimento MCP.
        $oauthIntended = $this->oauthAuthorizeIntendedPath($request);
        if ($oauthIntended === null) {
            $request->session()->forget('url.intended');
        } else {
            $request->session()->put('url.intended', $oauthIntended);
        }

        return Inertia::render('Auth/Login', [
            'login_turnstile' => LoginTurnstileSettings::publicConfig(),
        ]);
    }

    public function login(Request $request): RedirectResponse|HttpResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        $resolved = app(MemberAreaResolver::class)->resolve($request);
        if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
            $request->attributes->set('member_area_product', $resolved['product']);
            $request->attributes->set('member_area_access_type', $resolved['access_type']);
            $request->attributes->set('member_area_slug', $resolved['slug']);

            return app()->call(\App\Http\Controllers\MemberAreaLoginController::class.'@login', [
                'request' => $request,
                'slug' => $resolved['slug'],
            ]);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($turnstileError = $this->validateLoginTurnstile($request)) {
            return $turnstileError;
        }

        if (Auth::attempt($request->only('email', 'password'), (bool) $request->boolean('remember'))) {
            $user = Auth::user();
            // Contas do painel operador não entram por /login — resposta idêntica a senha errada
            // (não revelar existência de conta de operador nem o path /plataforma/login).
            if ($user && $user->canAccessPlatformPanel()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'Credenciais inválidas.',
                ])->onlyInput('email');
            }
            if ($user && $user->canAccessSellerPanel() && $user->sellerAccountAccessBlocked()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'Conta suspensa ou bloqueada. Contate o suporte.',
                ])->onlyInput('email');
            }
            $oauthIntended = $this->oauthAuthorizeIntendedPath($request);
            if ($user instanceof User && PlatformTotpService::requiresLoginChallenge($user)) {
                return $this->redirectToLoginTotpChallenge(
                    $request,
                    $user,
                    (bool) $request->boolean('remember'),
                    'seller',
                    'login.two-factor',
                    $user->canAccessSellerPanel() ? ($oauthIntended ?? '/dashboard') : '/painel-cliente',
                );
            }

            $request->session()->regenerate();
            if ($user && $user->tenant_id && $user->canAccessSellerPanel()) {
                TeamAuditLog::create([
                    'tenant_id' => $user->tenant_id,
                    'actor_user_id' => $user->id,
                    'action' => 'auth.login',
                    'metadata' => [
                        'method' => 'POST',
                        'path' => '/login',
                    ],
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
                SellerActivityLogService::record(
                    actor: $user,
                    action: SellerActivityLogService::AUTH_LOGIN,
                    request: $request,
                );
            }
            if ($user->canAccessSellerPanel()) {
                $request->session()->put('panel_context', 'seller');

                return $this->inertiaOrRedirectAfterLogin($request, $oauthIntended ?? '/dashboard');
            }

            if ($user->canAccessCustomerPanel()) {
                $request->session()->put('panel_context', 'customer');

                return $this->inertiaOrRedirectAfterLogin($request, '/painel-cliente');
            }

            return $this->inertiaOrRedirectAfterLogin($request, '/painel-cliente');
        }

        return back()->withErrors([
            'email' => 'Credenciais inválidas.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        if (CustomerImpersonation::isActive($request) || CustomerImpersonation::isExpired($request)) {
            return app(\App\Http\Controllers\Platform\CustomerImpersonationController::class)->leave($request);
        }

        $user = Auth::user();
        if ($user && $user->tenant_id && $user->canAccessSellerPanel()) {
            TeamAuditLog::create([
                'tenant_id' => $user->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'auth.logout',
                'metadata' => [
                    'method' => 'POST',
                    'path' => '/logout',
                ],
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
            SellerActivityLogService::record(
                actor: $user,
                action: SellerActivityLogService::AUTH_LOGOUT,
                request: $request,
            );
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->clearAuthSessionCookies();

        $to = $request->query('redirect');
        if (is_string($to) && $this->isSafeMemberAreaLoginRedirect($to)) {
            return redirect($to);
        }

        return redirect('/');
    }

    /**
     * Evita open redirect: só paths de login da área de membros (/m/{slug}/login ou /login em host dedicado).
     */
    private function isSafeMemberAreaLoginRedirect(string $path): bool
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }

        return (bool) preg_match('#^/m/[a-zA-Z0-9]{6,16}/login$#', $path)
            || $path === '/login';
    }

    /**
     * Só devolve o intended do consentimento MCP. Qualquer outro path é ignorado.
     */
    private function oauthAuthorizeIntendedPath(Request $request): ?string
    {
        $intended = $request->session()->get('url.intended');
        if (! is_string($intended) || $intended === '') {
            return null;
        }

        $path = $intended;
        if (str_starts_with($intended, 'http://') || str_starts_with($intended, 'https://')) {
            $parts = parse_url($intended);
            if (! is_array($parts)) {
                return null;
            }
            $path = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        if ($path === '' || str_starts_with($path, '//') || str_contains($path, '..')) {
            return null;
        }

        if (! str_starts_with($path, '/oauth/authorize')) {
            return null;
        }

        return $path;
    }
}
