<?php

namespace Tests\Unit\Platform;

use App\Services\Platform\SystemLogReader;
use Tests\TestCase;

class SystemLogReaderTest extends TestCase
{
    public function test_parses_cielo_pix_warning_and_filters_by_search(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sg-syslogs-'.uniqid('', true);
        mkdir($dir);
        $date = '2026-09-04';
        $path = $dir.DIRECTORY_SEPARATOR.'laravel-'.$date.'.log';
        file_put_contents($path, implode("\n", [
            '[2026-09-04 15:40:00] production.INFO: checkout started',
            '[2026-09-04 15:43:15] production.WARNING: CieloDriver request failed {"status":400,"message":"Affiliation not found"}',
            '[2026-09-04 15:43:15] production.WARNING: PaymentService: PIX gateway failed. {"gateway":"cielo","order_id":1631,"message":"Cielo: Affiliation not found","duration_ms":258}',
            '[2026-09-04 15:43:16] production.ERROR: boom {"merchant_key":"super-secret-key"}',
        ])."\n");

        $reader = new SystemLogReader($dir);

        $warnings = $reader->query($date, 'warning', 'cielo', 100);
        $this->assertCount(2, $warnings['entries']);
        $this->assertSame('WARNING', $warnings['entries'][0]['level']);
        $this->assertSame('PaymentService: PIX gateway failed.', $warnings['entries'][0]['message']);
        $this->assertSame('cielo', $warnings['entries'][0]['context']['gateway'] ?? null);

        $errors = $reader->query($date, 'error', '', 100);
        $this->assertCount(1, $errors['entries']);
        $this->assertSame('ERROR', $errors['entries'][0]['level']);
        $this->assertSame('***', $errors['entries'][0]['context']['merchant_key'] ?? null);

        $all = $reader->query($date, 'all', '', 100);
        $this->assertCount(4, $all['entries']);

        $this->assertContains($date, $reader->availableDates('2026-09-04'));

        @unlink($path);
        @rmdir($dir);
    }

    public function test_rejects_invalid_date_and_missing_file(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sg-syslogs-'.uniqid('', true);
        mkdir($dir);
        $reader = new SystemLogReader($dir);
        $result = $reader->query('../etc/passwd', 'warning', '', 50);
        $this->assertSame('laravel-'.now()->toDateString().'.log', $result['file']['name']);
        $this->assertFalse($result['file']['exists']);
        $this->assertSame([], $result['entries']);
        @rmdir($dir);
    }
}
