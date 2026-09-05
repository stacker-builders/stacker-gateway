<?php

namespace Tests\Unit\Platform;

use App\Support\DatabaseBackupSettings;
use App\Models\Setting;
use Tests\TestCase;

class DatabaseBackupSettingsTest extends TestCase
{
    public function test_defaults_are_off_at_three_am_with_seven_day_retention(): void
    {
        foreach ([
            DatabaseBackupSettings::KEY_ENABLED,
            DatabaseBackupSettings::KEY_DAILY_AT,
            DatabaseBackupSettings::KEY_RETENTION_DAYS,
            DatabaseBackupSettings::KEY_DESTINATION_PROVIDER,
            DatabaseBackupSettings::KEY_DESTINATION_PREFIX,
        ] as $key) {
            Setting::query()->where('key', $key)->delete();
            \Illuminate\Support\Facades\Cache::forget('setting.global.'.$key);
        }

        $this->assertFalse(DatabaseBackupSettings::enabled());
        $this->assertSame('03:00', DatabaseBackupSettings::dailyAt());
        $this->assertSame(7, DatabaseBackupSettings::retentionDays());
        $this->assertSame('local', DatabaseBackupSettings::destinationProvider());
        $this->assertSame('backups/db', DatabaseBackupSettings::destinationPrefix());
    }

    public function test_persist_normalizes_time_and_retention(): void
    {
        DatabaseBackupSettings::persistFromValidated([
            'backup_enabled' => '1',
            'backup_daily_at' => '25:99',
            'backup_retention_days' => 400,
        ]);

        $this->assertTrue(DatabaseBackupSettings::enabled());
        $this->assertSame('03:00', DatabaseBackupSettings::dailyAt());
        $this->assertSame(90, DatabaseBackupSettings::retentionDays());
    }

    public function test_invalid_time_falls_back_to_default(): void
    {
        Setting::set(DatabaseBackupSettings::KEY_DAILY_AT, 'noite', null);
        $this->assertSame('03:00', DatabaseBackupSettings::dailyAt());
    }

    public function test_destination_is_independent_and_stores_encrypted_secret(): void
    {
        DatabaseBackupSettings::persistFromValidated([
            'backup_destination_provider' => 'r2',
            'backup_destination_s3_key' => 'ak-test',
            'backup_destination_s3_secret' => 'secret-test',
            'backup_destination_s3_bucket' => 'bucket-backups',
            'backup_destination_s3_endpoint' => 'https://abc.r2.cloudflarestorage.com',
            'backup_destination_prefix' => 'gateway/dumps',
        ]);

        $this->assertSame('r2', DatabaseBackupSettings::destinationProvider());
        $this->assertTrue(DatabaseBackupSettings::destinationIsRemote());
        $this->assertSame('gateway/dumps', DatabaseBackupSettings::destinationPrefix());

        $creds = DatabaseBackupSettings::resolveDestinationCredentials();
        $this->assertSame('ak-test', $creds['key']);
        $this->assertSame('secret-test', $creds['secret']);
        $this->assertSame('bucket-backups', $creds['bucket']);
        $this->assertSame('auto', $creds['region']);

        // Secret no settings fica criptografado (não em texto puro).
        $raw = (string) Setting::get(DatabaseBackupSettings::KEY_DESTINATION_S3_SECRET, '', null);
        $this->assertNotSame('secret-test', $raw);
        $this->assertNotSame('', $raw);
    }

    public function test_path_traversal_prefix_falls_back_to_default(): void
    {
        DatabaseBackupSettings::persistFromValidated([
            'backup_destination_prefix' => '../etc',
        ]);
        $this->assertSame('backups/db', DatabaseBackupSettings::destinationPrefix());
    }
}
