<?php

namespace Tests\Unit\Platform;

use App\Services\Platform\DatabaseBackupService;
use App\Support\DatabaseBackupSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
    }

    public function test_run_stores_gzipped_sqlite_dump_on_private_disk(): void
    {
        $service = app(DatabaseBackupService::class);
        $result = $service->run('manual');

        $this->assertSame('local', $result['destination']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertMatchesRegularExpression(DatabaseBackupService::FILENAME_PATTERN, $result['filename']);
        Storage::disk('local')->assertExists('backups/db/'.$result['filename']);

        $gz = Storage::disk('local')->get('backups/db/'.$result['filename']);
        $sql = gzdecode($gz);
        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);

        $listed = $service->listBackups();
        $this->assertCount(1, $listed);
        $this->assertSame($result['filename'], $listed[0]['filename']);
    }

    public function test_prune_removes_files_older_than_retention(): void
    {
        DatabaseBackupSettings::persistFromValidated([
            'backup_retention_days' => 7,
        ]);

        Storage::disk('local')->put('backups/db/stacker-20260101-0300-aaaaaaaa.sql.gz', 'old');
        Storage::disk('local')->put('backups/db/stacker-'.now()->format('Ymd-Hi').'-bbbbbbbb.sql.gz', 'new');

        $removed = app(DatabaseBackupService::class)->prune();

        $this->assertSame(1, $removed);
        Storage::disk('local')->assertMissing('backups/db/stacker-20260101-0300-aaaaaaaa.sql.gz');
        Storage::disk('local')->assertExists('backups/db/stacker-'.now()->format('Ymd-Hi').'-bbbbbbbb.sql.gz');
    }

    public function test_scheduled_gate_runs_once_per_day_after_configured_time(): void
    {
        DatabaseBackupSettings::persistFromValidated([
            'backup_enabled' => '1',
            'backup_daily_at' => '03:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-04 02:59:00', 'America/Sao_Paulo'));
        $service = app(DatabaseBackupService::class);
        $this->assertFalse($service->shouldRunScheduledNow());

        Carbon::setTestNow(Carbon::parse('2026-09-04 03:00:00', 'America/Sao_Paulo'));
        $this->assertTrue($service->shouldRunScheduledNow());
        $this->assertFalse($service->shouldRunScheduledNow());

        Carbon::setTestNow();
    }

    public function test_rejects_path_traversal_filename(): void
    {
        $this->expectException(\RuntimeException::class);
        app(DatabaseBackupService::class)->assertSafeFilename('../etc/passwd.sql.gz');
    }

    public function test_custom_prefix_is_used_for_local_destination(): void
    {
        DatabaseBackupSettings::persistFromValidated([
            'backup_destination_provider' => 'local',
            'backup_destination_prefix' => 'offsite/dumps',
        ]);

        $service = app(DatabaseBackupService::class);
        $result = $service->run('manual');

        Storage::disk('local')->assertExists('offsite/dumps/'.$result['filename']);
        Storage::disk('local')->assertMissing('backups/db/'.$result['filename']);
    }
}
