<?php

namespace App\Console\Commands;

use App\Models\Term;
use App\Services\OperationLock;
use App\Services\SearchText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildSearch extends Command
{
    protected $signature = 'referentiel:reindex';

    protected $description = 'Rebuild the compact search projection in bounded, transactional chunks';

    public function handle(OperationLock $lock, SearchText $search): int
    {
        return $lock->run(function () use ($search) {
            Term::with('sourceImport')->chunkById(300, fn ($terms) => DB::transaction(fn () => $search->syncMany($terms)));
            $this->info('Search projection rebuilt.');

            return self::SUCCESS;
        }, true);
    }
}
