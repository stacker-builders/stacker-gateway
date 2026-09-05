<?php

namespace App\Console\Commands;

use App\Services\Versell\VersellMedService;
use Illuminate\Console\Command;

class ReconcileVersellInfractionsCommand extends Command
{
    protected $signature = 'versell:reconcile-infractions {--hours=72 : Janela de last_change em horas}';

    protected $description = 'Sincroniza infrações MED da Versell (poll) e abre disputas locais.';

    public function handle(VersellMedService $med): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $result = $med->reconcileRecent($hours);
        $this->info(sprintf(
            'Versell MED: synced=%d skipped=%d errors=%d (hours=%d)',
            $result['synced'],
            $result['skipped'],
            $result['errors'],
            $hours
        ));

        return ($result['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
