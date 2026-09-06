<?php

namespace App\Console\Commands;

use App\Models\ProductCoproducer;
use Illuminate\Console\Command;

class ExpireProductCoproductionsCommand extends Command
{
    protected $signature = 'coproduction:expire';

    protected $description = 'Marca como expiradas as co-produções ativas cujo prazo definido já terminou.';

    public function handle(): int
    {
        $count = ProductCoproducer::expireOverdue();
        $this->info("Co-produções expiradas: {$count}");

        return self::SUCCESS;
    }
}
