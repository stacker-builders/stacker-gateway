<?php

namespace App\Console\Commands;

use App\Services\Asaas\AsaasPixAutoLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileAsaasPixAutoCommand extends Command
{
    protected $signature = 'asaas:reconcile-pix-auto {--limit=80 : Máximo de assinaturas por execução}';

    protected $description = 'Agenda cobranças de PIX automático da Asaas dentro da janela de 2 a 10 dias úteis.';

    public function handle(AsaasPixAutoLifecycleService $lifecycle): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $stats = $lifecycle->reconcile($limit);
        $this->info(sprintf(
            'Asaas Pix Auto: scheduled=%d errors=%d',
            $stats['scheduled'],
            $stats['errors']
        ));

        return ($stats['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
