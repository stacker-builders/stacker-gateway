<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramAccessExpiryService;
use Illuminate\Console\Command;

class ExpireTelegramAccessCommand extends Command
{
    protected $signature = 'telegram:expire-access {--limit=200 : Máximo de grants por execução}';

    protected $description = 'Marca acessos Telegram vencidos e remove membros quando não houver outro direito válido.';

    public function handle(TelegramAccessExpiryService $expiry): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stats = $expiry->processDue($limit);

        $this->info(sprintf(
            'Telegram expire: expired=%d removed=%d skipped=%d failed=%d',
            $stats['expired'],
            $stats['removed'],
            $stats['skipped'],
            $stats['failed']
        ));

        return self::SUCCESS;
    }
}
