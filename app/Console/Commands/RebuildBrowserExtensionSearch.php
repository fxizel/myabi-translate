<?php

namespace App\Console\Commands;

use App\Services\BrowserExtension\SearchProjection;
use Illuminate\Console\Command;
use Throwable;

class RebuildBrowserExtensionSearch extends Command
{
    protected $signature = 'referentiel:extension-search-rebuild';

    protected $description = 'Rebuild the private browser extension search projection in resumable batches.';

    public function handle(SearchProjection $projection): int
    {
        try {
            $result = $projection->rebuild();
            $this->info('Search projection ready: generation '.$result['generation'].', '.$result['values'].' values.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Search projection unavailable. Check the private source storage, then retry this command.');

            return self::FAILURE;
        }
    }
}
