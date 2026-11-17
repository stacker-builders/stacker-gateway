<?php

namespace App\Http\Middleware;

use App\Support\CustomerImpersonation;
use App\Support\RegistrationEmailVerificationSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403, 'Acesso não autorizado.');
        }
        if (CustomerImpersonation::isActive($request)) {
            return redirect('/painel-cliente');
        }
        if ($user->canAccessPlatformPanel()) {
            return redirect()
                ->route('plataforma.dashboard')
                ->with('error', 'Use o painel da plataforma.');
        }
        if (! $user->canAccessSellerPanel()) {
            if ($request->expectsJson()) {
                abort(403, 'Acesso não autorizado.');
            }

            return redirect($user->sellerPanelFallbackUrl())
                ->with('info', 'O painel de vendas é para infoprodutores. Você foi redirecionado para o painel do cliente.');
        }

        if ($user->sellerAccountAccessBlocked()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Conta suspensa ou bloqueada. Contate o suporte.']);
        }

        if (RegistrationEmailVerificationSettings::requiresVerificationFor($user)
            && ! $this->isEmailVerificationRoute($request)) {
            return redirect()
                ->route('verification.notice')
                ->with('info', 'Confirme seu e-mail para continuar.');
        }

        if ($user->mustStayOnKycOnboardingRoutes() && ! $this->isKycOnboardingRoute($request)) {
            return redirect('/financeiro?tab=seus-dados')
                ->with('info', $user->sellerPanelRestrictedMessage());
        }

        return $next($request);
    }

    private function isEmailVerificationRoute(Request $request): bool
    {
        return $request->routeIs(
            'verification.notice',
            'verification.resend',
            'logout',
            'profile.index',
            'profile.update',
            'profile.update-username',
            'profile.update-password',
            'profile.push-preferences',
        );
    }

    private function isKycOnboardingRoute(Request $request): bool
    {
        return $request->routeIs(
            'kyc.upload',
            'kyc.preferences',
            'kyc.document',
            'kyc.finalize',
            'kyc.store',
            'kyc.didit',
            'financeiro.seller.index',
            'logout',
            'panel.switch',
            'profile.index',
            'profile.update',
            'profile.update-username',
            'profile.update-password',
            'profile.push-preferences',
            'profile.pj-conversion.start',
            'profile.pj-conversion.lookup',
            'profile.pj-conversion.cancel',
            'verification.notice',
            'verification.resend',
        );
    }
}
