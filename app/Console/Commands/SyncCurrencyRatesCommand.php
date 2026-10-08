<?php

namespace App\Console\Commands;

use App\Services\CurrencyRateSyncService;
use Illuminate\Console\Command;

class SyncCurrencyRatesCommand extends Command
{
    protected $signature = 'currencies:sync-rates';

    protected $description = 'Atualiza as taxas de câmbio das moedas do checkout (dias úteis, cotação Frankfurter).';

    public function handle(CurrencyRateSyncService $sync): int
    {
        $result = $sync->sync();
        if ($result['attempted'] === 0) {
            $this->info('Nenhuma moeda estrangeira configurada.');

            return self::SUCCESS;
        }
        if ($result['updated'] === 0) {
            $this->warn('Nenhuma taxa de câmbio foi atualizada.');

            return self::FAILURE;
        }

        $label = CurrencyRateSyncService::updatedAtLabel() ?? 'agora';
        $this->info("Taxas atualizadas: {$result['updated']}. Última atualização efetiva: {$label}.");

        return self::SUCCESS;
    }
}
