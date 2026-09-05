<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Backup diário do banco (Configurações → Operação → Backup).
 * Destino de armazenamento é independente do storage de mídias da plataforma.
 */
final class DatabaseBackupSettings
{
    public const KEY_ENABLED = 'backup_enabled';

    public const KEY_DAILY_AT = 'backup_daily_at';

    public const KEY_RETENTION_DAYS = 'backup_retention_days';

    public const KEY_DESTINATION_PROVIDER = 'backup_destination_provider';

    public const KEY_DESTINATION_S3_KEY = 'backup_destination_s3_key';

    public const KEY_DESTINATION_S3_SECRET = 'backup_destination_s3_secret';

    public const KEY_DESTINATION_S3_BUCKET = 'backup_destination_s3_bucket';

    public const KEY_DESTINATION_S3_REGION = 'backup_destination_s3_region';

    public const KEY_DESTINATION_S3_ENDPOINT = 'backup_destination_s3_endpoint';

    public const KEY_DESTINATION_PREFIX = 'backup_destination_prefix';

    public const KEY_LAST_STATUS = 'backup_last_status';

    public const KEY_LAST_AT = 'backup_last_at';

    public const KEY_LAST_ERROR = 'backup_last_error';

    public const KEY_LAST_FILENAME = 'backup_last_filename';

    public const KEY_LAST_BYTES = 'backup_last_bytes';

    public const KEY_LAST_DESTINATION = 'backup_last_destination';

    public const DEFAULT_TIME = '03:00';

    public const DEFAULT_RETENTION_DAYS = 7;

    public const DEFAULT_PREFIX = 'backups/db';

    public const MIN_RETENTION_DAYS = 1;

    public const MAX_RETENTION_DAYS = 90;

    /** @var list<string> */
    public const PROVIDERS = ['local', 's3', 'wasabi', 'r2'];

    /** @var list<string> */
    public const FORM_KEYS = [
        self::KEY_ENABLED,
        self::KEY_DAILY_AT,
        self::KEY_RETENTION_DAYS,
        self::KEY_DESTINATION_PROVIDER,
        self::KEY_DESTINATION_S3_KEY,
        self::KEY_DESTINATION_S3_SECRET,
        self::KEY_DESTINATION_S3_BUCKET,
        self::KEY_DESTINATION_S3_REGION,
        self::KEY_DESTINATION_S3_ENDPOINT,
        self::KEY_DESTINATION_PREFIX,
    ];

