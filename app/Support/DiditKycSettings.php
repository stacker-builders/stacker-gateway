<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * KYC da plataforma pode ser o envio manual ou a verificação hospedada da Didit.
 */
final class DiditKycSettings
{
    public const KEY_ENABLED = 'kyc_didit_enabled';

    public const KEY_API_KEY = 'kyc_didit_api_key';

    public const KEY_WORKFLOW_ID = 'kyc_didit_workflow_id';

    public const KEY_WEBHOOK_SECRET = 'kyc_didit_webhook_secret';

    public const PROVIDER = 'didit';

    public const MANUAL = 'manual';

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            self::KEY_ENABLED => ['nullable', 'boolean'],
            self::KEY_WORKFLOW_ID => ['nullable', 'string', 'max:64'],
            self::KEY_API_KEY => ['nullable', 'string', 'max:512'],
            self::KEY_WEBHOOK_SECRET => ['nullable', 'string', 'max:512'],
        ];
    }

    public static function isEnabled(): bool
    {
        return self::toBool(Setting::get(self::KEY_ENABLED, '0', null));
    }

    public static function isReady(): bool
    {
        return self::isEnabled()
            && self::apiKey() !== ''
            && self::workflowId() !== ''
            && self::webhookSecret() !== '';
    }

    public static function apiKey(): string
    {
        return self::decrypt(self::KEY_API_KEY);
    }

    public static function workflowId(): string
    {
        return trim((string) Setting::get(self::KEY_WORKFLOW_ID, '', null));
    }

    public static function webhookSecret(): string
    {
        return self::decrypt(self::KEY_WEBHOOK_SECRET);
    }

    public static function webhookUrl(): string
    {
        return url('/webhooks/didit');
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            self::KEY_ENABLED => self::isEnabled() ? '1' : '0',
            self::KEY_WORKFLOW_ID => self::workflowId(),
            'kyc_didit_api_key_configured' => self::apiKey() !== '',
            'kyc_didit_webhook_secret_configured' => self::webhookSecret() !== '',
            'kyc_didit_webhook_url' => self::webhookUrl(),
        ];
    }

    /**
     * O que o painel do infoprodutor precisa para escolher a tela.
     *
     * @return array{mode: string, ready: bool}
     */
    public static function forSeller(): array
    {
        if (! self::isEnabled()) {
            return [
                'mode' => self::MANUAL,
                'ready' => true,
            ];
        }

        return [
            'mode' => self::PROVIDER,
            'ready' => self::isReady(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated): void
    {
        $touches = array_key_exists(self::KEY_ENABLED, $validated)
            || array_key_exists(self::KEY_WORKFLOW_ID, $validated)
            || array_key_exists(self::KEY_API_KEY, $validated)
            || array_key_exists(self::KEY_WEBHOOK_SECRET, $validated);

        if (! $touches) {
            return;
        }

        $workflowId = trim((string) ($validated[self::KEY_WORKFLOW_ID] ?? ''));
        if ($workflowId !== '') {
            Setting::set(self::KEY_WORKFLOW_ID, $workflowId, null);
        }

        $apiKey = trim((string) ($validated[self::KEY_API_KEY] ?? ''));
        if ($apiKey !== '') {
            Setting::set(self::KEY_API_KEY, Crypt::encryptString($apiKey), null);
        }

        $secret = trim((string) ($validated[self::KEY_WEBHOOK_SECRET] ?? ''));
        if ($secret !== '') {
            Setting::set(self::KEY_WEBHOOK_SECRET, Crypt::encryptString($secret), null);
        }

        if (! array_key_exists(self::KEY_ENABLED, $validated)) {
            return;
        }

        $enabling = self::toBool($validated[self::KEY_ENABLED]);
        if ($enabling && (self::apiKey() === '' || self::workflowId() === '' || self::webhookSecret() === '')) {
            throw ValidationException::withMessages([
                self::KEY_API_KEY => 'Para usar a Didit, informe a chave de API, o ID do fluxo e o segredo do webhook.',
            ]);
        }

        Setting::set(self::KEY_ENABLED, $enabling ? '1' : '0', null);
    }

    private static function decrypt(string $key): string
    {
        $raw = Setting::get($key, '', null);
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        try {
            return trim((string) Crypt::decryptString($raw));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'on'
            || $value === 'yes';
    }
}
