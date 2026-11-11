<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Services\PjConversionService;
use App\Services\SellerActivityLogService;
use App\Support\BlockedDocuments;
use App\Support\BrazilianDocuments;
use App\Support\CnpjLookup;
use App\Support\KycRequiredDocuments;
use App\Support\PjConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PjConversionController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        protected PjConversionService $pjConversionService
    ) {}

    public function lookupCnpj(Request $request): JsonResponse
    {
        $subject = $this->subjectOrAbort($request);
        if (! PjConversion::canStart($subject)) {
            return response()->json(['message' => 'Esta conta não pode iniciar a migração para CNPJ.'], 422);
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

        if ($blocked = BlockedDocuments::messageFor($cnpj)) {
            return response()->json([
                'ok' => false,
                'status' => 'blocked',
                'message' => $blocked,
            ], 422);
        }

        if (PjConversion::cnpjTakenByAnotherUser($cnpj, (int) $subject->id)) {
            return response()->json([
                'ok' => false,
                'status' => 'taken',
                'message' => 'Este CNPJ já está cadastrado em outra conta.',
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

        $payload = CnpjLookup::publicWizardPayload($lookup);
        $natureza = is_array($lookup['payload'] ?? null)
            ? trim((string) ($lookup['payload']['natureza_juridica'] ?? ''))
            : '';
        if ($natureza !== '') {
            $user = $subject->newInstance($subject->getAttributes());
            $user->cnpj_lookup = [
                'status' => \App\Services\Cnpj\BrasilApiCnpjClient::STATUS_OK,
                'natureza_juridica' => $natureza,
            ];
            $payload['company_nature_suggestion'] = KycRequiredDocuments::suggestCompanyNatureFromLookup($user);
        }

        return response()->json($payload);
    }

    public function start(Request $request): RedirectResponse
    {
        $subject = $this->subjectOrAbort($request);
        if (! PjConversion::canStart($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Esta conta não pode iniciar a migração para CNPJ.',
            ]);
        }

        $validated = $request->validate([
            'cnpj' => ['required', 'string', 'max:20'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_legal_nature' => ['required', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)],
            'cnpj_suggested_razao_social' => ['nullable', 'string', 'max:255'],
        ]);

        $this->pjConversionService->start(
            $subject,
            $validated['cnpj'],
            $validated['company_name'],
            $validated['company_legal_nature'],
            $validated['cnpj_suggested_razao_social'] ?? null,
        );

        $this->logSellerActivity(SellerActivityLogService::PJ_CONVERSION_STARTED, $subject, [
            'cnpj' => BrazilianDocuments::digits($validated['cnpj']),
        ]);

        return redirect()->route('profile.index')->with('success', 'Informe os documentos da empresa para concluir a migração. Sua conta PF continua operando normalmente.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $subject = $this->subjectOrAbort($request);
        $this->pjConversionService->cancel($subject);

        $this->logSellerActivity(SellerActivityLogService::PJ_CONVERSION_CANCELLED, $subject);

        return redirect()->route('profile.index')->with('success', 'Migração para CNPJ cancelada. Sua conta continua como pessoa física.');
    }

    private function subjectOrAbort(Request $request): \App\Models\User
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSellerPanel()) {
            abort(403);
        }

        return $user->kycSubjectUser();
    }
}
