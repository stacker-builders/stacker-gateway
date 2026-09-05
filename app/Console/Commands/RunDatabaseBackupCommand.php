<?php

namespace App\Console\Commands;

use App\Services\Platform\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class RunDatabaseBackupCommand extends Command
{
    protected $signature = 'backup:database
                            {--force : Executa mesmo com o automático desligado ou fora do horário}';

    protected $description = 'Gera dump do banco e envia ao storage configurado (R2/S3/Wasabi ou disco privado local)';

    public function handle(DatabaseBackupService $backups): int
    {
        if (! $this->option('force') && ! $backups->shouldRunScheduledNow()) {
            $this->comment('Backup automático ignorado (desligado, fora do horário ou já executado hoje).');

            return self::SUCCESS;
        }

        try {
            $result = $backups->run($this->option('force') ? 'force' : 'scheduled');
        } catch (Throwable $e) {
            $this->error('Backup falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup ok: %s (%s) → %s. Retenção removeu %d arquivo(s).',
            $result['filename'],
            $this->humanBytes((int) $result['bytes']),
            $result['destination'],
            (int) $result['pruned']
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
