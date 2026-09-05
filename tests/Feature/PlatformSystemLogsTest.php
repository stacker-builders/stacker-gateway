<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\User;
use App\Services\Platform\SystemLogReader;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class PlatformSystemLogsTest extends TestCase
{
    private string $logsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        $this->logsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sg-syslogs-feat-'.uniqid('', true);
        mkdir($this->logsDir);
        $this->app->instance(SystemLogReader::class, new SystemLogReader($this->logsDir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logsDir.DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logsDir);
        parent::tearDown();
    }

    public function test_platform_admin_can_view_system_logs(): void
    {
        $date = now()->toDateString();
        file_put_contents($this->logsDir.DIRECTORY_SEPARATOR.'laravel-'.$date.'.log', implode("\n", [
            '['.$date.' 15:43:15] production.WARNING: CieloDriver request failed {"status":400,"message":"Affiliation not found"}',
            '['.$date.' 15:43:16] production.INFO: ignored info line',
        ])."\n");

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.system-logs.index', ['date' => $date, 'level' => 'warning', 'q' => 'cielo']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/SystemLogs/Index')
                ->where('filters.date', $date)
                ->where('entries.0.message', 'CieloDriver request failed')
                ->where('entries.0.context.status', 400)
            );
    }

    public function test_seller_cannot_view_system_logs(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);

        $this->actingAs($seller)
            ->get(route('plataforma.system-logs.index'))
            ->assertForbidden();
    }

    public function test_feed_returns_json_for_admin(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson(route('plataforma.system-logs.feed', ['level' => 'warning']))
            ->assertOk()
            ->assertJsonStructure(['entries', 'available_dates', 'file', 'filters']);
    }
}
