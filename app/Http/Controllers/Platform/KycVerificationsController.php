<?php

namespace App\Http\Controllers\Platform;

use App\Events\KycApproved;
use App\Events\KycRejected;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\PjConversionService;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use App\Services\PlatformEmailNotifications;
use App\Support\BlockedDocuments;
use App\Support\CnpjLookup;
use App\Support\KycRequiredDocuments;
use App\Support\KycUpload;
use App\Support\MerchantProfileSnapshot;
use App\Support\PjConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycVerificationsController extends Controller
{
    use RequiresPlatformStepUp;
    public function index(Request $request): Response
    {
        $filter = (string) $request->query('status', 'pending_review');

        $q = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('tenant_id');

        if ($filter === 'pending_review') {
            $q->where('kyc_status', User::KYC_PENDING_REVIEW);
        } elseif ($filter === 'rejected') {
            $q->where('kyc_status', User::KYC_REJECTED);
        } elseif ($filter === 'blocked') {
            $q->where('kyc_status', User::KYC_BLOCKED);
        } elseif ($filter === 'not_submitted') {
            $q->where('kyc_status', User::KYC_NOT_SUBMITTED);
        } elseif ($filter === 'needs_document_review' && Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $q->where('kyc_needs_document_review', true);
        }
        // 'all' = sem filtro extra

        $paginator = $q->orderByDesc('updated_at')->paginate(25)->withQueryString();
        $paginator->setCollection(
            $paginator->getCollection()->map(function (User $u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'person_type' => $u->person_type,
                    'kyc_status' => $u->kyc_status,
                    'pj_conversion_status' => PjConversion::status($u),
                    'updated_at' => $u->updated_at?->toIso8601String(),
                ];
            })
        );

        return Inertia::render('Platform/Kyc/Index', [
            'users' => $paginator,
            'filter' => $filter,
        ]);
    }

    public function show(User $user): Response
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);

        $profile = MerchantProfileSnapshot::forUser($user);

        $allDocuments = $user->kycDocuments()->orderByDesc('created_at')->get();
        $mapDoc = function (KycDocument $d) {
            $base = route('plataforma.kyc.document', ['document' => $d]);

            return [
                'id' => $d->id,
                'public_token' => $d->public_token,
                'kind' => $d->kind,
                'mime' => $d->original_mime,
                'size_bytes' => $d->size_bytes,
                'superseded_at' => $d->superseded_at?->toIso8601String(),
                'is_active' => $d->superseded_at === null,
                'created_at' => $d->created_at?->toIso8601String(),
                'view_url' => $base,
                'download_url' => $base.'?download=1',
            ];
        };

        $documents = $allDocuments->map($mapDoc)->values();

        return Inertia::render('Platform/Kyc/Show', [
            'merchant' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $profile['phone'] ?? null,
                'whatsapp' => $profile['whatsapp'] ?? null,
                'whatsapp_url' => $profile['whatsapp_url'] ?? null,
                'person_type' => $user->person_type,
                'document' => $user->document,
                'company_name' => $user->company_name,
                'legal_representative_cpf' => $user->legal_representative_cpf,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'address_zip' => $user->address_zip,
                'address_street' => $user->address_street,
                'address_number' => $user->address_number,
                'address_complement' => $user->address_complement,
                'address_neighborhood' => $user->address_neighborhood,
                'address_city' => $user->address_city,
                'address_state' => $user->address_state,
                'monthly_revenue_range' => $user->monthly_revenue_range,
                'kyc_status' => $user->kyc_status,
                'kyc_rejection_reason' => $user->kyc_rejection_reason,
                'kyc_reviewed_at' => $user->kyc_reviewed_at?->toIso8601String(),
                'identity_document_type' => $user->identity_document_type ?? null,
                'company_legal_nature' => $user->company_legal_nature ?? null,
                'kyc_requirements_version' => (int) ($user->kyc_requirements_version ?? 1),
                'pj_conversion' => PjConversion::forFrontend($user),
            ],
            'cnpj_lookup' => CnpjLookup::forKycAdmin($user),
            'documents' => $documents,
            'platform_totp_enabled' => PlatformTotpService::isEnabledFor(request()->user()),
        ]);
    }

    public function approve(Request $request, User $user, PlatformEmailNotifications $platformEmailNotifications): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);
        abort_unless($user->kyc_status === User::KYC_PENDING_REVIEW, 422);

        $this->validatePlatformStepUp($request, redirectRoute: 'plataforma.kyc.show');

        if (! KycRequiredDocuments::hasAllRequired($user)) {
            $missing = implode(', ', KycRequiredDocuments::missingLabelsForUser($user));
            throw ValidationException::withMessages([
                'kyc' => 'Documentos obrigatórios ausentes: '.$missing.'.',
            ]);
        }

        $attrs = [
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'kyc_rejection_reason' => null,
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => $request->user()?->id,
        ];
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = false;
        }

        $user->forceFill($attrs)->save();

        PlatformAuditService::log('platform.kyc.approved', ['merchant_id' => $user->id], $request);

        $platformEmailNotifications->kycApproved($user->fresh());
        KycApproved::dispatch($user->fresh());

        return redirect()->route('plataforma.kyc.show', ['user' => $user->id])->with('success', 'Verificação aprovada.');
    }

    public function reject(Request $request, User $user, PlatformEmailNotifications $platformEmailNotifications): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);
        abort_unless($user->kyc_status === User::KYC_PENDING_REVIEW, 422);

        $this->validatePlatformStepUp($request, redirectRoute: 'plataforma.kyc.show');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $user->forceFill([
            'kyc_status' => User::KYC_REJECTED,
            'kyc_rejection_reason' => $validated['reason'],
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => $request->user()?->id,
        ])->save();

        PlatformAuditService::log('platform.kyc.rejected', ['merchant_id' => $user->id, 'reason' => $validated['reason']], $request);

        $platformEmailNotifications->kycRejected($user->fresh(), $validated['reason']);
        KycRejected::dispatch($user->fresh(), $validated['reason']);

        return redirect()->route('plataforma.kyc.show', ['user' => $user->id])->with('success', 'Verificação rejeitada. O infoprodutor pode reenviar documentos.');
    }

    public function rejectAndBlock(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);
        abort_unless($user->kyc_status === User::KYC_PENDING_REVIEW, 422);

        $this->validatePlatformStepUp($request, redirectRoute: 'plataforma.kyc.show');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $blockedDocuments = [];
        DB::transaction(function () use ($request, $user, $validated, &$blockedDocuments) {
            $blockedDocuments = BlockedDocuments::blockMerchant($user, $validated['reason'], $request->user()?->id);

            $attrs = [
                'kyc_status' => User::KYC_BLOCKED,
                'account_status' => 'blocked',
                'kyc_rejection_reason' => $validated['reason'],
                'kyc_reviewed_at' => now(),
                'kyc_reviewed_by' => $request->user()?->id,
            ];
            if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
                $attrs['kyc_needs_document_review'] = false;
            }

            $user->forceFill($attrs)->save();
        });

        PlatformAuditService::log('platform.kyc.rejected_and_blocked', [
            'merchant_id' => $user->id,
            'reason' => $validated['reason'],
            'documents' => $blockedDocuments,
        ], $request);

        return redirect()
            ->route('plataforma.kyc.show', ['user' => $user->id])
            ->with('success', 'Verificação recusada. Os documentos desta conta foram bloqueados e não poderão ser usados em um novo cadastro.');
    }

    public function approvePjConversion(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);
        abort_unless(PjConversion::isPendingReview($user), 422);

        $this->validatePlatformStepUp($request, redirectRoute: 'plataforma.kyc.show');

        $cpfBefore = (string) $user->document;
        $cnpj = PjConversion::cnpj($user);

        app(PjConversionService::class)->approve($user->fresh());

        PlatformAuditService::log('platform.kyc.pj_conversion_approved', [
            'merchant_id' => $user->id,
            'previous_document' => $cpfBefore,
            'cnpj' => $cnpj,
        ], $request);

        return redirect()->route('plataforma.kyc.show', ['user' => $user->id])->with('success', 'Migração para CNPJ aprovada. O CPF anterior ficou como responsável legal.');
    }

    public function rejectPjConversion(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);
        abort_unless(PjConversion::isPendingReview($user), 422);

        $this->validatePlatformStepUp($request, redirectRoute: 'plataforma.kyc.show');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        app(PjConversionService::class)->reject($user->fresh(), $validated['reason']);

        PlatformAuditService::log('platform.kyc.pj_conversion_rejected', [
            'merchant_id' => $user->id,
            'reason' => $validated['reason'],
        ], $request);

        return redirect()->route('plataforma.kyc.show', ['user' => $user->id])->with('success', 'Migração para CNPJ rejeitada. A conta permanece pessoa física e operacional.');
    }

    public function refreshCnpj(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_INFOPRODUTOR, 404);
        $cnpj = preg_replace('/\D/', '', (string) $user->document) ?? '';
        if (strlen($cnpj) !== 14) {
            $cnpj = PjConversion::cnpj($user) ?? '';
        }
        abort_unless($user->person_type === 'pj' || PjConversion::hasCnpj($user), 422);

        if (strlen($cnpj) !== 14) {
            return redirect()
                ->route('plataforma.kyc.show', ['user' => $user->id])
                ->with('error', 'CNPJ do cadastro inválido para consulta.');
        }

        try {
            $snapshot = CnpjLookup::persistForUser(
                $user->fresh(),
                $cnpj,
                (string) (PjConversion::companyName($user) ?? $user->company_name ?? ''),
                null,
                true,
            );
        } catch (\Throwable $e) {
            PlatformAuditService::log('platform.kyc.cnpj_refresh_failed', [
                'merchant_id' => $user->id,
                'error' => $e->getMessage(),
            ], $request);

            return redirect()
                ->route('plataforma.kyc.show', ['user' => $user->id])
                ->with('error', 'Não foi possível consultar o CNPJ agora. Tente de novo em instantes.');
        }

        $refreshOk = ($snapshot['status'] ?? '') === \App\Services\Cnpj\BrasilApiCnpjClient::STATUS_OK
            && filled($snapshot['razao_social'] ?? null)
            && ($snapshot['has_official_data'] ?? true) !== false
            && empty($snapshot['last_error']);

        PlatformAuditService::log('platform.kyc.cnpj_refreshed', [
            'merchant_id' => $user->id,
            'status' => $snapshot['status'] ?? null,
            'ok' => $refreshOk,
        ], $request);

        if ($refreshOk) {
            return redirect()
                ->route('plataforma.kyc.show', ['user' => $user->id])
                ->with('success', 'Consulta CNPJ atualizada. Compare os dados da Receita com o cadastro.');
        }

        return redirect()
            ->route('plataforma.kyc.show', ['user' => $user->id])
            ->with('error', 'A consulta via API não foi bem-sucedida. Os dados anteriores, se houver, foram mantidos. Atenção na análise.');
    }

    public function downloadDocument(Request $request, KycDocument $document): StreamedResponse|\Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->canAccessPlatformPanel(), 403);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->disk_path), 404);

        $mime = $document->original_mime ?? 'application/octet-stream';
        $ext = KycUpload::extensionForMime($mime);
        if ($ext === 'bin') {
            $ext = pathinfo($document->disk_path, PATHINFO_EXTENSION) ?: 'bin';
        }

        $filename = 'kyc-document.'.$ext;
        $forceDownload = $request->boolean('download');

        // Inline = abrir no navegador / modal; attachment = baixar arquivo.
        $disposition = $forceDownload ? 'attachment' : 'inline';

        return $disk->response($document->disk_path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
