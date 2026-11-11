<?php

namespace App\Support;

use App\Models\BlockedDocument;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * CPF/CNPJ impedidos de abrir nova conta depois de "Recusar e bloquear" no KYC.
 */
final class BlockedDocuments
{
    public static function messageFor(?string $raw): ?string
    {
        $digits = BrazilianDocuments::digits((string) $raw);
        if ($digits === '' || ! Schema::hasTable('blocked_documents')) {
            return null;
        }

        $row = BlockedDocument::query()->where('document', $digits)->first();
        if ($row === null) {
            return null;
        }

        $label = ($row->kind === BlockedDocument::KIND_CNPJ || strlen($digits) === 14) ? 'CNPJ' : 'CPF';

        return "Este {$label} já existe na plataforma e está bloqueado.";
    }

    /**
     * Grava o documento da conta e, em PJ, o CPF do responsável (e o CNPJ de migração, se houver).
     *
     * @return list<string>
     */
    public static function blockMerchant(User $user, string $reason, ?int $blockedBy): array
    {
        if (! Schema::hasTable('blocked_documents')) {
            throw ValidationException::withMessages([
                'reason' => 'O bloqueio de documentos ainda não está disponível. Atualize o banco de dados.',
            ]);
        }

        $pairs = [];
        $document = BrazilianDocuments::digits((string) $user->document);
        $documentKind = self::kindFor($document);
        if ($documentKind !== null) {
            $pairs[$document] = $documentKind;
        }

        $representative = BrazilianDocuments::digits((string) ($user->legal_representative_cpf ?? ''));
        if (self::kindFor($representative) === BlockedDocument::KIND_CPF) {
            $pairs[$representative] = BlockedDocument::KIND_CPF;
        }

        $conversionCnpj = PjConversion::cnpj($user);
        if (is_string($conversionCnpj) && self::kindFor($conversionCnpj) === BlockedDocument::KIND_CNPJ) {
            $pairs[$conversionCnpj] = BlockedDocument::KIND_CNPJ;
        }

        foreach ($pairs as $digits => $kind) {
            BlockedDocument::query()->updateOrCreate(
                ['document' => (string) $digits],
                [
                    'kind' => $kind,
                    'user_id' => $user->id,
                    'blocked_by' => $blockedBy,
                    'reason' => $reason,
                ],
            );
        }

        return array_map('strval', array_keys($pairs));
    }

    private static function kindFor(string $digits): ?string
    {
        return match (strlen($digits)) {
            11 => BlockedDocument::KIND_CPF,
            14 => BlockedDocument::KIND_CNPJ,
            default => null,
        };
    }
}
