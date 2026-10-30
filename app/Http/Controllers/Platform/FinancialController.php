<?php

namespace App\Http\Controllers\Platform;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Concerns\ProvidesPlatformGatewayProps;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileBspayWithdrawalJob;
use App\Jobs\ReconcileCajuPayWithdrawalJob;
use App\Jobs\ReconcileSpacepagWithdrawalJob;
use App\Jobs\ReconcileWooviWithdrawalJob;
use App\Jobs\ReconcileXflowWithdrawalJob;
use App\Jobs\ReconcileOktoWithdrawalJob;
use App\Jobs\ReconcileXpagWithdrawalJob;
use Plugins\OnlyUp\OnlyUpPayoutService;
use Plugins\OnlyUp\ReconcileOnlyUpWithdrawalJob;
use App\Http\Controllers\Platform\CajuPayAccountsController;
use App\Models\CajuPayAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Bspay\BspayPayoutService;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\CajuPay\CajuPayWithdrawalReconcileService;
use App\Services\Spacepag\SpacepagPayoutService;
use App\Services\Woovi\WooviPayoutService;
use App\Services\Xflow\XflowPayoutService;
use App\Services\Okto\OktoPayoutService;
use App\Services\Xpag\XpagPayoutService;
use App\Services\EffectiveMerchantFees;
use App\Support\CardInstallmentEconomics;
use App\Support\PercentDecimal;
use App\Services\EffectiveSettlementRules;
use App\Services\ApiPixAccess;
use App\Services\PixGoAccess;
use App\Services\PlatformCardInstallments;
use App\Services\MerchantWithdrawalService;
use App\Services\MinimumChargeService;
use App\Services\WithdrawalPixReceiptService;
use App\Services\Payout\PayoutUserSettings;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use App\Services\PlatformEmailNotifications;
use App\Services\PlatformPaymentMethods;
use App\Services\Withdrawal\WithdrawalMinimumService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Models\Setting;
use App\Support\KycNotificationEmails;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Support\PlatformConfigContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialController extends Controller
{
    use ProvidesPlatformGatewayProps;
    use RequiresPlatformStepUp;

    public function index(): Response
    {
        $tenantId = PlatformConfigContext::settingsTenantId();

        $feeDefaults = EffectiveMerchantFees::platformDefaults();
        $cardInstallmentRules = $feeDefaults['card_installments'] ?? [];
        $lowTicket = $feeDefaults['low_ticket'] ?? EffectiveMerchantFees::normalizeLowTicket(null);
        unset($feeDefaults['card_installments'], $feeDefaults['low_ticket']);

        return Inertia::render('Platform/Financial/Index', [
            'gateways' => $this->buildGatewaysListForSettings($tenantId),
            'gateway_order' => $this->buildGatewayOrderForSettings($tenantId),
            'cajupay_method_accounts' => app(CajuPayAccountResolver::class)->methodAccountMap($tenantId),
            'cajupay_accounts' => CajuPayAccountsController::listForAdmin(),
            'cajupay_credential_keys' => config('gateways.gateways.cajupay.credential_keys', []),
            'merchant_fee_rules' => $feeDefaults,
            'low_ticket_fees' => $lowTicket,
            'card_installment_rules' => $cardInstallmentRules,
            'platform_card_installments_enabled' => PlatformCardInstallments::globalEnabled(),
            'platform_card_installments_max' => PlatformCardInstallments::maxAllowed(),
            'merchant_settlement_rules' => EffectiveSettlementRules::platformDefaults(),
            'api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'api_pix_minimum_charge_brl' => app(MinimumChargeService::class)->apiPixMinimumBrl(),
            'platform_minimum_charge_brl' => app(MinimumChargeService::class)->platformMinimumBrl(),
            'platform_minimum_withdrawal_brl' => WithdrawalMinimumService::platformMinimumBrl(),
            'effective_minimum_withdrawal_brl' => WithdrawalMinimumService::effectiveRequiredMinNet(),
            'payout_gateway_min_brl' => (float) (GatewayPayoutEconomics::forActiveGateway()['payout_min_brl'] ?? 0),
            'payout_gateway_preference' => PlatformPayoutGateway::preference(),
            'payout_gateway_active' => PlatformPayoutGateway::activeSlug(),
            'gateway_webhook_security_warnings' => $this->gatewayWebhookSecurityWarnings($tenantId),
            'platform_payment_methods_enabled' => PlatformPaymentMethods::platformEnabled(),
            'platform_payment_method_labels' => PlatformPaymentMethods::labelsForAdmin(),
            'withdrawal_policy' => WithdrawalPolicyService::toFrontendProps(),
            'platform_totp_enabled' => PlatformTotpService::isEnabledFor(request()->user()),
            'pixgo_enabled' => PixGoAccess::globalEnabled(),
            'pixgo_sidebar_label' => PixGoAccess::sidebarLabel(),
        ]);
    }

    public function updateWithdrawalPolicy(Request $request): RedirectResponse
    {
        $pinMax = WithdrawalPolicyService::MANUAL_APPROVAL_PIN_MAX_LENGTH;
        $pinMin = WithdrawalPolicyService::MANUAL_APPROVAL_PIN_MIN_LENGTH;

        $validated = $request->validate([
            'auto_withdrawal_enabled' => ['nullable', 'boolean'],
            'hours_enabled' => ['nullable', 'boolean'],
            'hours_start' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'hours_end' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'current_manual_approval_pin' => ['nullable', 'string', "max:{$pinMax}", 'regex:/^\d*$/'],
            'manual_approval_pin' => ['nullable', 'string', "min:{$pinMin}", "max:{$pinMax}", 'regex:/^\d+$/'],
            'manual_approval_pin_confirmation' => ['nullable', 'string', "max:{$pinMax}", 'regex:/^\d*$/'],
            'totp_code' => ['nullable', 'string', 'max:16'],
        ]);

        Setting::set('platform_auto_withdrawal_enabled', $request->boolean('auto_withdrawal_enabled', true), null);
        Setting::set('platform_withdrawal_hours_enabled', $request->boolean('hours_enabled', false), null);
        Setting::set('platform_withdrawal_hours_start', $validated['hours_start'] ?? WithdrawalPolicyService::hoursStart(), null);
        Setting::set('platform_withdrawal_hours_end', $validated['hours_end'] ?? WithdrawalPolicyService::hoursEnd(), null);
        Setting::set('platform_withdrawal_timezone', $validated['timezone'] ?? WithdrawalPolicyService::timezone(), null);

        $pin = trim((string) ($validated['manual_approval_pin'] ?? ''));
        $pinConfirm = trim((string) ($validated['manual_approval_pin_confirmation'] ?? ''));
        $pinChanged = false;

        if ($pin !== '') {
            if ($pinConfirm === '' || $pin !== $pinConfirm) {
                throw ValidationException::withMessages([
                    'manual_approval_pin_confirmation' => 'A confirmação do PIN não confere.',
                ]);
            }

            $this->validatePlatformStepUp($request);

            if (WithdrawalPolicyService::hasManualApprovalPin()) {
                $currentPin = trim((string) ($validated['current_manual_approval_pin'] ?? ''));
                if ($currentPin === '') {
                    throw ValidationException::withMessages([
                        'current_manual_approval_pin' => 'Informe o PIN atual para trocar.',
                    ]);
                }

                WithdrawalPolicyService::changeManualApprovalPin($currentPin, $pin);
            } else {
                WithdrawalPolicyService::setManualApprovalPin($pin);
            }

            $pinChanged = true;
            PlatformAuditService::log('platform.withdrawal.pin_changed', [], $request);
        }

        PlatformAuditService::log('platform.withdrawal.policy_updated', [
            'auto_withdrawal_enabled' => $request->boolean('auto_withdrawal_enabled', true),
            'hours_enabled' => $request->boolean('hours_enabled', false),
            'pin_changed' => $pinChanged,
        ], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'saques'])
            ->with('success', 'Política de saques atualizada.');
    }

    public function resetManualApprovalPin(Request $request): RedirectResponse
    {
        $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
        ]);

        $this->validatePlatformStepUp($request);

        $user = $request->user();
        $rateKey = 'platform-pin-reset:'.($user?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return redirect()->route('plataforma.financeiro.index', ['tab' => 'saques'])
                ->with('error', 'Muitas tentativas de recuperação de PIN. Tente novamente em '.ceil($seconds / 60).' minuto(s).');
        }

        RateLimiter::hit($rateKey, 1800);

        $raw = Setting::get('kyc_notification_emails', '', null);
        $recipients = KycNotificationEmails::parse(is_string($raw) ? $raw : null);

        if ($recipients === []) {
            return redirect()->route('plataforma.financeiro.index', ['tab' => 'saques'])
                ->with('error', 'Configure os e-mails de alerta do operador em Configurações > E-mail antes de recuperar o PIN.');
        }

        if (! WithdrawalPolicyService::hasManualApprovalPin()) {
            return redirect()->route('plataforma.financeiro.index', ['tab' => 'saques'])
                ->with('error', 'Nenhum PIN de aprovação manual está definido.');
        }

        $newPin = WithdrawalPolicyService::generateResetPin();
        WithdrawalPolicyService::setManualApprovalPin($newPin);

        app(PlatformEmailNotifications::class)->manualApprovalPinReset($newPin, $user);

        PlatformAuditService::log('platform.withdrawal.pin_reset_emailed', [
            'recipient_count' => count($recipients),
        ], $request);

        $masked = array_map(fn (string $email) => $this->maskAdminEmail($email), $recipients);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'saques'])
            ->with('success', 'Novo PIN enviado para: '.implode(', ', $masked).'. Altere-o após o login.');
    }

    private function maskAdminEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        [$local, $domain] = $parts;
        $visible = substr($local, 0, min(2, strlen($local)));

        return $visible.'***@'.$domain;
    }

    public function updatePaymentMethods(Request $request): RedirectResponse
    {
        $rules = [
            'platform_payment_methods_enabled' => ['required', 'array'],
        ];
        foreach (PlatformPaymentMethods::METHOD_KEYS as $key) {
            $rules["platform_payment_methods_enabled.$key"] = ['nullable', 'boolean'];
        }
        $request->validate($rules);

        $out = PlatformPaymentMethods::defaults();
        foreach (PlatformPaymentMethods::METHOD_KEYS as $key) {
            $out[$key] = $request->boolean("platform_payment_methods_enabled.$key", true);
        }

        if (! collect($out)->contains(true)) {
            return redirect()->route('plataforma.financeiro.index', ['tab' => 'metodos'])
                ->with('error', 'Ative pelo menos uma forma de pagamento na plataforma.');
        }

        Setting::set('platform_payment_methods_enabled', $out, null);

        PlatformAuditService::log('platform.financial.payment_methods_updated', ['enabled' => $out], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'metodos'])
            ->with('success', 'Formas de pagamento da plataforma atualizadas.');
    }

    /**
     * Preferência de saque automático (CajuPay, Woovi, OnlyUp ou automático).
     */
    public function updatePayoutGatewayPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preference' => ['required', 'string', 'in:auto,cajupay,woovi,versell,xflow,okto,xpag,onlyup'],
        ]);

        $pref = $validated['preference'];
        Setting::set('platform_payout_gateway', $pref === 'auto' ? null : $pref, null);

        PlatformAuditService::log('platform.financial.payout_gateway_preference', ['preference' => $pref], $request);

        return response()->json([
            'success' => true,
            'message' => 'Preferência de saque automático atualizada.',
            'payout_gateway_preference' => PlatformPayoutGateway::preference(),
            'payout_gateway_active' => PlatformPayoutGateway::activeSlug(),
        ]);
    }

    public function updateSettlement(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_settlement_rules' => ['required', 'array'],
        ]);
        foreach (EffectiveSettlementRules::SETTLEMENT_METHOD_KEYS as $key) {
            $request->validate([
                "merchant_settlement_rules.$key" => ['nullable', 'array'],
                "merchant_settlement_rules.$key.days_to_available" => ['nullable', 'integer', 'min:0', 'max:365'],
                "merchant_settlement_rules.$key.reserve_percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
                "merchant_settlement_rules.$key.reserve_hold_days" => ['nullable', 'integer', 'min:0', 'max:365'],
            ]);
        }

        $out = [];
        foreach (EffectiveSettlementRules::SETTLEMENT_METHOD_KEYS as $key) {
            $block = $validated['merchant_settlement_rules'][$key] ?? [];
            $out[$key] = [
                'days_to_available' => max(0, (int) ($block['days_to_available'] ?? 0)),
                'reserve_percent' => round(min(100, max(0, (float) ($block['reserve_percent'] ?? 0))), 2),
                'reserve_hold_days' => max(0, min(365, (int) ($block['reserve_hold_days'] ?? 0))),
            ];
        }

        Setting::set('merchant_settlement_rules', $out, null);

        PlatformAuditService::log('platform.financial.settlement_updated', ['rules' => $out], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'liquidacao'])
            ->with('success', 'Regras de liquidação atualizadas.');
    }

    public function updateFees(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_fee_rules' => ['required', 'array'],
            'card_installment_rules' => ['nullable', 'array'],
            'card_installment_rules.*' => ['nullable', 'array'],
            'card_installment_rules.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'card_installment_rules.*.fixed' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'card_installment_rules.*.days_to_available' => ['nullable', 'integer', 'min:0', 'max:365'],
            'api_pix_enabled' => ['nullable', 'boolean'],
            'low_ticket_enabled' => ['nullable', 'boolean'],
            'low_ticket_max_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'low_ticket_max_order_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'low_ticket_max_order_amount_enabled' => ['nullable', 'boolean'],
            'low_ticket_fee_rules' => ['nullable', 'array'],
        ]);
        $rules = ['pix', 'api_pix', 'pixgo', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'];
        foreach ($rules as $key) {
            $request->validate([
                "merchant_fee_rules.$key" => ['nullable', 'array'],
                "merchant_fee_rules.$key.percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
                "merchant_fee_rules.$key.fixed" => ['nullable', 'numeric', 'min:0', 'max:999999'],
            ]);
        }

        $out = [];
        foreach ($rules as $key) {
            $block = $validated['merchant_fee_rules'][$key] ?? [];
            $out[$key] = [
                'percent' => PercentDecimal::toFloat(PercentDecimal::normalize($block['percent'] ?? 0)),
                'fixed' => round((float) ($block['fixed'] ?? 0), 2),
            ];
        }

        $existingRaw = Setting::get('merchant_fee_rules', null, null);
        if (is_string($existingRaw)) {
            $existingRaw = json_decode($existingRaw, true);
        }
        $existingInstallments = is_array($existingRaw) ? ($existingRaw['card_installments'] ?? null) : null;

        if ($request->exists('low_ticket_enabled') || $request->exists('low_ticket_fee_rules')) {
            foreach (EffectiveMerchantFees::saleRuleKeys() as $key) {
                $request->validate([
                    "low_ticket_fee_rules.$key" => ['nullable', 'array'],
                    "low_ticket_fee_rules.$key.percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
                    "low_ticket_fee_rules.$key.fixed" => ['nullable', 'numeric', 'min:0', 'max:999999'],
                    "low_ticket_fee_rules.$key.enabled" => ['nullable', 'boolean'],
                ]);
            }

            $lowTicketRaw = [
                'enabled' => (bool) ($validated['low_ticket_enabled'] ?? false),
                'max_amount' => $validated['low_ticket_max_amount'] ?? 0,
                'max_order_amount' => $validated['low_ticket_max_order_amount'] ?? 0,
            ];
            if ($request->exists('low_ticket_max_order_amount_enabled')) {
                $lowTicketRaw['max_order_amount_enabled'] = $request->boolean('low_ticket_max_order_amount_enabled');
            }
            foreach (EffectiveMerchantFees::saleRuleKeys() as $key) {
                $lowTicketRaw[$key] = $validated['low_ticket_fee_rules'][$key] ?? [];
            }
            $out['low_ticket'] = EffectiveMerchantFees::normalizeLowTicket($lowTicketRaw);
        } elseif (is_array($existingRaw) && isset($existingRaw['low_ticket'])) {
            $out['low_ticket'] = EffectiveMerchantFees::normalizeLowTicket(
                is_array($existingRaw['low_ticket']) ? $existingRaw['low_ticket'] : null
            );
        }

        if ($request->exists('card_installment_rules')) {
            $incoming = is_array($validated['card_installment_rules'] ?? null)
                ? $validated['card_installment_rules']
                : [];
            $out['card_installments'] = CardInstallmentEconomics::normalizeRules(
                $incoming,
                $out['card'],
                CardInstallmentEconomics::fallbackDaysFromSettlement()
            );
        } elseif (is_array($existingInstallments) && $existingInstallments !== []) {
            $out['card_installments'] = CardInstallmentEconomics::normalizeRules(
                $existingInstallments,
                ['percent' => 0, 'fixed' => 0],
                0
            );
        }

        Setting::set('merchant_fee_rules', $out, null);
        Setting::set('api_pix_enabled', (bool) ($validated['api_pix_enabled'] ?? ApiPixAccess::globalEnabled()), null);

        PlatformAuditService::log('platform.financial.fees_updated', [
            'rules' => $out,
            'api_pix_enabled' => (bool) ($validated['api_pix_enabled'] ?? ApiPixAccess::globalEnabled()),
        ], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'taxas'])
            ->with('success', 'Taxas da plataforma atualizadas.');
    }

    public function updateInstallments(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_card_installments_enabled' => ['nullable', 'boolean'],
            'platform_card_installments_max' => ['nullable', 'integer', 'min:2', 'max:12'],
            'card_installment_rules' => ['nullable', 'array'],
            'card_installment_rules.*' => ['nullable', 'array'],
            'card_installment_rules.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'card_installment_rules.*.fixed' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'card_installment_rules.*.days_to_available' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $enabled = $request->boolean('platform_card_installments_enabled', false);
        $max = PlatformCardInstallments::normalizePlatformMax(
            (int) ($validated['platform_card_installments_max'] ?? PlatformCardInstallments::DEFAULT_MAX)
        );

        PlatformCardInstallments::setEnabled($enabled);
        PlatformCardInstallments::setMaxAllowed($max);

        $existingRaw = Setting::get('merchant_fee_rules', null, null);
        if (is_string($existingRaw)) {
            $existingRaw = json_decode($existingRaw, true);
        }
        $out = is_array($existingRaw) ? $existingRaw : [];
        $cardFee = is_array($out['card'] ?? null) ? $out['card'] : ['percent' => 0, 'fixed' => 0];

        if ($request->exists('card_installment_rules')) {
            $incoming = is_array($validated['card_installment_rules'] ?? null)
                ? $validated['card_installment_rules']
                : [];
            $out['card_installments'] = CardInstallmentEconomics::normalizeRules(
                $incoming,
                $cardFee,
                CardInstallmentEconomics::fallbackDaysFromSettlement()
            );
            Setting::set('merchant_fee_rules', $out, null);
        }

        PlatformAuditService::log('platform.financial.installments_updated', [
            'enabled' => $enabled,
            'max' => $max,
        ], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'parcelamento'])
            ->with('success', 'Configurações de parcelamento atualizadas.');
    }

    public function updatePixGo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pixgo_enabled' => ['nullable', 'boolean'],
            'pixgo_sidebar_label' => ['nullable', 'string', 'max:32'],
        ]);

        PixGoAccess::setEnabled($request->boolean('pixgo_enabled', false));
        if (array_key_exists('pixgo_sidebar_label', $validated)) {
            PixGoAccess::setSidebarLabel((string) ($validated['pixgo_sidebar_label'] ?? PixGoAccess::DEFAULT_SIDEBAR_LABEL));
        }

        PlatformAuditService::log('platform.financial.pixgo_updated', [
            'pixgo_enabled' => PixGoAccess::globalEnabled(),
            'pixgo_sidebar_label' => PixGoAccess::sidebarLabel(),
        ], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'pixgo'])
            ->with('success', 'Configurações PixGO atualizadas.');
    }

    public function updateChargeLimits(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'api_pix_minimum_charge_brl' => ['required', 'numeric', 'min:0', 'max:999999'],
            'platform_minimum_charge_brl' => ['required', 'numeric', 'min:0', 'max:999999'],
            'platform_minimum_withdrawal_brl' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);

        $apiMin = round((float) $validated['api_pix_minimum_charge_brl'], 2);
        $platformMin = round((float) $validated['platform_minimum_charge_brl'], 2);
        $withdrawalMin = round((float) $validated['platform_minimum_withdrawal_brl'], 2);

        Setting::set(MinimumChargeService::SETTING_API_PIX, (string) $apiMin, null);
        Setting::set(MinimumChargeService::SETTING_PLATFORM, (string) $platformMin, null);
        WithdrawalMinimumService::setPlatformMinimumBrl($withdrawalMin);

        PlatformAuditService::log('platform.financial.charge_limits_updated', [
            'api_pix_minimum_charge_brl' => $apiMin,
            'platform_minimum_charge_brl' => $platformMin,
            'platform_minimum_withdrawal_brl' => $withdrawalMin,
        ], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'limites'])
            ->with('success', 'Limites atualizados.');
    }

    public function approveWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $validated = $request->validate([
            'payout_manual' => ['nullable', 'boolean'],
            'totp_code' => ['nullable', 'string', 'max:16'],
            'manual_approval_pin' => ['nullable', 'string', 'max:'.WithdrawalPolicyService::MANUAL_APPROVAL_PIN_MAX_LENGTH, 'regex:/^\d*$/'],
            'manual_confirm_external' => ['nullable', 'boolean'],
            'payout_acquirer' => ['nullable', 'string', 'max:32'],
        ]);
        $manual = (bool) ($validated['payout_manual'] ?? false);

        if ($manual && ! $request->boolean('manual_confirm_external')) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Confirme que o PIX já foi enviado fora do sistema.');
        }

        $this->validateWithdrawalPayoutStepUp($request, 'plataforma.saques.index');

        $hadExternal = trim((string) ($withdrawal->payout_external_id ?? '')) !== '';
        $manualAcquirer = null;
        if ($manual && ! $hadExternal) {
            $manualAcquirer = $this->validatedManualPayoutAcquirer($validated['payout_acquirer'] ?? null);
            if ($manualAcquirer === null) {
                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Selecione a adquirente usada no PIX ou a opção pagamento por conta externa.');
            }
        }

        if (! $manual && trim((string) ($withdrawal->payout_external_id ?? '')) !== '') {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque já foi enviado ao gateway. Use Reprocessar para nova tentativa ou Cancelar e estornar.');
        }

        $requestedPayoutSlug = trim((string) ($validated['payout_acquirer'] ?? ''));
        if (! $manual && $requestedPayoutSlug !== '' && ! PlatformPayoutGateway::isConnected($requestedPayoutSlug)) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Selecione uma adquirente de saque conectada.');
        }

        $locked = MerchantWithdrawalService::beginPayoutApproval((int) $withdrawal->id);
        if ($locked === null) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não está pendente ou já está em processamento.');
        }

        $withdrawal = $locked;

        if ($manual) {
            $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
            $meta['manual_confirmed_by'] = $request->user()?->id;
            $meta['manual_confirmed_at'] = now()->toIso8601String();
            if ($hadExternal) {
                $meta['manual_confirmed_while_awaiting_gateway'] = true;
            }
            $update = [
                'payout_manual' => true,
                'payout_meta' => $meta,
            ];
            // Sem envio prévio ao gateway: grava a adquirente (ou conta externa) para o comprovante.
            // Se já há external_id, preserva cajupay/etc.
            if (! $hadExternal && is_string($manualAcquirer) && $manualAcquirer !== '') {
                $meta['manual_payout_acquirer'] = $manualAcquirer;
                $update['payout_meta'] = $meta;
                $update['payout_provider'] = $manualAcquirer;
            }
            $withdrawal->update($update);
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', [
                'withdrawal_id' => $withdrawal->id,
                'manual' => true,
                'awaiting_gateway' => $hadExternal,
                'payout_acquirer' => $manualAcquirer,
            ], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', $hadExternal
                    ? 'Saque confirmado como pago (já liquidado no gateway).'
                    : 'Saque marcado como pago (aprovado manualmente, sem API).');
        }

        $slug = $requestedPayoutSlug !== '' ? $requestedPayoutSlug : PlatformPayoutGateway::activeSlug();
        if ($slug === null) {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Nenhum gateway de payout PIX está conectado. Use aprovação manual ou configure Integrações > Gateways.');
        }

        $owner = $this->withdrawalTenantOwner($withdrawal);

        if ($owner === null) {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Titular da conta (infoprodutor) não encontrado.');
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];

        if ($slug === 'cajupay') {
            $result = $this->sendCajuPayWithdrawalPayout($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $this->recordCajuPayWithdrawalFailure($withdrawal, $result);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, $result['error'] ?? 'Falha ao enviar o saque.');

                return redirect()->route('plataforma.saques.index')
                    ->with('error', $result['error'] ?? 'Falha ao enviar o saque.');
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'cajupay',
                'payout_external_id' => $result['external_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => $result['status'] ?? null,
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileCajuPayWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addMinutes(2));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'cajupay' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado via CajuPay. Aguardando confirmação para marcar como pago.');
        }

        if ($slug === 'spacepag') {
            $payout = new SpacepagPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'spacepag',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'Spacepag: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Spacepag: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'spacepag',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileSpacepagWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'spacepag' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à Spacepag. Será marcado como pago após confirmação do PIX (webhook).');
        }

        if ($slug === 'woovi') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new WooviPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'woovi',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'Woovi: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Woovi: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'woovi',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileWooviWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'woovi' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à Woovi. Será marcado como pago após confirmação na API.');
        }

        if ($slug === 'bspay') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new BspayPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'bspay',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'BSPay: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'BSPay: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'bspay',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileBspayWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'bspay' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à BSPay. Será marcado como pago após confirmação do PIX (webhook).');
        }

        if ($slug === 'xflow') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new XflowPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'xflow',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'Xflow: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Xflow: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'xflow',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'pending_approval' => ($result['pending_approval'] ?? false) ? true : null,
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileXflowWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'xflow' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à Xflow. Será marcado como pago após confirmação do PIX (webhook).');
        }

        if ($slug === 'okto') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new OktoPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'okto',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'Okto: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Okto: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'okto',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileOktoWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'okto' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à Okto. Será marcado como pago após confirmação do PIX (webhook).');
        }

        if ($slug === 'xpag') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new XpagPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'xpag',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'XPag: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'XPag: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'xpag',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileXpagWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'xpag' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à XPag. Será marcado como pago após confirmação do PIX (webhook).');
        }

        if ($slug === 'onlyup') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new OnlyUpPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'onlyup',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
                $this->notifyWithdrawalPayoutError($withdrawal, 'OnlyUp: '.($result['error'] ?? 'Falha ao enviar o saque.'));

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'OnlyUp: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'onlyup',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileOnlyUpWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'onlyup' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à OnlyUp. Será marcado como pago após confirmação na API.');
        }

        MerchantWithdrawalService::releasePayoutApproval($withdrawal);

        return redirect()->route('plataforma.saques.index')
            ->with('error', 'Gateway de payout não suportado.');
    }

    /**
     * Consulta o status do payout na CajuPay e confirma/falha o saque local se a API for definitiva.
     */
    public function reconcileCajuPayWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        if ($withdrawal->payout_provider !== 'cajupay') {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Reconciliação automática disponível apenas para saques CajuPay.');
        }

        if (! in_array($withdrawal->status, ['pending', 'processing'], true)) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não está pendente.');
        }

        if (trim((string) ($withdrawal->payout_external_id ?? '')) === '') {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Saque sem ID externo na CajuPay. Use Enviar pagamento ou Reprocessar.');
        }

        $outcome = app(CajuPayWithdrawalReconcileService::class)->reconcile($withdrawal->fresh());

        PlatformAuditService::log('platform.withdrawal.cajupay_reconcile', [
            'withdrawal_id' => $withdrawal->id,
            'result' => $outcome['result'] ?? null,
            'api_status' => $outcome['api_status'] ?? null,
        ], $request);

        if (($outcome['result'] ?? null) === 'paid') {
            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque confirmado como pago via consulta à CajuPay.');
        }

        if (($outcome['result'] ?? null) === 'failed') {
            return redirect()->route('plataforma.saques.index')
                ->with('success', 'CajuPay reportou falha; saldo devolvido ao infoprodutor.');
        }

        return redirect()->route('plataforma.saques.index')
            ->with('error', $outcome['message'] ?? 'CajuPay ainda não confirmou o saque. Tente novamente em instantes ou confirme manualmente se o PIX já liquidou.');
    }

    /**
     * Nova tentativa de envio PIX via CajuPay para saque ainda pendente (ex.: saldo insuficiente antes).
     */
    public function retryCajuPayWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $this->validatePlatformStepUp($request);

        $locked = MerchantWithdrawalService::beginPayoutApproval((int) $withdrawal->id);
        if ($locked === null) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não está pendente ou já está em processamento.');
        }

        $withdrawal = $locked;

        if (PlatformPayoutGateway::activeSlug() !== 'cajupay') {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'O reprocessamento automático só está disponível com CajuPay como gateway de saque ativo.');
        }

        $owner = $this->withdrawalTenantOwner($withdrawal);
        if ($owner === null) {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Titular da conta (infoprodutor) não encontrado.');
        }

        $result = $this->sendCajuPayWithdrawalPayout($withdrawal->fresh(), $owner);

        if (! ($result['ok'] ?? false)) {
            $this->recordCajuPayWithdrawalFailure($withdrawal, $result);
            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());
            $this->notifyWithdrawalPayoutError($withdrawal, $result['error'] ?? 'Falha ao reprocessar o saque.');

            PlatformAuditService::log('platform.withdrawal.cajupay_retry_failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $result['error'] ?? null,
                'cajupay_error_code' => $result['cajupay_error_code'] ?? null,
            ], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('error', $result['error'] ?? 'Falha ao reprocessar o saque.');
        }

        $withdrawal->update([
            'payout_manual' => false,
            'payout_provider' => 'cajupay',
            'payout_external_id' => $result['external_id'] ?? null,
            'payout_meta' => array_filter([
                'api_status' => $result['status'] ?? null,
                'requested_at' => now()->toIso8601String(),
            ]),
        ]);

        ReconcileCajuPayWithdrawalJob::dispatch($withdrawal->fresh()->id)
            ->delay(now()->addMinutes(2));

        MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

        PlatformAuditService::log('platform.withdrawal.cajupay_retry_succeeded', ['withdrawal_id' => $withdrawal->id], $request);

        return redirect()->route('plataforma.saques.index')
            ->with('success', 'Saque reprocessado via CajuPay. Aguardando confirmação para marcar como pago.');
    }

    public function rejectWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'totp_code' => ['nullable', 'string', 'max:16'],
            'manual_approval_pin' => ['nullable', 'string', 'max:'.WithdrawalPolicyService::MANUAL_APPROVAL_PIN_MAX_LENGTH, 'regex:/^\d*$/'],
        ]);

        $this->validatePlatformStepUp($request, redirectRoute: 'plataforma.saques.index');

        if (! in_array($withdrawal->status, ['pending', 'processing'], true)) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não pode ser rejeitado no status atual.');
        }

        MerchantWithdrawalService::reject($withdrawal->fresh(), $validated['admin_note'] ?? null);

        PlatformAuditService::log('platform.withdrawal.rejected', ['withdrawal_id' => $withdrawal->id], $request);

        return redirect()->route('plataforma.saques.index')
            ->with('success', 'Saque cancelado e saldo devolvido ao infoprodutor.');
    }

    private function withdrawalTenantOwner(Withdrawal $withdrawal): ?User
    {
        $tenantId = (int) $withdrawal->tenant_id;
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }

        return $owner;
    }

    /**
     * @return array{ok: true, external_id?: string|null, status?: int|null}|array{ok: false, error: string, cajupay_error_code?: string|null}
     */
    private function sendCajuPayWithdrawalPayout(Withdrawal $withdrawal, User $owner): array
    {
        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::cajuPixKey($settings);
        $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
        $keyOwnerDocument = PayoutUserSettings::cajuPixOwnerDocument($settings);

        if ($pixKey === '' || $pixKeyType === '') {
            return ['ok' => false, 'error' => 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).'];
        }
        if ($keyOwnerDocument === '') {
            return ['ok' => false, 'error' => 'O infoprodutor precisa atualizar o CPF/CNPJ do titular no cadastro da chave PIX em Financeiro.'];
        }

        $payout = new CajuPayPayoutService;
        $apiResult = $payout->sendWithdrawalToPixKey(
            $withdrawal,
            null,
            $pixKey,
            $pixKeyType,
            $keyOwnerDocument
        );

        if ($apiResult['ok'] ?? false) {
            return [
                'ok' => true,
                'external_id' => $apiResult['external_id'] ?? null,
                'status' => $apiResult['status'] ?? null,
            ];
        }

        return [
            'ok' => false,
            'error' => $apiResult['error'] ?? 'Falha ao enviar o saque.',
            'cajupay_error_code' => $apiResult['cajupay_error_code'] ?? null,
        ];
    }

    /**
     * @param  array{ok: false, error: string, cajupay_error_code?: string|null}  $result
     */
    private function recordCajuPayWithdrawalFailure(Withdrawal $withdrawal, array $result): void
    {
        $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta = array_merge($prev, [
            'last_error' => $result['error'] ?? 'Erro desconhecido',
            'last_attempt_at' => now()->toIso8601String(),
        ]);
        if (($result['cajupay_error_code'] ?? null) === 'insufficient_funds') {
            $meta['cajupay_error_code'] = 'insufficient_funds';
        } else {
            unset($meta['cajupay_error_code']);
        }

        $withdrawal->update([
            'payout_provider' => 'cajupay',
            'payout_meta' => $meta,
        ]);
    }

    private function notifyWithdrawalPayoutError(Withdrawal $withdrawal, string $reason): void
    {
        app(PlatformEmailNotifications::class)->withdrawalPayoutError($withdrawal->fresh(), $reason);
    }

    private function validatedManualPayoutAcquirer(mixed $raw): ?string
    {
        $slug = strtolower(trim((string) $raw));
        if ($slug === WithdrawalPixReceiptService::MANUAL_PAYOUT_EXTERNAL) {
            return $slug;
        }
        if ($slug !== '' && GatewayRegistry::isAllowedAcquirer($slug) && GatewayRegistry::get($slug) !== null) {
            return $slug;
        }

        return null;
    }
}
