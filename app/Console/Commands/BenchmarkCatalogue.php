<?php

namespace App\Console\Commands;

use App\Domain\Devconf\FormatRegistry;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\AtomicFileWriter;
use App\Services\ImportService;
use App\Services\OperationLock;
use App\Services\StorageBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BenchmarkCatalogue extends Command
{
    protected $signature = 'referentiel:benchmark {--passes=2} {--path=data/imports} {--measure-capacity : Permit local measurement beyond the production quota}';

    protected $description = 'Local-only full-catalogue benchmark; prints counts and timings, never business values';

    public function handle(ImportService $imports, StorageBudget $budget, OperationLock $lock): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Benchmark is restricted to local/test environments.');

            return self::FAILURE;
        }
        DB::disableQueryLog();
        $targetQuota = config('referentiel.storage_quota');
        if ($this->option('measure-capacity')) {
            config(['referentiel.storage_quota' => 20 * 1024 * 1024 * 1024]);
        }
        $this->call('db:seed', ['--force' => true]);
        $root = Organisation::where('is_root', true)->firstOrFail();
        $user = User::firstOrCreate(['email' => 'benchmark@referentiel.invalid'], ['name' => 'Recette locale', 'organization_id' => $root->id, 'password' => Hash::make(bin2hex(random_bytes(32))), 'roles' => ['manager' => ['de', 'fr', 'it', 'en']], 'active' => true, 'notifications_enabled' => false]);
        $version = MyabiVersion::firstOrCreate(['number' => '2026.2'], ['released_at' => '2026-09-17', 'status' => 'current', 'created_by' => $user->id, 'updated_by' => $user->id]);
        $results = [];
        $start = microtime(true);

        return $lock->run(function () use ($imports, $budget, $user, $version, &$results, $start, $targetQuota) {
            for ($pass = 1; $pass <= (int) $this->option('passes'); $pass++) {
                foreach (FormatRegistry::all() as $type => $format) {
                    $t = microtime(true);
                    $path = base_path($this->option('path').'/'.$format->filename);
                    $import = $imports->receive($path, $format->filename, ['type' => $type, 'version_id' => $version->id, 'organization_id' => null, 'source_kind' => 'publisher'], $user);
                    $imports->analyze($import);
                    $analysis = microtime(true) - $t;
                    $imports->apply($import->fresh());
                    $elapsed = microtime(true) - $t;
                    $result = ['pass' => $pass, 'type' => $type, 'import_id' => $import->id, 'rows' => $import->fresh()->row_count, 'analysis_seconds' => round($analysis, 3), 'total_seconds' => round($elapsed, 3), 'status' => $import->fresh()->status, 'memory_peak' => memory_get_peak_usage(true)];
                    $results[] = $result;
                    $this->line(json_encode($result));
                    if ($import->fresh()->status !== 'applied') {
                        return self::FAILURE;
                    }
                }
                $usage = $budget->usage();
                $this->line(json_encode(['pass' => $pass, 'storage' => $usage, 'within_target_quota' => $usage['bytes'] <= $targetQuota]));
            }
            $summary = ['environment' => 'LOCAL Windows PHP + Docker MariaDB (not alwaysdata)', 'timestamp' => now()->toIso8601String(), 'seconds' => round(microtime(true) - $start, 3), 'rows' => DB::table('import_rows')->count(),
                'terms' => Term::count(), 'proposals' => Proposal::count(), 'revisions' => Revision::count(), 'memory_peak' => memory_get_peak_usage(true), 'storage' => $budget->usage(), 'target_quota' => $targetQuota, 'runs' => $results];
            app(AtomicFileWriter::class)->write(storage_path('app/private/benchmark.json'), json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line(json_encode($summary));

            return self::SUCCESS;
        }, true);
    }
}
