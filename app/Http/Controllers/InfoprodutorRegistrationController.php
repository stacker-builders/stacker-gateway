<?php

namespace App\Http\Controllers;

use App\Events\SellerRegistered;
use App\Models\ProductCoproducer;
use App\Models\TenantWallet;
use App\Models\User;
use App\Services\Checkout\TurnstileVerifier;
use App\Services\LegalDocumentsService;
use App\Services\PlatformEmailNotifications;
use App\Support\BrazilianDocuments;
use App\Support\CnpjLookup;
use App\Support\DockerSetupState;
use App\Support\EmailVerificationResendGuard;
use App\Support\HtmlSanitizer;
use App\Support\NormalizedEmail;
use App\Support\RegistrationEmailVerificationSettings;
use App\Services\PlatformAuditService;
use App\Support\RegistrationTurnstileSettings;
use App\Support\InfoproducerRegistrationSettings;
use App\Services\ReferralAttributionService;
use App\Support\ReferralProgramSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class InfoprodutorRegistrationController extends Controller
{
    public function __construct(
        protected PlatformEmailNotifications $platformEmailNotifications,
        protected TurnstileVerifier $turnstileVerifier,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        if (User::count() === 0) {
            return redirect()->route('criar-admin');
        }

        if ($blocked = $this->denyIfRegistrationClosed($request)) {
            return $blocked;
        }

        $response = Inertia::render('Auth/RegisterWizard', array_merge([
            'revenue_ranges' => self::revenueRangeOptions(),
            'coproducer_invite' => $request->query('coproducer_invite'),
            'referral_ref' => ReferralAttributionService::resolveCodeFromRequest($request),
            'upgrade_from_customer' => false,
        ], self::registrationWizardProps()));

        return $this->withReferralCookie($request, $response);
    }

    public function createUpgrade(Request $request): Response|RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }
        $user = Auth::user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }
        if (! $user->isCliente()) {
            return redirect($user->defaultAuthenticatedHomeUrl());
        }

        if ($blocked = $this->denyIfRegistrationClosed($request)) {
            return $blocked;
        }

        $response = Inertia::render('Auth/RegisterWizard', array_merge([
            'revenue_ranges' => self::revenueRangeOptions(),
            'coproducer_invite' => $request->query('coproducer_invite'),
            'referral_ref' => ReferralAttributionService::resolveCodeFromRequest($request),
            'upgrade_from_customer' => true,
        ], self::registrationWizardProps()));

        return $this->withReferralCookie($request, $response);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function revenueRangeOptions(): array
    {
        return [
            ['value' => 'up_to_10k', 'label' => 'Até R$ 10 mil'],
            ['value' => '10k_50k', 'label' => 'R$ 10 mil a R$ 50 mil'],
            ['value' => '50k_100k', 'label' => 'R$ 50 mil a R$ 100 mil'],
            ['value' => '100k_500k', 'label' => 'R$ 100 mil a R$ 500 mil'],
            ['value' => 'over_500k', 'label' => 'Acima de R$ 500 mil'],
        ];
    }

    public function validateEmail(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($blocked = $this->denyIfRegistrationClosedJson($request)) {
            return $blocked;
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = NormalizedEmail::normalize($validated['email']);

        if (NormalizedEmail::isReservedForRegistration($email)) {
            PlatformAuditService::log('security.registration_blocked_reserved_email', [
                'email' => $email,
                'context' => 'validate_email',
            ], $request);

            return response()->json([
                'available' => false,
                'message' => 'Este e-mail não pode ser usado para cadastro.',
            ]);
        }

        $ignoreId = Auth::check() ? Auth::id() : null;

        return response()->json([
            'available' => ! NormalizedEmail::isTaken($email, is_int($ignoreId) ? $ignoreId : null),
        ]);
    }

    public function validateDocument(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($blocked = $this->denyIfRegistrationClosedJson($request)) {
            return $blocked;
        }

        $validated = $request->validate([
            'person_type' => ['required', 'string', Rule::in(['pf', 'pj'])],
            'document' => ['required', 'string', 'max:20'],
            'legal_representative_cpf' => ['nullable', 'string', 'max:20'],
        ]);

        $docDigits = BrazilianDocuments::digits($validated['document']);

        if ($validated['person_type'] === 'pf') {
            if (! BrazilianDocuments::isValidCpf($docDigits)) {
                return response()->json([
                    'available' => false,
                    'message' => 'CPF inválido.',
                ], 422);
            }
            $docQ = User::query()->where('document', $docDigits);
            if (Auth::check()) {
                $docQ->where('id', '!=', Auth::id());
            }
            if ($docQ->exists()) {
                return response()->json([
                    'available' => false,
                    'field' => 'document',
                    'message' => 'Este CPF já está cadastrado.',
                ]);
            }

            return response()->json(['available' => true]);
        }

        if (! BrazilianDocuments::isValidCnpj($docDigits)) {
            return response()->json([
                'available' => false,
                'message' => 'CNPJ inválido.',
            ], 422);
        }

        $docQ2 = User::query()->where('document', $docDigits);
        if (Auth::check()) {
            $docQ2->where('id', '!=', Auth::id());
        }
        if ($docQ2->exists()) {
            return response()->json([
                'available' => false,
                'field' => 'document',
                'message' => 'Este CNPJ já está cadastrado.',
            ]);
        }

        $rep = BrazilianDocuments::digits((string) ($validated['legal_representative_cpf'] ?? ''));
        if ($rep === '' || ! BrazilianDocuments::isValidCpf($rep)) {
            return response()->json([
                'available' => false,
                'message' => 'CPF do representante legal inválido.',
            ], 422);
        }

        $lrQ = User::query()->where('legal_representative_cpf', $rep);
        if (Auth::check()) {
            $lrQ->where('id', '!=', Auth::id());
        }
        if ($lrQ->exists()) {
            return response()->json([
                'available' => false,
                'field' => 'legal_representative_cpf',
                'message' => 'Este CPF já está vinculado a outra conta.',
            ]);
        }

        $lrDoc = User::query()->where('document', $rep);
        if (Auth::check()) {
            $lrDoc->where('id', '!=', Auth::id());
        }
        if ($lrDoc->exists()) {
            return response()->json([
                'available' => false,
                'field' => 'legal_representative_cpf',
                'message' => 'Este CPF já está cadastrado como titular de outra conta.',
            ]);
        }

        return response()->json(['available' => true]);
    }

    /**
     * Consulta CNPJ na BrasilAPI para autocompletar razão social.
     * Nunca bloqueia o cadastro: falha devolve ok=false.
     */
    public function lookupCnpj(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($blocked = $this->denyIfRegistrationClosedJson($request)) {
            return $blocked;
        }

        $validated = $request->validate([
            'document' => ['required', 'string', 'max:20'],
        ]);

        $cnpj = BrazilianDocuments::digits($validated['document']);
        if (! BrazilianDocuments::isValidCnpj($cnpj)) {
            return response()->json([
                'ok' => false,
                'status' => 'invalid',
                'message' => 'CNPJ inválido.',
            ], 422);
        }

        try {
            $lookup = app(\App\Services\Cnpj\BrasilApiCnpjClient::class)->lookup($cnpj);
        } catch (\Throwable) {
            return response()->json(CnpjLookup::publicWizardPayload([
                'status' => \App\Services\Cnpj\BrasilApiCnpjClient::STATUS_UNAVAILABLE,
                'payload' => null,
            ]));
        }

        return response()->json(CnpjLookup::publicWizardPayload($lookup));
    }

    public function store(Request $request): RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        if (User::count() === 0) {
            abort(403, 'Cadastro indisponível.');
        }

        if ($blocked = $this->denyIfRegistrationClosed($request)) {
            return $blocked;
        }

        if (Auth::check() && Auth::user()->isCliente()) {
            return $this->upgradeClienteToInfoprodutor($request);
        }

        if ($turnstileError = $this->validateRegistrationTurnstile($request)) {
            return $turnstileError;
        }

        if ($honeypotError = $this->rejectRegistrationHoneypot($request)) {
            return $honeypotError;
        }

        $request->merge(['email' => NormalizedEmail::normalize($request->input('email'))]);

        $rules = [
            'person_type' => ['required', 'string', Rule::in(['pf', 'pj'])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'birth_date' => ['required', 'date', 'before:'.now()->subYears(18)->format('Y-m-d')],
            'document' => ['required', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'legal_representative_cpf' => ['nullable', 'string', 'max:20'],
            'cnpj_suggested_razao_social' => ['nullable', 'string', 'max:255'],
            'address_zip' => ['required', 'string', 'regex:/^\d{8}$/'],
            'address_street' => ['required', 'string', 'max:255'],
            'address_number' => ['required', 'string', 'max:32'],
            'address_complement' => ['nullable', 'string', 'max:120'],
            'address_neighborhood' => ['required', 'string', 'max:120'],
            'address_city' => ['required', 'string', 'max:120'],
            'address_state' => ['required', 'string', 'size:2'],
            'monthly_revenue_range' => ['required', 'string', Rule::in(User::MONTHLY_REVENUE_RANGES)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'coproducer_invite' => ['nullable', 'string', 'max:64'],
            'ref' => ['nullable', 'string', 'max:32'],
            'accept_terms_privacy' => ['accepted'],
        ];

        $validated = $request->validate($rules, [
            'email.unique' => 'Este e-mail já está em uso.',
            'birth_date.before' => 'É necessário ter pelo menos 18 anos.',
            'accept_terms_privacy.accepted' => 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.',
        ]);

        if (NormalizedEmail::isReservedForRegistration($validated['email'])) {
            PlatformAuditService::log('security.registration_blocked_reserved_email', [
                'email' => $validated['email'],
                'context' => 'store',
            ], $request);

            return back()->withErrors([
                'email' => 'Este e-mail não pode ser usado para cadastro.',
            ])->withInput();
        }

        // Campos de texto puro: previne XSS armazenado (endereços, nomes, etc.)
        foreach ([
            'name' => 255,
            'company_name' => 255,
            'cnpj_suggested_razao_social' => 255,
            'address_street' => 255,
            'address_number' => 32,
            'address_complement' => 120,
            'address_neighborhood' => 120,
            'address_city' => 120,
        ] as $k => $max) {
            if (array_key_exists($k, $validated)) {
                $validated[$k] = HtmlSanitizer::plainText($validated[$k], $max) ?: null;
            }
        }

        $docDigits = BrazilianDocuments::digits($validated['document']);
        if ($validated['person_type'] === 'pf') {
            if (! BrazilianDocuments::isValidCpf($docDigits)) {
                return back()->withErrors(['document' => 'CPF inválido.'])->withInput();
            }
        } else {
            if (! BrazilianDocuments::isValidCnpj($docDigits)) {
                return back()->withErrors(['document' => 'CNPJ inválido.'])->withInput();
            }
            if (empty(trim((string) ($validated['company_name'] ?? '')))) {
                return back()->withErrors(['company_name' => 'Informe a razão social da empresa.'])->withInput();
            }
            $rep = BrazilianDocuments::digits((string) ($validated['legal_representative_cpf'] ?? ''));
            if ($rep === '' || ! BrazilianDocuments::isValidCpf($rep)) {
                return back()->withErrors(['legal_representative_cpf' => 'CPF do representante legal inválido.'])->withInput();
            }
        }

        if (User::query()->where('document', $docDigits)->exists()) {
            return back()->withErrors([
                'document' => $validated['person_type'] === 'pf'
                    ? 'Este CPF já está cadastrado.'
                    : 'Este CNPJ já está cadastrado.',
            ])->withInput();
        }

        if ($validated['person_type'] === 'pj') {
            $repDigits = BrazilianDocuments::digits((string) $validated['legal_representative_cpf']);
            if (User::query()->where('legal_representative_cpf', $repDigits)->exists()) {
                return back()->withErrors(['legal_representative_cpf' => 'Este CPF já está vinculado a outra conta.'])->withInput();
            }
            if (User::query()->where('document', $repDigits)->exists()) {
                return back()->withErrors(['legal_representative_cpf' => 'Este CPF já está cadastrado como titular de outra conta.'])->withInput();
            }
        }

        $phoneDigits = $this->normalizePhoneDigits((string) ($validated['phone'] ?? ''));
        if ($phoneDigits === null) {
            return back()->withErrors(['phone' => 'Informe um WhatsApp válido com DDD (10 ou 11 dígitos).'])->withInput();
        }

        $user = User::create([
            'name' => (string) ($validated['name'] ?? ''),
            'email' => $validated['email'],
            'phone' => $phoneDigits,
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => $validated['person_type'],
            'document' => $docDigits,
            'birth_date' => $validated['birth_date'],
            'company_name' => $validated['person_type'] === 'pj' ? ($validated['company_name'] ?? null) : null,
            'legal_representative_cpf' => $validated['person_type'] === 'pj'
                ? BrazilianDocuments::digits((string) $validated['legal_representative_cpf'])
                : null,
            'address_zip' => $validated['address_zip'],
            'address_street' => $validated['address_street'] ?? '',
            'address_number' => $validated['address_number'] ?? '',
            'address_complement' => $validated['address_complement'] ?? null,
            'address_neighborhood' => $validated['address_neighborhood'] ?? '',
            'address_city' => $validated['address_city'] ?? '',
            'address_state' => strtoupper($validated['address_state']),
            'monthly_revenue_range' => $validated['monthly_revenue_range'],
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'seller_onboarded_at' => now(),
            'email_verified_at' => RegistrationEmailVerificationSettings::isEnabled() ? null : now(),
        ]);

        $user->update(['tenant_id' => $user->id]);

        $this->persistCnpjLookupIfPj($user->fresh(), $validated, $docDigits);

        $this->attachReferralIfPresent($request, $user, $validated['ref'] ?? null);

        try {
            app(\App\Services\AccountManagerAssignmentService::class)->autoAssignIfConfigured($user->fresh(), $request);
        } catch (\Throwable) {
            // Não bloqueia o cadastro.
        }

        $this->recordLegalConsent($user);

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->firstOrCreate(
                ['tenant_id' => $user->tenant_id],
                [
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'currency' => 'BRL',
                    'available_pix' => 0,
                    'available_card' => 0,
                    'available_boleto' => 0,
                    'pending_pix' => 0,
                    'pending_card' => 0,
                    'pending_boleto' => 0,
                ]
            );
        }

        $this->platformEmailNotifications->welcomeInfoprodutor($user->fresh());
        SellerRegistered::dispatch($user->fresh());

        $verificationEmailSent = null;
        if (RegistrationEmailVerificationSettings::isEnabled()) {
            $freshUser = $user->fresh();
            $verificationEmailSent = $this->platformEmailNotifications->sendEmailVerification($freshUser);
            if ($verificationEmailSent) {
                EmailVerificationResendGuard::markResent($freshUser);
            }
        }

        Auth::login($user);
        $request->session()->regenerate();

        $inviteAccepted = false;
        if (! empty($validated['coproducer_invite'])) {
            $inviteAccepted = ProductCoproducer::tryActivateAfterRegistration($user->fresh(), $validated['coproducer_invite']);
        }

        $msg = $inviteAccepted
            ? 'Conta criada e co-produção ativada. Envie seus documentos de verificação (KYC) para acessar o painel.'
            : 'Conta criada. Envie seus documentos de verificação de identidade (KYC) para acessar o painel do infoprodutor.';

        return $this->redirectAfterRegistration($user, $msg, $verificationEmailSent);
    }

    /**
     * Cliente autenticado completa cadastro e vira infoprodutor (mesma conta).
     */
    private function upgradeClienteToInfoprodutor(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user instanceof User || ! $user->isCliente()) {
            abort(403);
        }

        if ($turnstileError = $this->validateRegistrationTurnstile($request)) {
            return $turnstileError;
        }

        if ($honeypotError = $this->rejectRegistrationHoneypot($request)) {
            return $honeypotError;
        }

        $request->merge(['email' => NormalizedEmail::normalize($request->input('email'))]);

        $rules = [
            'person_type' => ['required', 'string', Rule::in(['pf', 'pj'])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:32'],
            'birth_date' => ['required', 'date', 'before:'.now()->subYears(18)->format('Y-m-d')],
            'document' => ['required', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'legal_representative_cpf' => ['nullable', 'string', 'max:20'],
            'cnpj_suggested_razao_social' => ['nullable', 'string', 'max:255'],
            'address_zip' => ['required', 'string', 'regex:/^\d{8}$/'],
            'address_street' => ['required', 'string', 'max:255'],
            'address_number' => ['required', 'string', 'max:32'],
            'address_complement' => ['nullable', 'string', 'max:120'],
            'address_neighborhood' => ['required', 'string', 'max:120'],
            'address_city' => ['required', 'string', 'max:120'],
            'address_state' => ['required', 'string', 'size:2'],
            'monthly_revenue_range' => ['required', 'string', Rule::in(User::MONTHLY_REVENUE_RANGES)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'coproducer_invite' => ['nullable', 'string', 'max:64'],
            'ref' => ['nullable', 'string', 'max:32'],
            'accept_terms_privacy' => ['accepted'],
        ];

        $validated = $request->validate($rules, [
            'email.unique' => 'Este e-mail já está em uso.',
            'birth_date.before' => 'É necessário ter pelo menos 18 anos.',
            'accept_terms_privacy.accepted' => 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.',
        ]);

        if (NormalizedEmail::isReservedForRegistration($validated['email'])) {
            PlatformAuditService::log('security.registration_blocked_reserved_email', [
                'email' => $validated['email'],
                'context' => 'upgrade',
            ], $request);

            return back()->withErrors([
                'email' => 'Este e-mail não pode ser usado para cadastro.',
            ])->withInput();
        }

        foreach ([
            'name' => 255,
            'company_name' => 255,
            'cnpj_suggested_razao_social' => 255,
            'address_street' => 255,
            'address_number' => 32,
            'address_complement' => 120,
            'address_neighborhood' => 120,
            'address_city' => 120,
        ] as $k => $max) {
            if (array_key_exists($k, $validated)) {
                $validated[$k] = HtmlSanitizer::plainText($validated[$k], $max) ?: null;
            }
        }

        $docDigits = BrazilianDocuments::digits($validated['document']);
        if ($validated['person_type'] === 'pf') {
            if (! BrazilianDocuments::isValidCpf($docDigits)) {
                return back()->withErrors(['document' => 'CPF inválido.'])->withInput();
            }
        } else {
            if (! BrazilianDocuments::isValidCnpj($docDigits)) {
                return back()->withErrors(['document' => 'CNPJ inválido.'])->withInput();
            }
            if (empty(trim((string) ($validated['company_name'] ?? '')))) {
                return back()->withErrors(['company_name' => 'Informe a razão social da empresa.'])->withInput();
            }
            $rep = BrazilianDocuments::digits((string) ($validated['legal_representative_cpf'] ?? ''));
            if ($rep === '' || ! BrazilianDocuments::isValidCpf($rep)) {
                return back()->withErrors(['legal_representative_cpf' => 'CPF do representante legal inválido.'])->withInput();
            }
        }

        if (User::query()->where('document', $docDigits)->where('id', '!=', $user->id)->exists()) {
            return back()->withErrors([
                'document' => $validated['person_type'] === 'pf'
                    ? 'Este CPF já está cadastrado.'
                    : 'Este CNPJ já está cadastrado.',
            ])->withInput();
        }

        if ($validated['person_type'] === 'pj') {
            $repDigits = BrazilianDocuments::digits((string) $validated['legal_representative_cpf']);
            if (User::query()->where('legal_representative_cpf', $repDigits)->where('id', '!=', $user->id)->exists()) {
                return back()->withErrors(['legal_representative_cpf' => 'Este CPF já está vinculado a outra conta.'])->withInput();
            }
            if (User::query()->where('document', $repDigits)->where('id', '!=', $user->id)->exists()) {
                return back()->withErrors(['legal_representative_cpf' => 'Este CPF já está cadastrado como titular de outra conta.'])->withInput();
            }
        }

        $emailChanged = $user->email !== $validated['email'];
        $needsEmailVerification = RegistrationEmailVerificationSettings::isEnabled()
            && ($emailChanged || $user->email_verified_at === null);

        $phoneDigits = $this->normalizePhoneDigits((string) ($validated['phone'] ?? ''));
        if ($phoneDigits === null) {
            return back()->withErrors(['phone' => 'Informe um WhatsApp válido com DDD (10 ou 11 dígitos).'])->withInput();
        }

        $user->update([
            'name' => (string) ($validated['name'] ?? ''),
            'email' => $validated['email'],
            'phone' => $phoneDigits,
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => $validated['person_type'],
            'document' => $docDigits,
            'birth_date' => $validated['birth_date'],
            'company_name' => $validated['person_type'] === 'pj' ? ($validated['company_name'] ?? null) : null,
            'legal_representative_cpf' => $validated['person_type'] === 'pj'
                ? BrazilianDocuments::digits((string) $validated['legal_representative_cpf'])
                : null,
            'address_zip' => $validated['address_zip'],
            'address_street' => $validated['address_street'] ?? '',
            'address_number' => $validated['address_number'] ?? '',
            'address_complement' => $validated['address_complement'] ?? null,
            'address_neighborhood' => $validated['address_neighborhood'] ?? '',
            'address_city' => $validated['address_city'] ?? '',
            'address_state' => strtoupper($validated['address_state']),
            'monthly_revenue_range' => $validated['monthly_revenue_range'],
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'seller_onboarded_at' => now(),
            'email_verified_at' => $needsEmailVerification ? null : ($user->email_verified_at ?? now()),
        ]);

        $user->update(['tenant_id' => $user->id]);

        $this->persistCnpjLookupIfPj($user->fresh(), $validated, $docDigits);

        $this->attachReferralIfPresent($request, $user, $validated['ref'] ?? null);

        try {
            app(\App\Services\AccountManagerAssignmentService::class)->autoAssignIfConfigured($user->fresh(), $request);
        } catch (\Throwable) {
            // Não bloqueia o cadastro.
        }

        $this->recordLegalConsent($user);

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->firstOrCreate(
                ['tenant_id' => $user->tenant_id],
                [
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'currency' => 'BRL',
                    'available_pix' => 0,
                    'available_card' => 0,
                    'available_boleto' => 0,
                    'pending_pix' => 0,
                    'pending_card' => 0,
                    'pending_boleto' => 0,
                ]
            );
        }

        $this->platformEmailNotifications->welcomeInfoprodutor($user->fresh());
        SellerRegistered::dispatch($user->fresh());

        $verificationEmailSent = null;
        if ($needsEmailVerification) {
            $freshUser = $user->fresh();
            $verificationEmailSent = $this->platformEmailNotifications->sendEmailVerification($freshUser);
            if ($verificationEmailSent) {
                EmailVerificationResendGuard::markResent($freshUser);
            }
        }

        $inviteAccepted = false;
        if (! empty($validated['coproducer_invite'])) {
            $inviteAccepted = ProductCoproducer::tryActivateAfterRegistration($user->fresh(), $validated['coproducer_invite']);
        }

        $msg = $inviteAccepted
            ? 'Conta de infoprodutor ativada e co-produção vinculada. Envie seus documentos de verificação (KYC) para acessar o painel.'
            : 'Conta de infoprodutor criada. Envie seus documentos de verificação (KYC) para acessar o painel.';

        return $this->redirectAfterRegistration($user, $msg, $verificationEmailSent);
    }

    /**
     * @return array<string, mixed>
     */
    private static function registrationWizardProps(): array
    {
        return [
            'registration_turnstile' => RegistrationTurnstileSettings::publicConfig(),
        ];
    }

    private function denyIfRegistrationClosed(Request $request): ?RedirectResponse
    {
        if (InfoproducerRegistrationSettings::requestMayRegister($request)) {
            return null;
        }

        return redirect()
            ->route('login')
            ->with('error', InfoproducerRegistrationSettings::BLOCKED_MESSAGE);
    }

    private function denyIfRegistrationClosedJson(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (InfoproducerRegistrationSettings::requestMayRegister($request)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => InfoproducerRegistrationSettings::BLOCKED_MESSAGE,
        ], 403);
    }

    private function validateRegistrationTurnstile(Request $request): ?RedirectResponse
    {
        if (! RegistrationTurnstileSettings::isRequired()) {
            return null;
        }

        $token = trim((string) $request->input('turnstile_token', ''));
        if ($token === '' || ! $this->turnstileVerifier->verify($token, $request->ip())) {
            return back()
                ->withErrors(['turnstile_token' => 'Confirme que você não é um robô e tente novamente.'])
                ->withInput();
        }

        return null;
    }

    private function rejectRegistrationHoneypot(Request $request): ?RedirectResponse
    {
        $honeypot = trim((string) $request->input('sg_hp', ''));
        if ($honeypot === '') {
            $honeypot = trim((string) $request->input('website', ''));
        }
        if ($honeypot === '') {
            return null;
        }

        PlatformAuditService::log('security.registration_honeypot', [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ], $request);

        Log::warning('registration honeypot triggered', [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return back()->withErrors([
            'email' => 'Não foi possível concluir o cadastro. Tente novamente.',
        ])->withInput();
    }

    private function redirectAfterRegistration(User $user, string $successMessage, ?bool $verificationEmailSent = null): RedirectResponse
    {
        if (RegistrationEmailVerificationSettings::requiresVerificationFor($user->fresh())) {
            $redirect = redirect()->route('verification.notice');

            if ($verificationEmailSent === false) {
                return $redirect->with(
                    'error',
                    'Conta criada, mas não foi possível enviar o e-mail de confirmação. Em Plataforma → Configurações → E-mail, salve o SMTP e use o teste de envio antes de reenviar.'
                );
            }

            return $redirect->with('success', 'Conta criada! Confirme seu e-mail para continuar.');
        }

        return redirect('/financeiro?tab=seus-dados')->with('success', $successMessage);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistCnpjLookupIfPj(User $user, array $validated, string $docDigits): void
    {
        if (($validated['person_type'] ?? '') !== 'pj' || ! CnpjLookup::columnExists()) {
            return;
        }

        try {
            CnpjLookup::persistForUser(
                $user,
                $docDigits,
                (string) ($validated['company_name'] ?? ''),
                isset($validated['cnpj_suggested_razao_social'])
                    ? (string) $validated['cnpj_suggested_razao_social']
                    : null,
            );
        } catch (\Throwable $e) {
            Log::notice('cnpj_lookup.persist_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            try {
                if (CnpjLookup::columnExists()) {
                    $user->forceFill([
                        'cnpj_lookup' => CnpjLookup::failedSnapshot(
                            \App\Services\Cnpj\BrasilApiCnpjClient::STATUS_UNAVAILABLE,
                            'exception',
                            (string) ($validated['company_name'] ?? ''),
                            isset($validated['cnpj_suggested_razao_social'])
                                ? (string) $validated['cnpj_suggested_razao_social']
                                : null,
                            $docDigits,
                        ),
                    ])->save();
                }
            } catch (\Throwable) {
                // Cadastro já concluído; o admin pode reconsultar no KYC.
            }
        }
    }

    private function recordLegalConsent(User $user): void
    {
        if (! Schema::hasColumn('users', 'privacy_policy_accepted_at')) {
            return;
        }

        $now = now();
        $version = app(LegalDocumentsService::class)->contentVersion();

        $user->forceFill([
            'privacy_policy_accepted_at' => $now,
            'terms_accepted_at' => $now,
            'legal_consent_version' => $version,
        ])->save();
    }

    /**
     * Normaliza WhatsApp BR para dígitos (com 55 se vier só DDD+número).
     */
    private function normalizePhoneDigits(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 10) {
            return null;
        }
        if (strlen($digits) <= 11 && ! str_starts_with($digits, '55')) {
            $digits = '55'.$digits;
        }
        if (strlen($digits) < 12 || strlen($digits) > 13) {
            return null;
        }

        return $digits;
    }

    private function withReferralCookie(Request $request, Response $response): Response
    {
        if (! ReferralProgramSettings::isEnabled()) {
            return $response;
        }

        $code = ReferralAttributionService::normalizeCode((string) $request->query('ref', ''));
        if ($code === null || ReferralAttributionService::findReferrerByCode($code) === null) {
            return $response;
        }

        cookie()->queue(ReferralAttributionService::makeReferralCookie($code));

        return $response;
    }

    private function attachReferralIfPresent(Request $request, User $user, ?string $refFromBody): void
    {
        $code = ReferralAttributionService::normalizeCode($refFromBody)
            ?? ReferralAttributionService::resolveCodeFromRequest($request);

        if ($code === null) {
            return;
        }

        ReferralAttributionService::attachOnRegistration($user->fresh(), $code);
    }
}

