<?php

namespace App\Support;

/**
 * Confere a assinatura do webhook da Didit.
 * O corpo inteiro só é confiável quando X-Signature ou X-Signature-V2 batem.
 * X-Signature-Simple autentica só o envelope (status, sessão, tipo e timestamp).
 */
final class DiditWebhookSignature
{
    public const FULL = 'full';

    public const ENVELOPE = 'envelope';

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, string|null>  $headers
     */
    public static function verify(string $rawBody, array $decoded, string $secret, ?string $timestamp, array $headers): ?string
    {
        if ($secret === '' || $timestamp === null || $timestamp === '' || ! ctype_digit($timestamp)) {
            return null;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return null;
        }

        if (self::matchesAny($headers, ['x-signature-v2', 'x-signature-v2-previous'], self::canonical($decoded), $secret)
            || self::matchesAny($headers, ['x-signature', 'x-signature-previous'], $rawBody, $secret)) {
            return self::FULL;
        }

        $envelope = implode(':', [
            (string) ($decoded['timestamp'] ?? ''),
            (string) ($decoded['session_id'] ?? ''),
            (string) ($decoded['status'] ?? ''),
            (string) ($decoded['webhook_type'] ?? ''),
        ]);

        if (self::matchesAny($headers, ['x-signature-simple', 'x-signature-simple-previous'], $envelope, $secret)) {
            return self::ENVELOPE;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function canonical(array $decoded): string
    {
        $json = json_encode(
            self::sortAndShorten($decoded),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($json) ? $json : '';
    }

    /**
     * @param  array<string, string|null>  $headers
     * @param  list<string>  $names
     */
    private static function matchesAny(array $headers, array $names, string $payload, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);
        foreach ($names as $name) {
            $given = $headers[$name] ?? null;
            if (is_string($given) && $given !== '' && hash_equals($expected, strtolower(trim($given)))) {
                return true;
            }
        }

        return false;
    }

    private static function sortAndShorten(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'sortAndShorten'], $value);
            }

            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $sorted = [];
            foreach ($keys as $key) {
                $sorted[$key] = self::sortAndShorten($value[$key]);
            }

            return $sorted;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        return $value;
    }
}
