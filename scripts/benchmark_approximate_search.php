<?php

declare(strict_types=1);

// Local synthetic benchmark. No default application/database bootstrap is accepted.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\Term;
use App\Models\User;
use App\Services\CatalogueSearch\Profile;
use App\Services\CatalogueSearch\Projection;
use App\Services\CatalogueSearch\Search;
use App\Services\ImportService;
use App\Services\OperationLock;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\DB;

$options = getopt('', ['bootstrap:', 'seed:', 'rebuild', 'samples:', 'describe', 'import-type:', 'import-rows:', 'changed', 'import-control', 'update-samples:', 'report:', 'fixtures:']);
if (! isset($options['bootstrap'], $options['report'], $options['fixtures'])) {
    fwrite(STDERR, "Required: --bootstrap=PRIVATE_PHP --report=PRIVATE_JSON --fixtures=PRIVATE_JSON; then --seed=581142, --rebuild or --samples=200\n");
    exit(2);
}
$app = require $options['bootstrap'];
set_exception_handler(static function (Throwable $error) use ($options): void {
    $runtime = realpath(dirname(__DIR__).'/.runtime');
    $directory = realpath(dirname($options['report']));
    if ($runtime && $directory && str_starts_with(strtolower(str_replace('\\', '/', $directory)).'/', strtolower(str_replace('\\', '/', $runtime)).'/')) {
        file_put_contents($directory.'/benchmark-error.txt', (string) $error);
    }
    fwrite(STDERR, 'Synthetic benchmark failed: '.$error::class.". Inspect the private run state.\n");
    exit(1);
});
$connection = DB::connection();
$expectedDirectory = getenv('MYABI_BENCHMARK_DATADIR');
if (! $app->environment('testing') || getenv('MYABI_ISOLATED_TESTS') !== '1' || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
    || ! preg_match('/\Amyabi_test_[a-z0-9_]+\z/', $connection->getDatabaseName())
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true) || ! $expectedDirectory) {
    throw new RuntimeException('An explicitly isolated local testing schema is required.');
}
$actual = $connection->selectOne('SELECT @@datadir AS datadir, VERSION() AS version');
$normalize = fn ($path) => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
if ($normalize($actual->datadir) !== $normalize($expectedDirectory) || ! str_starts_with($actual->version, '11.4.')) {
    throw new RuntimeException('Wrong MariaDB instance or version.');
}
$runtime = $normalize(realpath(base_path('.runtime')) ?: '');
foreach (['report', 'fixtures'] as $option) {
    $directory = realpath(dirname($options[$option]));
    if (! $runtime || ! $directory || ! str_starts_with($normalize($directory).'/', $runtime.'/')) {
        throw new RuntimeException('Benchmark files must stay inside the private ignored runtime directory.');
    }
}
DB::disableQueryLog();
$report = ['php' => PHP_VERSION, 'database_version' => $actual->version, 'platform' => PHP_OS_FAMILY, 'profile_version' => Profile::VERSION];
$start = hrtime(true);
$report['started_at'] = gmdate('c');
if (isset($options['seed'])) {
    $count = filter_var($options['seed'], FILTER_VALIDATE_INT);
    if ($count < 1000 || $count > 1000000) {
        throw new RuntimeException('Synthetic background terms must be 1000–1000000.');
    }
    require __DIR__.'/lib/SyntheticSearchCatalogue.php';
    $fixtures = (new SyntheticSearchCatalogue)->seed($count, fn ($done) => print (json_encode(['synthetic_terms_seeded' => $done]).PHP_EOL));
    file_put_contents($options['fixtures'], json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    file_put_contents(dirname($options['fixtures']).'/corpus.json', json_encode(require base_path('tests/Fixtures/catalogue-search-corpus.php'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $report += ['action' => 'seed', 'terms' => $fixtures['terms'], 'background_terms' => $count];
} elseif (isset($options['rebuild'])) {
    $report += ['action' => 'rebuild', ...app(Projection::class)->rebuild()];
} elseif (isset($options['describe'])) {
    $generation = DB::table('catalogue_search_state')->value('active_generation');
    $possible = 0;
    $types = DB::table('terms')->selectRaw('type, COUNT(*) AS total')->groupBy('type')->get();
    foreach ($types as $type) {
        $possible += $type->total * count(FormatRegistry::get($type->type)->attributeNames) * 4;
    }
    $filled = DB::table('catalogue_search_values')->where('generation', $generation)->select('term_id', 'attribute', 'language')->distinct();
    $report += ['action' => 'describe', 'types' => $types, 'possible_attribute_language_cells' => $possible,
        'filled_eligible_cells' => DB::query()->fromSub($filled, 'cells')->count(),
        'values' => DB::table('catalogue_search_values')->where('generation', $generation)->selectRaw('language, origin, COUNT(*) AS total, MIN(text_length) AS min_characters, AVG(text_length) AS mean_characters, MAX(text_length) AS max_characters')->groupBy('language', 'origin')->get(),
        'proposals' => DB::table('proposals')->selectRaw('status, language, COUNT(*) AS total')->groupBy('status', 'language')->get(),
        'words' => DB::table('catalogue_search_words')->where('generation', $generation)->selectRaw('language, COUNT(*) AS total, MIN(length) AS min_characters, AVG(length) AS mean_characters, MAX(length) AS max_characters')->groupBy('language')->get(),
        'word_frequencies' => DB::table('catalogue_search_occurrences')->selectRaw('word_id, COUNT(*) AS total')->groupBy('word_id')->orderByDesc('total')->limit(30)->get(),
        'length_histogram' => DB::table('catalogue_search_values')->where('generation', $generation)->selectRaw('FLOOR(text_length / 10) * 10 AS lower_bound, COUNT(*) AS total')->groupBy('lower_bound')->get(),
        'reference_cache_bytes' => (int) DB::table('terms')->sum(DB::raw('OCTET_LENGTH(catalogue_reference_de)'))];
} elseif (isset($options['import-type'])) {
    $type = $options['import-type'];
    FormatRegistry::get($type);
    $manager = User::firstOrCreate(['email' => 'manager-benchmark@example.org'], ['name' => 'Synthetic manager', 'roles' => ['manager'], 'active' => true, 'password' => bin2hex(random_bytes(24))]);
    User::firstOrCreate(['is_technical' => true], ['name' => 'Synthetic importer', 'email' => 'technical-benchmark@example.org', 'roles' => [], 'active' => false, 'password' => bin2hex(random_bytes(24))]);
    $source = ImportBatch::where('type', $type)->where('status', 'applied')->orderBy('id')->firstOrFail();
    $importRows = isset($options['import-rows']) ? filter_var($options['import-rows'], FILTER_VALIDATE_INT) : null;
    if (isset($options['import-rows']) && (! $importRows || $importRows < 1 || $importRows > $source->row_count)) {
        throw new RuntimeException('Invalid synthetic import sample size.');
    }
    $file = $source->originalPath();
    $firstTermId = DB::table('import_rows')->where('import_id', $source->id)->orderBy('record_number')->value('term_id');
    $suffix = str_ends_with(Term::findOrFail($firstTermId)->source_text, ' zusätzliche Angaben') ? ' ergänzende Hinweise' : ' zusätzliche Angaben';
    if (isset($options['changed']) || $importRows !== null) {
        $format = FormatRegistry::get($type);
        $file = storage_path('app/private/synthetic-changed-'.$type.'-'.bin2hex(random_bytes(6)).'.csv');
        $handle = fopen($file, 'xb');
        if (! $handle) {
            throw new RuntimeException('Cannot prepare the private synthetic import.');
        }
        try {
            foreach ((new CsvReader)->records($source->originalPath()) as $record) {
                if ($importRows !== null && $record->number > $importRows) {
                    break;
                }
                $values = $record->values;
                if ($record->number > 0 && isset($options['changed'])) {
                    $column = match ($format->layout) {
                        'pairs' => 'Default Label', 'nls' => 'de_CH', 'law' => $format->attributeNames[0].' de_CH', default => $format->attributeNames[0].'_DE',
                    };
                    $values[array_search($column, $format->headers, true)] .= $suffix;
                }
                $bytes = '"'.implode('";"', array_map(fn ($value) => str_replace('"', '""', $value), $values)).'"'."\r\n";
                if (fwrite($handle, Encoding::encode($bytes)) === false) {
                    throw new RuntimeException('Cannot write the private synthetic import.');
                }
            }
        } finally {
            fclose($handle);
        }
    }
    $execute = function () use ($source, $manager, $type, $file) {
        $service = app(ImportService::class);
        $started = hrtime(true);
        $import = $service->receive($file, $source->filename, ['type' => $type, 'version_id' => $source->version_id, 'source_kind' => 'publisher'], $manager);
        $received = hrtime(true);
        $service->analyze($import);
        $analyzed = hrtime(true);
        $service->apply($import->fresh());
        $import->refresh();
        if ($import->status !== 'applied') {
            throw new RuntimeException('Synthetic import did not commit.');
        }

        return ['action' => 'import', 'type' => $type, 'rows' => $import->row_count, 'receive_seconds' => ($received - $started) / 1e9,
            'analyze_seconds' => ($analyzed - $received) / 1e9, 'apply_seconds' => (hrtime(true) - $analyzed) / 1e9, 'counts' => $import->report];
    };
    if (isset($options['import-control'])) {
        $runs = [];
        foreach ([false, true] as $indexed) {
            $runs[] = app(OperationLock::class)->run(function () use ($execute, $indexed) {
                DB::beginTransaction();
                try {
                    if (! $indexed) {
                        DB::table('catalogue_search_state')->where('id', 1)->update(['ready' => false]);
                    }

                    return ['indexed' => $indexed, ...$execute()];
                } finally {
                    DB::rollBack();
                }
            }, true);
        }
        $report += ['action' => 'import_control', 'changed_source' => isset($options['changed']), 'rolled_back' => true, 'runs' => $runs];
    } else {
        $report += app(OperationLock::class)->run($execute, true);
        $report['changed_source'] = isset($options['changed']);
    }
} elseif (isset($options['update-samples'])) {
    $samples = max(1, min(100, (int) $options['update-samples']));
    $manager = User::firstOrCreate(['email' => 'manager-benchmark@example.org'], ['name' => 'Synthetic manager', 'roles' => ['manager'], 'active' => true, 'password' => bin2hex(random_bytes(24))]);
    $term = Term::where('type', 'server')->where('context', 'like', 'synthetic-area-%')->whereRaw("source_text NOT LIKE '%{field}%'")->firstOrFail();
    $runs = [];
    foreach ([false, true] as $indexed) {
        for ($i = 0; $i < $samples; $i++) {
            DB::beginTransaction();
            try {
                if (! $indexed) {
                    DB::table('catalogue_search_state')->where('id', 1)->update(['ready' => false]);
                }
                $started = hrtime(true);
                app(WorkflowService::class)->propose($manager, $term->fresh(), 'label', 'fr', 'Texte synthétique pour la mesure');
                $runs[] = ['indexed' => $indexed, 'seconds' => (hrtime(true) - $started) / 1e9];
            } finally {
                // Compare the same business action without changing the frozen catalogue.
                DB::rollBack();
            }
        }
    }
    $report += ['action' => 'targeted_updates', 'rolled_back' => true, 'runs' => $runs];
} else {
    $samples = (int) ($options['samples'] ?? 200);
    $fixtures = json_decode(file_get_contents($options['fixtures']), true, flags: JSON_THROW_ON_ERROR);
    $user = User::findOrFail($fixtures['user_id']);
    $cases = require base_path('tests/Fixtures/catalogue-search-corpus.php');
    $runs = [];
    for ($i = 0; $i < $samples; $i++) {
        $case = $cases[$i % count($cases)];
        $scope = Term::visibleTo($user);
        // Report selective and broad cases separately; never substitute one for the other.
        $selective = $i % 2 === 0;
        if ($selective) {
            $scope->where('context', $case['id']);
        }
        $started = hrtime(true) / 1e9;
        try {
            $result = DB::transaction(fn () => app(Search::class)->search($scope, ['q' => $case['query'], 'language' => $case['language']], 1, $started));
            $runs[] = ['case' => $case['id'], 'selective' => $selective, 'seconds' => hrtime(true) / 1e9 - $started,
                'status' => $result['status'], 'total' => $result['typeCounts']->sum()];
        } catch (Throwable $error) {
            $runs[] = ['case' => $case['id'], 'selective' => $selective, 'seconds' => hrtime(true) / 1e9 - $started, 'status' => 'error', 'error_type' => $error::class];
        }
    }
    $report += ['action' => 'service_samples', 'visible_time_measured' => false, 'concurrent_clients' => 1, 'runs' => $runs];
}
$report['seconds'] = (hrtime(true) - $start) / 1e9;
$report['finished_at'] = gmdate('c');
$report['peak_memory_bytes'] = memory_get_peak_usage(true);
$report['storage'] = DB::select('SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema=DATABASE()');
file_put_contents($options['report'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(array_diff_key($report, ['storage' => true, 'runs' => true]), JSON_THROW_ON_ERROR).PHP_EOL;
