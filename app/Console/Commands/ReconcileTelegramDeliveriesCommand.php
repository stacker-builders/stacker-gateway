<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramDeliveryReconcileService;
use Illuminate\Console\Command;

class ReconcileTelegramDeliveriesCommand extends Command
{
    protected $signature = 'telegram:reconcile-deliveries {--limit=100 : Máximo por categoria}';

    protected $description = 'Reconcilia entregas Telegram órfãs (pagamento sem grant, grant sem delivery, webhooks received).';

    public function handle(TelegramDeliveryReconcileService $reconcile): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stats = $reconcile->run($limit);

        $this->info(sprintf(
            'Telegram reconcile: orders=%d delivery=%d identity=%d failed=%d webhooks=%d skipped=%d',
            $stats['orders_without_grant'],
            $stats['grants_without_delivery'],
            $stats['identity_without_invite'],
            $stats['failed_requeued'],
            $stats['webhooks_requeued'],
            $stats['skipped']
        ));

        return self::SUCCESS;
    }
}
