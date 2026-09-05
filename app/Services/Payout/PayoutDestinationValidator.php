<?php

namespace App\Services\Payout;

use App\Models\User;
use App\Support\BrazilianDocumentDigits;

/**
 * Valida e normaliza destino PIX para saque (painel, API e auto-payout).
 */
class PayoutDestinationValidator
{
    /** @var list<string> */
    public const CAJUPAY_PIX_KEY_TYPES = ['cpf', 'cnpj', 'email', 'phone', 'evp'];

    /** @var list<string> */
    public const API_PIX_KEY_TYPES = ['cpf', 'cnpj', 'email', 'phone', 'evp', 'random'];

    /**
     * Normaliza tipo de chave da API (random → evp).
     */
    public static function normalizePixKeyType(string $pixKeyType): string
    {
        $type = strtolower(trim($pixKeyType));

        return $type === 'random' ? 'evp' : $type;
    }

    /**
     * Resolve CPF/CNPJ do titular para Cajupay (auto-derive em chaves cpf/cnpj).
     */
    public static function resolveOwnerDocument(string $pixKeyType, string $pixKey, ?string $keyOwnerDocument): ?string
    {
        $type = self::normalizePixKeyType($pixKeyType);
        $doc = BrazilianDocumentDigits::onlyDigits((string) $keyOwnerDocument);

        if ($doc !== '' && BrazilianDocumentDigits::isValidCpfOrCnpjLength($doc)) {
            return $doc;
        }

        if (in_array($type, ['cpf', 'cnpj'], true)) {
            $fromKey = BrazilianDocumentDigits::onlyDigits($pixKey);
            if ($fromKey !== '' && BrazilianDocumentDigits::isValidCpfOrCnpjLength($fromKey)) {
                return $fromKey;
            }
        }

        return null;
    }

    /**
     * Valida payload de PUT /payout-destination para o gateway ativo.
     *
     * @param  array{pix_key: string, pix_key_type: string, key_owner_document?: string|null}  $input
     * @return array{ok: true, pix_key: string, pix_key_type: string, key_owner_document: string}|array{ok: false, field: string, message: string}
     */
    public static function validateForUpdate(array $input, ?string $gatewaySlug = null): array
    {
        $slug = $gatewaySlug ?? PlatformPayoutGateway::activeSlug();
        $pixKey = trim((string) ($input['pix_key'] ?? ''));
        $pixKeyType = strtolower(trim((string) ($input['pix_key_type'] ?? '')));

        if ($pixKey === '') {
            return ['ok' => false, 'field' => 'pix_key', 'message' => 'Informe a chave PIX.'];
        }

        if ($slug === null) {
            return ['ok' => false, 'field' => 'pix_key', 'message' => 'Saque automático não configurado na plataforma.'];
        }

        if ($slug === 'cajupay' || $slug === 'versell') {
            if (! in_array($pixKeyType, self::API_PIX_KEY_TYPES, true)) {
                return ['ok' => false, 'field' => 'pix_key_type', 'message' => 'Tipo de chave PIX inválido.'];
            }

            $normalizedType = self::normalizePixKeyType($pixKeyType);
            if (! in_array($normalizedType, self::CAJUPAY_PIX_KEY_TYPES, true)) {
                return ['ok' => false, 'field' => 'pix_key_type', 'message' => 'Tipo de chave PIX inválido.'];
            }

            $ownerDoc = self::resolveOwnerDocument(
                $pixKeyType,
                $pixKey,
                $input['key_owner_document'] ?? null
            );

            if ($ownerDoc === null) {
                $needsExplicit = in_array($normalizedType, ['email', 'phone', 'evp'], true);

                return [
                    'ok' => false,
                    'field' => 'key_owner_document',
                    'message' => $needsExplicit
                        ? 'Informe o CPF ou CNPJ do titular da chave PIX em key_owner_document.'
                        : 'CPF ou CNPJ do titular inválido.',
                ];
            }

            return [
                'ok' => true,
                'pix_key' => $pixKey,
                'pix_key_type' => $normalizedType,
                'key_owner_document' => $ownerDoc,
            ];
        }

        if (in_array($slug, ['spacepag', 'woovi', 'bspay', 'onlyup'], true)) {
            if (! in_array($pixKeyType, ['cpf', 'cnpj', 'email', 'phone', 'evp', 'random'], true)) {
                return ['ok' => false, 'field' => 'pix_key_type', 'message' => 'Tipo de chave PIX inválido.'];
            }

            return [
                'ok' => true,
                'pix_key' => $pixKey,
                'pix_key_type' => self::normalizePixKeyType($pixKeyType),
                'key_owner_document' => BrazilianDocumentDigits::onlyDigits((string) ($input['key_owner_document'] ?? '')),
            ];
        }

        return ['ok' => false, 'field' => 'pix_key', 'message' => 'Saque automático não configurado na plataforma.'];
    }

    /**
     * Verifica se o infoprodutor tem destino PIX completo para saque.
     *
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public static function assertReadyForWithdrawal(User $owner, ?string $gatewaySlug = null): array
    {
        $slug = $gatewaySlug ?? PlatformPayoutGateway::activeSlug();
        if ($slug === null) {
            return [
                'ok' => false,
                'message' => 'Saque automático não disponível. Configure o destino PIX ou contate o suporte.',
            ];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];

        if ($slug === 'cajupay' || $slug === 'versell') {
            $pixKey = PayoutUserSettings::cajuPixKey($settings);
            $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
            $ownerDoc = PayoutUserSettings::cajuPixOwnerDocument($settings);

            if ($pixKey === '' || $pixKeyType === '') {
                return [
                    'ok' => false,
                    'message' => 'Configure a chave PIX de destino com PUT /payout-destination antes de solicitar saque.',
                ];
            }

            if ($ownerDoc === '') {
                $derived = self::resolveOwnerDocument($pixKeyType, $pixKey, null);
                if ($derived === null) {
                    return [
                        'ok' => false,
                        'message' => 'Configure a chave PIX de destino com PUT /payout-destination (incluindo CPF/CNPJ do titular).',
                    ];
                }
            }

            if (! in_array(self::normalizePixKeyType($pixKeyType), self::CAJUPAY_PIX_KEY_TYPES, true)) {
                return [
                    'ok' => false,
                    'message' => 'Tipo de chave PIX de destino inválido. Atualize com PUT /payout-destination.',
                ];
            }

            return ['ok' => true];
        }

        if (in_array($slug, ['spacepag', 'woovi', 'bspay', 'onlyup'], true)) {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                return [
                    'ok' => false,
                    'message' => 'Configure a chave PIX de destino com PUT /payout-destination antes de solicitar saque.',
                ];
            }

            return ['ok' => true];
        }

        return [
            'ok' => false,
            'message' => 'Saque automático não disponível para esta conta.',
        ];
    }

    public static function maskDocument(string $digits): string
    {
        $len = strlen($digits);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($digits, -4);
    }
}
