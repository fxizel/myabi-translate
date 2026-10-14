<?php

namespace App\Console\Commands;

use App\Services\CommonTermTransition;
use App\Services\OperationLock;
use App\Services\PrivateStoragePermissions;
use Illuminate\Console\Command;

class CommonTerms extends Command
{
    protected $signature = 'referentiel:common-terms {--diagnose : Read-only compatibility inventory (default)}';

    protected $description = 'Diagnose exact common identities and write a private compatibility report';

    public function handle(CommonTermTransition $transition, OperationLock $lock, PrivateStoragePermissions $permissions): int
    {
        return $lock->run(function () use ($transition, $permissions) {
            $directory = storage_path('app/private/reports');
            $permissions->ensureDirectory($directory);
            $path = $directory.'/common-terms-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.jsonl';
            $stream = fopen($path, 'xb');
            if (! $stream) {
                throw new \RuntimeException('Cannot create private compatibility report.');
            }
            try {
                $permissions->secureFile($path);
                $counts = $transition->diagnose(function ($group) use ($stream) {
                    $line = json_encode(['group' => $group], JSON_THROW_ON_ERROR)."\n";
                    if (fwrite($stream, $line) !== strlen($line)) {
                        throw new \RuntimeException('Cannot write private compatibility report.');
                    }
                });
                fwrite($stream, json_encode(['summary' => $counts], JSON_THROW_ON_ERROR)."\n");
            } finally {
                fclose($stream);
            }
            // Public output contains counters only; keys, values and provenance stay private.
            $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
            $this->info('Private report written under storage/app/private/reports.');

            return $counts['blocked_groups'] > 0 ? self::FAILURE : self::SUCCESS;
        }, true);
    }
}
