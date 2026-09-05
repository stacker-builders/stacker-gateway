<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Setting;
use App\Models\User;
use App\Support\DatabaseBackupSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformDatabaseBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        Storage::fake('local');
        Cache::flush();
    }

    public function test_platform_admin_can_save_backup_settings(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->post('/plataforma/configuracoes', [
            'backup_enabled' => '1',
            'backup_daily_at' => '03:00',
            'backup_retention_days' => 14,
            'backup_destination_provider' => 'wasabi',
            'backup_destination_s3_key' => 'wasabi-key',
            'backup_destination_s3_secret' => 'wasabi-secret',
            'backup_destination_s3_bucket' => 'backups-bucket',
            'backup_destination_s3_region' => 'us-east-1',
            'backup_destination_s3_endpoint' => 'https://s3.wasabisys.com',
            'backup_destination_prefix' => 'stacker/db',
        ]);

        $response->assertRedirect();
        $this->assertTrue(DatabaseBackupSettings::enabled());
        $this->assertSame('03:00', DatabaseBackupSettings::dailyAt());
        $this->assertSame(14, DatabaseBackupSettings::retentionDays());
        $this->assertSame('wasabi', DatabaseBackupSettings::destinationProvider());
        $this->assertSame('stacker/db', DatabaseBackupSettings::destinationPrefix());

        $rows = Setting::query()->where('key', 'like', 'backup_%')->whereNull('tenant_id')->pluck('value', 'key')->all();
        $this->assertSame('wasabi-key', $rows['backup_destination_s3_key'] ?? null, 'rows='.json_encode($rows));
        $this->assertSame('backups-bucket', $rows['backup_destination_s3_bucket'] ?? null);
        $this->assertSame('wasabi-key', DatabaseBackupSettings::resolveDestinationCredentials()['key']);
    }

    public function test_settings_page_includes_backup_tab_payload(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $page = $this->actingAs($admin)->get('/plataforma/configuracoes?tab=backup');
        $page->assertOk();
        $page->assertInertia(fn ($assert) => $assert
            ->component('Settings/Index')
            ->where('settings.backup_enabled', '0')
            ->where('settings.backup_daily_at', '03:00')
            ->where('settings.backup_retention_days', 7)
            ->where('settings.backup_destination_provider', 'local')
            ->where('backup_storage.provider', 'local')
            ->where('backup_storage.is_remote', false)
            ->where('backup_storage.independent_from_media', true)
            ->has('backup_files')
            ->has('backup_status'));
    }

    public function test_incomplete_remote_destination_fails_run_without_using_media_storage(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        DatabaseBackupSettings::persistFromValidated([
            'backup_destination_provider' => 'r2',
            'backup_destination_s3_key' => '',
            'backup_destination_s3_bucket' => '',
        ]);

        // Media storage remote would not matter — backup uses its own destination.
        Setting::set('storage_provider', 'r2', null);

        $run = $this->actingAs($admin)->postJson('/plataforma/configuracoes/backup/run');
        $run->assertStatus(422)->assertJson(['success' => false]);
        $this->assertStringContainsString('Destino de backup remoto incompleto', (string) $run->json('message'));
    }

    public function test_manual_run_stores_backup_and_download_returns_gzip(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $run = $this->actingAs($admin)->postJson('/plataforma/configuracoes/backup/run');
        $run->assertOk()->assertJson(['success' => true]);
        $filename = $run->json('file.filename');
        $this->assertIsString($filename);
        Storage::disk('local')->assertExists('backups/db/'.$filename);

        $download = $this->actingAs($admin)->get('/plataforma/configuracoes/backup/arquivos/'.$filename);
        $download->assertOk();
        $download->assertHeader('content-type', 'application/gzip');
        $this->assertStringStartsWith("\x1f\x8b", $download->streamedContent());
    }

    public function test_generate_download_streams_gzip_without_requiring_storage_copy(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->post('/plataforma/configuracoes/backup/download');
        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringStartsWith("\x1f\x8b", $response->streamedContent());
    }

    public function test_artisan_force_creates_backup_and_skip_when_disabled(): void
    {
        $this->artisan('backup:database')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->files('backups/db'));

        $this->artisan('backup:database', ['--force' => true])->assertSuccessful();
        $this->assertNotEmpty(Storage::disk('local')->files('backups/db'));
    }

    public function test_infoprodutor_cannot_run_backup(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        $this->actingAs($seller)->postJson('/plataforma/configuracoes/backup/run')->assertForbidden();
    }

    public function test_rejects_unsafe_stored_filename(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/configuracoes/backup/arquivos/'.rawurlencode('../secrets.sql.gz'))
            ->assertNotFound();
    }
}
