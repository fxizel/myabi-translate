<?php

namespace App\Console\Commands;

use App\Services\CatalogueSearch\Projection;
use Illuminate\Console\Command;
use Throwable;

class RebuildCatalogueSearch extends Command
{
    protected $signature = 'referentiel:catalogue-search-rebuild';

    protected $description = 'Rebuild the catalogue search projection in bounded, resumable batches.';

    public function handle(Projection $projection): int
    {
        try {
            $this->info(json_encode($projection->rebuild(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Catalogue search reconstruction did not finish. Check its state, private sources and storage, then retry.');

            return self::FAILURE;
        }
    }
}
