<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Provedor e chave do relatório com IA no painel da plataforma.
 * A chave fica criptografada e não volta para o formulário.
 */
final class PlatformAiReportSettings
{
    public const KEY_ENABLED = 'platform_ai_report_enabled';

    public const KEY_PROVIDER = 'platform_ai_report_provider';

    public const KEY_API_KEY = 'platform_ai_report_api_key';

    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_GEMINI = 'gemini';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_OPENAI,
        self::PROVIDER_GEMINI,
    ];

    /** @var list<string> */
    public const FORM_KEYS = [
        self::KEY_ENABLED,
        self::KEY_PROVIDER,
        self::KEY_API_KEY,
        'platform_ai_report_api_key_configured',
    ];

    public static function isEnabled(): bool
    {
        $value = Setting::get(self::KEY_ENABLED, '0', null);

        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    public static function provider(): string
    {
        $provider = strtolower(trim((string) Setting::get(self::KEY_PROVIDER, self::PROVIDER_GEMINI, null)));

        return in_array($provider, self::PROVIDERS, true) ? $provider : self::PROVIDER_GEMINI;
    }

    public static function providerLabel(?string $provider = null): string
    {
        return match ($provider ?? self::provider()) {
            self::PROVIDER_OPENAI => 'OpenAI',
            default => 'Gemini',
        };
    }

    public static function hasApiKey(): bool
    {
        return self::apiKey() !== '';
    }

    public static function apiKey(): string
    {
        $raw = Setting::get(self::KEY_API_KEY, '', null);
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        try {
            return trim((string) Crypt::decryptString($raw));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function isReady(): bool
    {
        return self::isEnabled() && self::hasApiKey();
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            self::KEY_ENABLED => self::isEnabled() ? '1' : '0',
            self::KEY_PROVIDER => self::provider(),
            'platform_ai_report_api_key_configured' => self::hasApiKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated): void
    {
        $touches = array_key_exists(self::KEY_ENABLED, $validated)
            || array_key_exists(self::KEY_PROVIDER, $validated)
            || array_key_exists(self::KEY_API_KEY, $validated);

        if (! $touches) {
            return;
        }

        $provider = array_key_exists(self::KEY_PROVIDER, $validated)
            ? strtolower(trim((string) ($validated[self::KEY_PROVIDER] ?? '')))
            : self::provider();

        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::withMessages([
                self::KEY_PROVIDER => 'Escolha OpenAI ou Gemini.',
            ]);
        }

        $enabled = array_key_exists(self::KEY_ENABLED, $validated)
            ? self::isTruthy($validated[self::KEY_ENABLED] ?? false)
            : self::isEnabled();

        $incomingKey = array_key_exists(self::KEY_API_KEY, $validated)
            ? trim((string) ($validated[self::KEY_API_KEY] ?? ''))
            : '';

        if ($incomingKey !== '') {
            if (strlen($incomingKey) < 12 || strlen($incomingKey) > 512 || preg_match('/\s/', $incomingKey) === 1) {
                throw ValidationException::withMessages([
                    self::KEY_API_KEY => 'A chave da API é inválida.',
                ]);
            }
            Setting::set(self::KEY_API_KEY, Crypt::encryptString($incomingKey), null);
        } elseif ($provider !== self::provider() && self::hasApiKey()) {
            throw ValidationException::withMessages([
                self::KEY_API_KEY => 'Ao trocar o provedor, cole a chave correspondente.',
            ]);
        }

        if ($enabled && ! self::hasApiKey()) {
            throw ValidationException::withMessages([
                self::KEY_API_KEY => 'Informe a chave da API para ativar o relatório.',
            ]);
        }

        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', null);
        Setting::set(self::KEY_PROVIDER, $provider, null);
    }

    private static function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
