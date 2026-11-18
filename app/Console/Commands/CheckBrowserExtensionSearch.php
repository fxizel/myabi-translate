<?php

namespace App\Console\Commands;

use App\Services\BrowserExtension\IndexStatus;
use Illuminate\Console\Command;

class CheckBrowserExtensionSearch extends Command
{
    protected $signature = 'referentiel:extension-search-status';

    protected $description = 'Check whether the browser extension search index is ready.';

    public function handle(IndexStatus $status): int
    {
        if (! config('browser-extension.enabled')) {
            $this->line('disabled');

            return self::SUCCESS;
        }
        if ($status->check()) {
            $this->line('ready');

            return self::SUCCESS;
        }

        $this->line('unavailable');
        $this->warn('Verify the migrations and private source storage, then run referentiel:extension-search-rebuild.');

        return self::FAILURE;
    }
}
