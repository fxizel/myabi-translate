<?php

namespace App\Console\Commands;

use App\Services\CatalogueSearch\IndexStatus;
use Illuminate\Console\Command;

class CheckCatalogueSearch extends Command
{
    protected $signature = 'referentiel:catalogue-search-status';

    protected $description = 'Check whether the catalogue approximate search index is ready.';

    public function handle(IndexStatus $indexStatus): int
    {
        $status = $indexStatus->check();
        $this->line($status);
        if ($status === IndexStatus::READY) {
            return self::SUCCESS;
        }

        $this->warn('Verify the migrations and run referentiel:catalogue-search-rebuild during a maintenance window.');

        return self::FAILURE;
    }
}
