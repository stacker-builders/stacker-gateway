<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiAuthContext;
use App\Services\Payout\PayoutDestinationValidator;
use App\Services\Payout\PlatformPayoutGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Valida destino PIX para saque via API.
 *
 * Não altera a chave master do infoprodutor (users.payout_settings).
 * O destino deve ser informado em cada POST /withdrawals.
 */
class PayoutDestinationController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::WITHDRAWALS_WRITE)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $request->validate([
            'pix_key' => ['required', 'string', 'max:255'],
            'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,evp,random'],
            'key_owner_document' => ['nullable', 'string', 'max:20'],
        ]);

        $slug = PlatformPayoutGateway::activeSlug();
        $result = PayoutDestinationValidator::validateForUpdate([
            'pix_key' => (string) $request->input('pix_key'),
            'pix_key_type' => (string) $request->input('pix_key_type'),
            'key_owner_document' => $request->input('key_owner_document'),
        ], $slug);

        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                $result['field'] => $result['message'],
            ]);
        }

        $response = [
            'message' => 'Destino PIX válido. Envie pix_key, pix_key_type e key_owner_document (quando exigido) em cada POST /withdrawals. A chave master do infoprodutor não é alterada.',
            'pix_key_type' => $result['pix_key_type'],
            'pix_key_masked' => $this->maskPixKey($result['pix_key']),
            'persisted_to_merchant' => false,
        ];

        if (in_array($slug, ['cajupay', 'versell'], true) && ($result['key_owner_document'] ?? '') !== '') {
            $response['key_owner_document_masked'] = PayoutDestinationValidator::maskDocument($result['key_owner_document']);
        }

        return response()->json($response);
    }

    private function maskPixKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 4) {
            return '****';
        }

        return str_repeat('*', max(0, $len - 4)).substr($key, -4);
    }
}