    public static function enabled(): bool
    {
        $value = Setting::get(self::KEY_ENABLED, '0', null);

        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    public static function dailyAt(): string
    {
        return self::normalizeTime((string) Setting::get(self::KEY_DAILY_AT, self::DEFAULT_TIME, null));
    }

    public static function retentionDays(): int
    {
        $days = (int) Setting::get(self::KEY_RETENTION_DAYS, (string) self::DEFAULT_RETENTION_DAYS, null);

        return max(self::MIN_RETENTION_DAYS, min(self::MAX_RETENTION_DAYS, $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS));
    }

    public static function destinationProvider(): string
    {
        $provider = strtolower(trim((string) Setting::get(self::KEY_DESTINATION_PROVIDER, 'local', null)));

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'local';
    }

    public static function destinationIsRemote(): bool
    {
        return self::destinationProvider() !== 'local';
    }

    public static function destinationLabel(): string
    {
        return match (self::destinationProvider()) {
            'r2' => 'Cloudflare R2',
            's3' => 'AWS S3',
            'wasabi' => 'Wasabi',
            default => 'Disco local do servidor',
        };
    }

    public static function destinationPrefix(): string
    {
        $prefix = trim(str_replace('\\', '/', (string) Setting::get(self::KEY_DESTINATION_PREFIX, self::DEFAULT_PREFIX, null)), '/');
        if ($prefix === '' || str_contains($prefix, '..')) {
            return self::DEFAULT_PREFIX;
        }

        return $prefix;
    }

    /**
     * Credenciais do destino de backup (nunca reutiliza o storage de mídias).
     *
     * @return array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string, url: string, prefix: string}
     */
    public static function resolveDestinationCredentials(): array
    {
        $provider = self::destinationProvider();
        if ($provider === 'local') {
            return [
                'provider' => 'local',
                'key' => '',
                'secret' => '',
                'bucket' => '',
                'region' => '',
                'endpoint' => '',
                'url' => '',
                'prefix' => self::destinationPrefix(),
            ];
        }

        $secretRaw = (string) Setting::get(self::KEY_DESTINATION_S3_SECRET, '', null);
        $secret = '';
        if ($secretRaw !== '') {
            try {
                $secret = Crypt::decryptString($secretRaw);
            } catch (\Throwable) {
                $secret = '';
            }
        }

        $region = trim((string) Setting::get(self::KEY_DESTINATION_S3_REGION, $provider === 'r2' ? 'auto' : 'us-east-1', null));
        if ($provider === 'r2') {
            $region = 'auto';
        }

        return [
            'provider' => $provider,
            'key' => trim((string) Setting::get(self::KEY_DESTINATION_S3_KEY, '', null)),
            'secret' => $secret,
            'bucket' => trim((string) Setting::get(self::KEY_DESTINATION_S3_BUCKET, '', null)),
            'region' => $region !== '' ? $region : ($provider === 'r2' ? 'auto' : 'us-east-1'),
            'endpoint' => trim((string) Setting::get(self::KEY_DESTINATION_S3_ENDPOINT, '', null)),
            'url' => '',
            'prefix' => self::destinationPrefix(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lastRun(): array
    {
        $status = (string) Setting::get(self::KEY_LAST_STATUS, '', null);
        if (! in_array($status, ['ok', 'failed'], true)) {
            $status = '';
        }

        $bytes = (int) Setting::get(self::KEY_LAST_BYTES, '0', null);

        return [
            'status' => $status !== '' ? $status : null,
            'at' => (string) Setting::get(self::KEY_LAST_AT, '', null) ?: null,
            'error' => (string) Setting::get(self::KEY_LAST_ERROR, '', null) ?: null,
            'filename' => (string) Setting::get(self::KEY_LAST_FILENAME, '', null) ?: null,
            'bytes' => $bytes > 0 ? $bytes : null,
            'destination' => (string) Setting::get(self::KEY_LAST_DESTINATION, '', null) ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        $provider = self::destinationProvider();
        $creds = self::resolveDestinationCredentials();

        return [
            'backup_enabled' => self::enabled() ? '1' : '0',
            'backup_daily_at' => self::dailyAt(),
            'backup_retention_days' => self::retentionDays(),
            'backup_destination_provider' => $provider,
            'backup_destination_s3_key' => $creds['key'],
            'backup_destination_s3_bucket' => $creds['bucket'],
            'backup_destination_s3_region' => $provider === 'r2' ? 'auto' : $creds['region'],
            'backup_destination_s3_endpoint' => $creds['endpoint'],
            'backup_destination_prefix' => self::destinationPrefix(),
            'backup_destination_secret_configured' => trim((string) Setting::get(self::KEY_DESTINATION_S3_SECRET, '', null)) !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'backup_enabled' => ['nullable'],
            'backup_daily_at' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'backup_retention_days' => ['nullable', 'integer', 'min:'.self::MIN_RETENTION_DAYS, 'max:'.self::MAX_RETENTION_DAYS],
            'backup_destination_provider' => ['nullable', 'string', 'in:local,s3,wasabi,r2'],
            'backup_destination_s3_key' => ['nullable', 'string', 'max:255'],
            'backup_destination_s3_secret' => ['nullable', 'string', 'max:512'],
            'backup_destination_s3_bucket' => ['nullable', 'string', 'max:255'],
            'backup_destination_s3_region' => ['nullable', 'string', 'max:64'],
            'backup_destination_s3_endpoint' => ['nullable', 'string', 'max:512'],
            'backup_destination_prefix' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated, ?Request $request = null): void
    {
        $touched = false;

        if (array_key_exists(self::KEY_ENABLED, $validated)) {
            $enabled = filter_var($validated[self::KEY_ENABLED], FILTER_VALIDATE_BOOLEAN);
            Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', null);
            $touched = true;
        }

        if (array_key_exists(self::KEY_DAILY_AT, $validated)) {
            Setting::set(self::KEY_DAILY_AT, self::normalizeTime((string) $validated[self::KEY_DAILY_AT]), null);
            $touched = true;
        }

        if (array_key_exists(self::KEY_RETENTION_DAYS, $validated)) {
            $days = (int) $validated[self::KEY_RETENTION_DAYS];
            Setting::set(
                self::KEY_RETENTION_DAYS,
                (string) max(self::MIN_RETENTION_DAYS, min(self::MAX_RETENTION_DAYS, $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS)),
                null
            );
            $touched = true;
        }

        if (array_key_exists(self::KEY_DESTINATION_PROVIDER, $validated)) {
            $provider = strtolower(trim((string) $validated[self::KEY_DESTINATION_PROVIDER]));
            if (! in_array($provider, self::PROVIDERS, true)) {
                $provider = 'local';
            }
            Setting::set(self::KEY_DESTINATION_PROVIDER, $provider, null);
            $touched = true;
        }

        foreach ([
            self::KEY_DESTINATION_S3_KEY,
            self::KEY_DESTINATION_S3_BUCKET,
            self::KEY_DESTINATION_S3_REGION,
            self::KEY_DESTINATION_S3_ENDPOINT,
        ] as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }
            $value = trim((string) ($validated[$key] ?? ''));
            if ($key === self::KEY_DESTINATION_S3_REGION && self::destinationProvider() === 'r2') {
                $value = 'auto';
            }
            Setting::set($key, $value, null);
            $touched = true;
        }

        if (array_key_exists(self::KEY_DESTINATION_S3_SECRET, $validated)) {
            $secret = trim((string) ($validated[self::KEY_DESTINATION_S3_SECRET] ?? ''));
            if ($secret !== '') {
                Setting::set(self::KEY_DESTINATION_S3_SECRET, Crypt::encryptString($secret), null);
                $touched = true;
            }
        }

        if (array_key_exists(self::KEY_DESTINATION_PREFIX, $validated)) {
            Setting::set(self::KEY_DESTINATION_PREFIX, self::normalizePrefix((string) $validated[self::KEY_DESTINATION_PREFIX]), null);
            $touched = true;
        }

        if ($touched) {
            PlatformAuditService::log('platform.backup.settings_updated', [
                'enabled' => self::enabled(),
                'daily_at' => self::dailyAt(),
                'retention_days' => self::retentionDays(),
                'destination_provider' => self::destinationProvider(),
                'destination_prefix' => self::destinationPrefix(),
            ], $request);
        }
    }

    /**
     * @param  array{status: string, filename?: string|null, bytes?: int|null, destination?: string|null, error?: string|null}  $result
     */
    public static function recordLastRun(array $result): void
    {
        $status = ($result['status'] ?? '') === 'ok' ? 'ok' : 'failed';
        Setting::set(self::KEY_LAST_STATUS, $status, null);
        Setting::set(self::KEY_LAST_AT, now()->toIso8601String(), null);
        Setting::set(self::KEY_LAST_ERROR, $status === 'failed' ? mb_substr((string) ($result['error'] ?? 'Falha desconhecida.'), 0, 2000) : '', null);
        Setting::set(self::KEY_LAST_FILENAME, (string) ($result['filename'] ?? ''), null);
        Setting::set(self::KEY_LAST_BYTES, (string) max(0, (int) ($result['bytes'] ?? 0)), null);
        Setting::set(self::KEY_LAST_DESTINATION, (string) ($result['destination'] ?? ''), null);
    }

    public static function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if ($hour <= 23 && $minute <= 59) {
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }

        return self::DEFAULT_TIME;
    }

    public static function normalizePrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if ($prefix === '' || str_contains($prefix, '..')) {
            return self::DEFAULT_PREFIX;
        }

        return $prefix;
    }
}
