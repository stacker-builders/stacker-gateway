<?php

namespace App\Gateways\Versell;

/**
 * Normaliza credenciais Versell entre formulário flat (admin) e JSON aninhado (storage).
 *
 * Storage:
 * [
 *   'cash_in' => [client_id, client_secret, certificate_path, private_key_path, pix_key, *_filename],
 *   'cash_out' => [client_id, client_secret, certificate_path, private_key_path, *_filename],
 * ]
 */
final class VersellCredentials
{
    public const API_CASH_IN = 'cash_in';

    public const API_CASH_OUT = 'cash_out';

    /** @var list<string> */
    private const APIS = [self::API_CASH_IN, self::API_CASH_OUT];

    /**
     * Converte payload flat do admin (cash_in_client_id, cash_in_certificate_path, …) para nested.
     *
     * @param  array<string, mixed>  $flat
     * @param  array<string, mixed>  $existingNested  Credenciais já salvas (nested ou flat legado)
     * @return array{cash_in: array<string, mixed>, cash_out: array<string, mixed>}
     */
    public static function nestFromFlat(array $flat, array $existingNested = []): array
    {
        $existing = self::normalize($existingNested);

        $out = [
            self::API_CASH_IN => $existing[self::API_CASH_IN],
            self::API_CASH_OUT => $existing[self::API_CASH_OUT],
        ];

        foreach (self::APIS as $api) {
            foreach (['client_id', 'client_secret', 'pix_key'] as $field) {
                if ($api === self::API_CASH_OUT && $field === 'pix_key') {
                    continue;
                }
                $flatKey = $api.'_'.$field;
                if (! array_key_exists($flatKey, $flat)) {
                    continue;
                }
                $value = $flat[$flatKey];
                if ($field === 'client_secret') {
                    $trimmed = is_string($value) ? trim($value) : '';
                    if ($trimmed === '') {
                        // Preserva secret existente quando o formulário envia vazio
                        continue;
                    }
                    $out[$api]['client_secret'] = $trimmed;
                    continue;
                }
                $out[$api][$field] = is_string($value) ? trim($value) : '';
            }

            foreach (['certificate', 'private_key'] as $fileField) {
                $pathKey = $api.'_'.$fileField.'_path';
                $nameKey = $api.'_'.$fileField.'_filename';
                $nestedPath = $fileField === 'certificate' ? 'certificate_path' : 'private_key_path';
                $nestedName = $fileField === 'certificate' ? 'certificate_filename' : 'private_key_filename';

                if (! empty($flat[$pathKey]) && is_string($flat[$pathKey])) {
                    $out[$api][$nestedPath] = $flat[$pathKey];
                    if (! empty($flat[$nameKey]) && is_string($flat[$nameKey])) {
                        $out[$api][$nestedName] = $flat[$nameKey];
                    }
                }
            }
        }

        // Taxas/mínimos ficam na raiz do JSON (padrão BSPay/Woovi)
        foreach (self::ECONOMICS_KEYS as $feeKey) {
            if (array_key_exists($feeKey, $flat) && $flat[$feeKey] !== null && $flat[$feeKey] !== '') {
                $out[$feeKey] = is_string($flat[$feeKey]) ? trim($flat[$feeKey]) : $flat[$feeKey];
            } elseif (array_key_exists($feeKey, $existingNested)) {
                $out[$feeKey] = $existingNested[$feeKey];
            }
        }

        return $out;
    }

    /** @var list<string> */
    private const ECONOMICS_KEYS = [
        'versell_payout_min_brl',
        'versell_admin_fee_pix_brl',
        'versell_admin_fee_pix_percent',
        'versell_admin_fee_payout_brl',
        'versell_admin_fee_payout_percent',
    ];

