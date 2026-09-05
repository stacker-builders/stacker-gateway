<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\EffectiveMerchantFees;
use App\Services\EffectiveSettlementRules;
use App\Services\MerchantWithdrawalService;
use App\Services\Payout\PayoutUserSettings;
use App\Services\SellerActivityLogService;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\Withdrawal\WithdrawalMinimumService;
use App\Services\WithdrawalAutoPayoutService;
use App\Services\WithdrawalPixReceiptService;
use App\Support\BrazilianDocumentDigits;
use App\Support\MerchantProfileSnapshot;
use App\Support\HtmlSanitizer;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SellerFinancialController extends Controller
{
    public function __construct(
        protected WithdrawalPixReceiptService $receiptService,
    ) {}

    private static function parseBrlAmountToFloat(mixed $raw): float
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '') {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
        }

        // Remove espaços e símbolo de moeda (mantém apenas dígitos e separadores comuns)
        $s = preg_replace('/[^\d.,-]/u', '', $s) ?? '';
        $s = trim($s);
        if ($s === '' || $s === '-' || $s === ',' || $s === '.') {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
        }

        // Rejeita valores negativos
        if (str_starts_with($s, '-')) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor positivo.']);
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma) {
            // pt-BR: '.' milhares, ',' decimal
            $parts = explode(',', $s);
            if (count($parts) !== 2) {
                throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
            }
            [$int, $dec] = $parts;
            $int = str_replace('.', '', $int);
            if ($dec === '' || strlen($dec) > 2) {
                throw ValidationException::withMessages(['amount' => 'Use no máximo 2 casas decimais.']);
            }
            $norm = $int.'.'.$dec;
        } else {
            // padrão: '.' decimal (sem separador de milhar)
            if ($hasDot) {
                $parts = explode('.', $s);
                if (count($parts) !== 2) {
                    throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
                }
                if ($parts[1] === '' || strlen($parts[1]) > 2) {
                    throw ValidationException::withMessages(['amount' => 'Use no máximo 2 casas decimais.']);
                }
            }
            $norm = $s;
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $norm)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
        }

        $amount = (float) $norm;
        if (! is_finite($amount) || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
        }

        return $amount;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenantId = (int) ($user->tenant_id ?? $user->id);

        $wallet = null;
        if (Schema::hasTable('tenant_wallets')) {
            $wallet = TenantWallet::query()->firstOrCreate(
                ['tenant_id' => $tenantId],
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

        $withdrawals = [];
        if (Schema::hasTable('withdrawals')) {
            $withdrawals = Withdrawal::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(80)
                ->get()
                ->map(fn ($w) => [
                    'id' => $w->id,
                    'amount' => (float) $w->amount,
                    'fee_amount' => (float) ($w->fee_amount ?? 0),
                    'net_amount' => (float) ($w->net_amount ?? 0),
                    'bucket' => $w->bucket ?? 'pix',
                    'status' => $w->status,
                    'notes' => $w->notes,
                    'created_at' => $w->created_at?->toIso8601String(),
                    'can_download_receipt' => $this->receiptService->isAvailable($w),
                ])
                ->all();
        }

        $feesPreview = EffectiveMerchantFees::forTenant($tenantId);

        $reservePendingTotal = 0.0;
        if (Schema::hasTable('wallet_transactions')) {
            $reservePendingTotal = round((float) WalletTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
                ->get()
                ->filter(function (WalletTransaction $t) {
                    $m = is_array($t->meta) ? $t->meta : [];

                    return ($m['portion'] ?? '') === 'reserve' && empty($m['released_at'] ?? null);
                })
                ->sum(fn (WalletTransaction $t) => (float) $t->amount_net), 2);
        }

        $payoutGateway = PlatformPayoutGateway::activeSlug();
        $subject = $user->kycSubjectUser();
        // Versell exige chave + tipo + CPF/CNPJ do titular (mesmo formulário da CajuPay).
        // BSPay/OnlyUp só precisam da chave (como Woovi).
        $payoutPixSetup = match ($payoutGateway) {
            'cajupay', 'versell' => 'label_and_key',
            'spacepag' => 'key_and_receiver',
            'woovi', 'bspay', 'onlyup' => 'pix_key_only',
            default => null,
        };
        $cajuPixOwnerDocumentHint = '';
        if (in_array($payoutGateway, ['cajupay', 'versell'], true)) {
            $cajuPixOwnerDocumentHint = BrazilianDocumentDigits::onlyDigits((string) ($subject->document ?? ''));
        }

        $pendingReceiveByDate = $this->pendingReceiveByDate($tenantId);

        $walletPayload = null;
        if ($wallet !== null) {
            $pp = (float) ($wallet->pending_pix ?? 0);
            $pc = (float) ($wallet->pending_card ?? 0);
            $pb = (float) ($wallet->pending_boleto ?? 0);
            $walletPayload = [
                'available_pix' => (float) ($wallet->available_pix ?? 0),
                'available_card' => (float) ($wallet->available_card ?? 0),
                'available_boleto' => (float) ($wallet->available_boleto ?? 0),
                'pending_pix' => $pp,
                'pending_card' => $pc,
                'pending_boleto' => $pb,
                'pending_total' => round($pp + $pc + $pb, 2),
                'reserve_pending_total' => $reservePendingTotal,
                'available_total' => round(
                    (float) ($wallet->available_pix ?? 0)
                    + (float) ($wallet->available_card ?? 0)
                    + (float) ($wallet->available_boleto ?? 0),
                    2
                ),
            ];
        }

        $kycFinanceLocked = Schema::hasColumn('users', 'kyc_status')
            && ! $subject->isMerchantOperationallyApproved();

        $requiredWithdrawalNet = WithdrawalMinimumService::effectiveRequiredMinNet();
        $withdrawalMinimumGross = $requiredWithdrawalNet > 0
            ? EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $requiredWithdrawalNet)
            : null;

        return Inertia::render('Financeiro/Index', [
            'wallet' => $walletPayload,
            'pending_receive_by_date' => $pendingReceiveByDate,
            'withdrawals' => $withdrawals,
            'seller_profile' => [
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
                'document' => $user->document !== null && $user->document !== '' ? (string) $user->document : null,
            ],
            'kyc_status' => Schema::hasColumn('users', 'kyc_status') ? ($subject->kyc_status ?? User::KYC_NOT_SUBMITTED) : null,
            'kyc_person_type' => Schema::hasColumn('users', 'person_type') ? ($subject->person_type ?? 'pf') : 'pf',
            'kyc_rejection_reason' => Schema::hasColumn('users', 'kyc_rejection_reason')
                ? ($subject->kyc_rejection_reason ?? null)
                : null,
            'kyc_identity_document_type' => Schema::hasColumn('users', 'identity_document_type')
                ? ($subject->identity_document_type ?? null)
                : null,
            'kyc_company_legal_nature' => Schema::hasColumn('users', 'company_legal_nature')
                ? ($subject->company_legal_nature ?? null)
                : null,
            'kyc_company_nature_suggestion' => (($subject->person_type ?? '') === 'pj' || \App\Support\PjConversion::isCollectingOrPending($subject))
                ? \App\Support\KycRequiredDocuments::suggestCompanyNatureFromLookup($subject)
                : null,
            'kyc_uploaded_kinds' => Schema::hasTable('kyc_documents')
                ? \App\Models\KycDocument::query()
                    ->where('user_id', $subject->id)
                    ->active()
                    ->pluck('kind')
                    ->values()
                    ->all()
                : [],
            'kyc_requirements' => \App\Support\KycRequirementSettings::forSellerForm(),
            'kyc_finance_locked' => $kycFinanceLocked,
            'pj_conversion' => \App\Support\PjConversion::forFrontend($subject),
            'registration_snapshot' => MerchantProfileSnapshot::forUser($subject, maskDocuments: false),
            'payout_settings' => is_array($user->payout_settings) ? $user->payout_settings : [],
            /** @var 'label_and_key'|'key_and_receiver'|null Fluxo de cadastro PIX sem expor adquirente ao vendedor */
            'payout_pix_setup' => $payoutPixSetup,
            'caju_pix_owner_document_hint' => $cajuPixOwnerDocumentHint,
            'fee_preview' => $feesPreview,
            'withdrawal_minimum_net_brl' => $requiredWithdrawalNet,
            'withdrawal_minimum_gross_brl' => $withdrawalMinimumGross,
            'settlement_preview' => [
                'pix' => EffectiveSettlementRules::forTenantMethod($tenantId, 'pix'),
                'open_finance' => EffectiveSettlementRules::forTenantMethod($tenantId, 'open_finance'),
                'card' => EffectiveSettlementRules::forTenantMethod($tenantId, 'card'),
                'apple_pay' => EffectiveSettlementRules::forTenantMethod($tenantId, 'apple_pay'),
                'google_pay' => EffectiveSettlementRules::forTenantMethod($tenantId, 'google_pay'),
                'boleto' => EffectiveSettlementRules::forTenantMethod($tenantId, 'boleto'),
            ],
        ]);
    }

    public function storePayoutPixKey(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->isInfoprodutor()) {
            abort(403);
        }

        if (Schema::hasColumn('users', 'kyc_status') && ! $user->kycSubjectUser()->isMerchantOperationallyApproved()) {
            return redirect()->route('financeiro.seller.index')
                ->with('error', 'Conclua a verificação de identidade (KYC) para salvar dados de recebimento.');
        }

        $slug = PlatformPayoutGateway::activeSlug();
        if ($slug === null) {
            return redirect()->route('financeiro.seller.index')
                ->with('error', 'A plataforma ainda não configurou o recebimento automático de saques por PIX.');
        }

        // CajuPay e Versell: chave + tipo + documento do titular (Cash Out dict).
        if (in_array($slug, ['cajupay', 'versell'], true)) {
            $validated = $request->validate([
                'label' => ['required', 'string', 'max:120'],
                'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,evp'],
                'pix_key' => ['required', 'string', 'max:120'],
                // Opcional quando a chave já é CPF/CNPJ (o backend deriva o documento da própria chave).
                'key_owner_document' => ['nullable', 'string', 'max:20'],
            ]);

            $pixKeyType = $validated['pix_key_type'];
            $pixKeyTrim = trim($validated['pix_key']);

            if (in_array($pixKeyType, ['cpf', 'cnpj'], true)) {
                $pixKeyTrim = BrazilianDocumentDigits::onlyDigits($pixKeyTrim);
                $ownerDoc = $pixKeyTrim;
                $expectedLen = $pixKeyType === 'cnpj' ? 14 : 11;
                if (strlen($pixKeyTrim) !== $expectedLen) {
                    return redirect()->route('financeiro.seller.index')
                        ->withErrors([
                            'pix_key' => $pixKeyType === 'cnpj'
                                ? 'Informe um CNPJ válido (14 dígitos) como chave PIX.'
                                : 'Informe um CPF válido (11 dígitos) como chave PIX.',
                        ])
                        ->onlyInput('pix_key');
                }
            } else {
                $ownerDoc = BrazilianDocumentDigits::onlyDigits($validated['key_owner_document'] ?? '');
                if (! BrazilianDocumentDigits::isValidCpfOrCnpjLength($ownerDoc)) {
                    return redirect()->route('financeiro.seller.index')
                        ->withErrors(['key_owner_document' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido do titular da chave PIX.'])
                        ->onlyInput('key_owner_document');
                }
            }

            if ($pixKeyType === 'phone') {
                $pixKeyTrim = BrazilianDocumentDigits::onlyDigits($pixKeyTrim);
            }

            $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
            // Cadastro local: a validação de titularidade é feita no momento do saque (API do adquirente).
            $settings['cajupay_pix_key_id'] = null;
            $settings['cajupay_pix_key'] = $pixKeyTrim;
            $settings['payout_pix_key'] = $pixKeyTrim;
            $settings['cajupay_pix_key_type'] = $pixKeyType;
            $settings['payout_pix_key_type'] = $pixKeyType;
            $settings['cajupay_pix_label'] = $validated['label'];
            $settings['payout_pix_label'] = $validated['label'];
            $settings['cajupay_pix_key_owner_document'] = $ownerDoc;
            $settings['payout_pix_key_owner_document'] = $ownerDoc;
            $user->payout_settings = $settings;
            $user->save();

            $this->logPayoutSettingsUpdated($user, $settings);

            return redirect()->route('financeiro.seller.index')->with('success', 'Dados para recebimento de saques atualizados.');
        }

        if ($slug === 'spacepag') {
            $validated = $request->validate([
                'pix_key' => ['required', 'string', 'max:120'],
                'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,evp'],
                'receiver_name' => ['required', 'string', 'max:120'],
                'receiver_document' => ['required', 'string', 'max:20'],
                'receiver_email' => ['required', 'email', 'max:255'],
            ]);

            $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
            $pkt = $validated['pix_key_type'];
            $pk = trim($validated['pix_key']);
            if (in_array($pkt, ['cpf', 'cnpj', 'phone'], true)) {
                $pk = BrazilianDocumentDigits::onlyDigits($pk);
            }
            $receiverDoc = BrazilianDocumentDigits::onlyDigits($validated['receiver_document']);
            $settings['payout_pix_key'] = $pk;
            $settings['payout_pix_key_type'] = $pkt;
            $settings['spacepag_pix_key'] = $pk;
            $settings['spacepag_pix_key_type'] = $pkt;
            $settings['receiver_name'] = trim($validated['receiver_name']);
            $settings['receiver_document'] = $receiverDoc !== '' ? $receiverDoc : trim($validated['receiver_document']);
            $settings['receiver_email'] = trim($validated['receiver_email']);
            $user->payout_settings = $settings;
            $user->save();

            $this->logPayoutSettingsUpdated($user, $settings);

            return redirect()->route('financeiro.seller.index')->with('success', 'Dados para recebimento de saques salvos.');
        }

        if (in_array($slug, ['woovi', 'bspay', 'onlyup'], true)) {
            $validated = $request->validate([
                'pix_key' => ['required', 'string', 'max:120'],
                'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,evp'],
            ]);

            $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
            $pkt = $validated['pix_key_type'];
            $pk = trim($validated['pix_key']);
            if (in_array($pkt, ['cpf', 'cnpj', 'phone'], true)) {
                $pk = BrazilianDocumentDigits::onlyDigits($pk);
            }
            $settings['payout_pix_key'] = $pk;
            $settings['payout_pix_key_type'] = $pkt;
            if ($slug === 'woovi') {
                $settings['woovi_pix_key'] = $pk;
                $settings['woovi_pix_key_type'] = $pkt;
            }
            $user->payout_settings = $settings;
            $user->save();

            $this->logPayoutSettingsUpdated($user, $settings);

            return redirect()->route('financeiro.seller.index')->with('success', 'Chave PIX para saques salva.');
        }

        return redirect()->route('financeiro.seller.index')
            ->with('error', 'Gateway de payout não suportado.');
    }

    public function storeWithdrawal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Pode vir como number (frontend) ou string (pt-BR / padrão). Parsing valida casas/negativo.
            'amount' => ['required'],
            'bucket' => ['required', 'string', 'in:pix,card,boleto'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        // Rejeita inputs maliciosos/absurdos antes do parse (strings enormes).
        if (is_string($validated['amount']) && mb_strlen($validated['amount']) > 32) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido.']);
        }

        $validated['amount'] = self::parseBrlAmountToFloat($validated['amount']);
        if ($validated['amount'] > 99999999) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor menor.']);
        }
        if (array_key_exists('notes', $validated)) {
            $validated['notes'] = HtmlSanitizer::plainTextMultiline($validated['notes'], 2000) ?: null;
        }

        $user = $request->user();
        if (Schema::hasColumn('users', 'kyc_status') && ! $user->kycSubjectUser()->isMerchantOperationallyApproved()) {
            return redirect()->route('financeiro.seller.index')
                ->with('error', 'Conclua a verificação de identidade (KYC) e aguarde aprovação da plataforma para solicitar saques.');
        }

        if (! \App\Services\Withdrawal\WithdrawalPolicyService::allowsRequestAt()) {
            return redirect()->route('financeiro.seller.index')
                ->with('error', \App\Services\Withdrawal\WithdrawalPolicyService::requestBlockedMessage());
        }

        $slug = PlatformPayoutGateway::activeSlug();
        if (in_array($slug, ['cajupay', 'versell'], true)) {
            $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
            $pixKey = PayoutUserSettings::cajuPixKey($settings);
            $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
            if ($pixKey === '' || $pixKeyType === '') {
                throw ValidationException::withMessages([
                    'amount' => 'Cadastre os dados de PIX para recebimento (seção acima) antes de solicitar o saque.',
                ]);
            }
            $payoutDoc = PayoutUserSettings::cajuPixOwnerDocument($settings);
            if ($payoutDoc === '') {
                throw ValidationException::withMessages([
                    'amount' => 'Atualize os dados de recebimento PIX (Financeiro): informe o CPF ou CNPJ do titular no cadastro da chave e salve novamente.',
                ]);
            }
        }
        if ($slug === 'spacepag') {
            $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
            $ok = PayoutUserSettings::pixKey($settings) !== ''
                && PayoutUserSettings::pixKeyType($settings) !== ''
                && trim((string) ($settings['receiver_name'] ?? '')) !== ''
                && trim((string) ($settings['receiver_document'] ?? '')) !== ''
                && trim((string) ($settings['receiver_email'] ?? '')) !== '';
            if (! $ok) {
                throw ValidationException::withMessages([
                    'amount' => 'Preencha os dados de PIX e recebedor (seção acima) antes de solicitar o saque.',
                ]);
            }
        }
        if (in_array($slug, ['woovi', 'bspay', 'onlyup'], true)) {
            $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
            if (PayoutUserSettings::pixKey($settings) === '' || PayoutUserSettings::pixKeyType($settings) === '') {
                throw ValidationException::withMessages([
                    'amount' => 'Cadastre a chave PIX de destino (seção acima) antes de solicitar o saque.',
                ]);
            }
        }

        $withdrawal = MerchantWithdrawalService::requestWithdrawal(
            $request->user(),
            (float) $validated['amount'],
            $validated['bucket'],
            $validated['notes'] ?? null
        );

        if (PlatformPayoutGateway::isEnabled() && \App\Services\Withdrawal\WithdrawalPolicyService::autoWithdrawalEnabled()) {
            $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($withdrawal);

            if (($auto['ok'] ?? false) === true) {
                if (($auto['pending'] ?? false) === true) {
                    return redirect()->route('financeiro.seller.index')
                        ->with('success', 'Seu saque está sendo processado.');
                }

                return redirect()->route('financeiro.seller.index')
                    ->with('success', 'Saque enviado via PIX e marcado como concluído.');
            }

            if (($auto['skipped'] ?? false) === true) {
                $msg = ($auto['reason'] ?? '') === 'cajupay_insufficient_funds'
                    ? 'Solicitação de saque registrada e aguardando processamento pela plataforma.'
                    : 'Solicitação de saque registrada. Complete o cadastro de dados de recebimento PIX para envio automático.';

                return redirect()->route('financeiro.seller.index')->with('success', $msg);
            }

            return redirect()->route('financeiro.seller.index')
                ->with('error', 'Saque registrado, mas o envio automático falhou: '.($auto['error'] ?? 'tente novamente ou contate o suporte.'));
        }

        return redirect()->route('financeiro.seller.index')
            ->with('success', 'Solicitação de saque registrada. Aguarde a análise da plataforma.');
    }

    /**
     * Valores em liquidação agrupados pela data prevista de liberação (clears_at).
     *
     * @return list<array{date: ?string, amount: float, count: int}>
     */
    private function pendingReceiveByDate(int $tenantId): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return [];
        }

        $byDate = [];

        WalletTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->orderBy('id')
            ->get()
            ->each(function (WalletTransaction $tx) use (&$byDate) {
                $meta = is_array($tx->meta) ? $tx->meta : [];
                if (! empty($meta['released_at'])) {
                    return;
                }

                $dateKey = 'unknown';
                $clearsAt = $meta['clears_at'] ?? null;
                if (is_string($clearsAt) && $clearsAt !== '') {
                    try {
                        $dateKey = Carbon::parse($clearsAt)->format('Y-m-d');
                    } catch (\Throwable) {
                        $dateKey = 'unknown';
                    }
                }

                if (! isset($byDate[$dateKey])) {
                    $byDate[$dateKey] = [
                        'date' => $dateKey === 'unknown' ? null : $dateKey,
                        'amount' => 0.0,
                        'count' => 0,
                    ];
                }

                $byDate[$dateKey]['amount'] += (float) $tx->amount_net;
                $byDate[$dateKey]['count']++;
            });

        $rows = array_values($byDate);
        usort($rows, function (array $a, array $b) {
            if ($a['date'] === null) {
                return 1;
            }
            if ($b['date'] === null) {
                return -1;
            }

            return strcmp($a['date'], $b['date']);
        });

        return array_map(function (array $row) {
            $row['amount'] = round($row['amount'], 2);

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function logPayoutSettingsUpdated(User $user, array $settings): void
    {
        $pixKey = PayoutUserSettings::pixKey($settings);
        if ($pixKey === '') {
            $pixKey = PayoutUserSettings::cajuPixKey($settings);
        }
        $pixKeyType = PayoutUserSettings::pixKeyType($settings);
        if ($pixKeyType === '') {
            $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
        }

        SellerActivityLogService::record(
            actor: $user,
            action: SellerActivityLogService::PAYOUT_SETTINGS_UPDATED,
            targetType: User::class,
            targetId: $user->id,
            metadata: array_filter([
                'pix_key_type' => $pixKeyType !== '' ? $pixKeyType : null,
                'pix_key_masked' => SellerActivityLogService::maskValue($pixKey),
                'label' => PayoutUserSettings::pixLabel($settings) ?: null,
                'receiver_name' => isset($settings['receiver_name']) ? trim((string) $settings['receiver_name']) : null,
                'receiver_document_masked' => SellerActivityLogService::maskValue(
                    isset($settings['receiver_document']) ? (string) $settings['receiver_document'] : null
                ),
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }
}