    /**
     * Expõe valores flat para o formulário admin (sem paths absolutos).
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    public static function flattenForForm(array $credentials): array
    {
        $nested = self::normalize($credentials);
        $flat = [];

        foreach (self::APIS as $api) {
            $block = $nested[$api];
            $flat[$api.'_client_id'] = (string) ($block['client_id'] ?? '');
            // Secrets: não devolver valor ao browser — campo password fica vazio se já configurado
            $flat[$api.'_client_secret'] = '';
            if ($api === self::API_CASH_IN) {
                $flat[$api.'_pix_key'] = (string) ($block['pix_key'] ?? '');
            }
        }

        foreach (self::ECONOMICS_KEYS as $feeKey) {
            $raw = $credentials[$feeKey] ?? '';
            $flat[$feeKey] = $raw !== null && $raw !== '' ? (string) $raw : '';
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, bool>
     */
    public static function fileFieldsConfigured(array $credentials): array
    {
        $nested = self::normalize($credentials);
        $out = [];

        foreach (self::APIS as $api) {
            $block = $nested[$api];
            $out[$api.'_certificate'] = self::pathConfigured($block['certificate_path'] ?? null);
            $out[$api.'_private_key'] = self::pathConfigured($block['private_key_path'] ?? null);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{cash_in: array<string, mixed>, cash_out: array<string, mixed>}
     */
    public static function normalize(array $credentials): array
    {
        if (isset($credentials['cash_in']) || isset($credentials['cash_out'])) {
            return [
                self::API_CASH_IN => self::normalizeApiBlock(
                    is_array($credentials['cash_in'] ?? null) ? $credentials['cash_in'] : []
                ),
                self::API_CASH_OUT => self::normalizeApiBlock(
                    is_array($credentials['cash_out'] ?? null) ? $credentials['cash_out'] : []
                ),
            ];
        }

        // Flat intermediário (admin) → nested sem recursão
        return [
            self::API_CASH_IN => self::normalizeApiBlock([
                'client_id' => $credentials['cash_in_client_id'] ?? '',
                'client_secret' => $credentials['cash_in_client_secret'] ?? '',
                'certificate_path' => $credentials['cash_in_certificate_path'] ?? '',
                'private_key_path' => $credentials['cash_in_private_key_path'] ?? '',
                'pix_key' => $credentials['cash_in_pix_key'] ?? '',
                'certificate_filename' => $credentials['cash_in_certificate_filename'] ?? '',
                'private_key_filename' => $credentials['cash_in_private_key_filename'] ?? '',
            ]),
            self::API_CASH_OUT => self::normalizeApiBlock([
                'client_id' => $credentials['cash_out_client_id'] ?? '',
                'client_secret' => $credentials['cash_out_client_secret'] ?? '',
                'certificate_path' => $credentials['cash_out_certificate_path'] ?? '',
                'private_key_path' => $credentials['cash_out_private_key_path'] ?? '',
                'certificate_filename' => $credentials['cash_out_certificate_filename'] ?? '',
                'private_key_filename' => $credentials['cash_out_private_key_filename'] ?? '',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function normalizeApiBlock(array $block): array
    {
        return [
            'client_id' => trim((string) ($block['client_id'] ?? '')),
            'client_secret' => (string) ($block['client_secret'] ?? ''),
            'certificate_path' => trim((string) ($block['certificate_path'] ?? '')),
            'private_key_path' => trim((string) ($block['private_key_path'] ?? '')),
            'pix_key' => trim((string) ($block['pix_key'] ?? '')),
            'certificate_filename' => trim((string) ($block['certificate_filename'] ?? '')),
            'private_key_filename' => trim((string) ($block['private_key_filename'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public static function apiBlock(array $credentials, string $api): array
    {
        $api = $api === self::API_CASH_OUT ? self::API_CASH_OUT : self::API_CASH_IN;
        $nested = self::normalize($credentials);

        return $nested[$api];
    }

    public static function pathConfigured(mixed $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        return is_file($path);
    }

    /**
     * @param  array<string, mixed>  $apiBlock
     * @return array{ok: bool, error: ?string}
     */
    public static function assertMtlsFiles(array $apiBlock, string $apiLabel): array
    {
        $cert = trim((string) ($apiBlock['certificate_path'] ?? ''));
        $key = trim((string) ($apiBlock['private_key_path'] ?? ''));

        if ($cert === '' || ! is_file($cert)) {
            return ['ok' => false, 'error' => "{$apiLabel}: certificado CRT não configurado ou arquivo ausente."];
        }
        if ($key === '' || ! is_file($key)) {
            return ['ok' => false, 'error' => "{$apiLabel}: private key KEY não configurada ou arquivo ausente."];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Indica se o secret já está salvo (para UI / validação de teste sem reenviar).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{cash_in: bool, cash_out: bool}
     */
    public static function secretsConfigured(array $credentials): array
    {
        $nested = self::normalize($credentials);

        return [
            self::API_CASH_IN => trim((string) ($nested[self::API_CASH_IN]['client_secret'] ?? '')) !== '',
            self::API_CASH_OUT => trim((string) ($nested[self::API_CASH_OUT]['client_secret'] ?? '')) !== '',
        ];
    }

    /**
     * Credenciais mínimas para cobrança PIX (Cash In).
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function isCashInReady(array $credentials): bool
    {
        $block = self::apiBlock($credentials, self::API_CASH_IN);

        if (trim((string) ($block['client_id'] ?? '')) === '') {
            return false;
        }
        if (trim((string) ($block['client_secret'] ?? '')) === '') {
            return false;
        }
        if (trim((string) ($block['pix_key'] ?? '')) === '') {
            return false;
        }

        $files = self::assertMtlsFiles($block, 'Cash In');

        return $files['ok'] === true;
    }

    /**
     * Credenciais mínimas para saque PIX (Cash Out).
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function isCashOutReady(array $credentials): bool
    {
        $block = self::apiBlock($credentials, self::API_CASH_OUT);

        if (trim((string) ($block['client_id'] ?? '')) === '') {
            return false;
        }
        if (trim((string) ($block['client_secret'] ?? '')) === '') {
            return false;
        }

        $files = self::assertMtlsFiles($block, 'Cash Out');

        return $files['ok'] === true;
    }
}
