<?php

namespace App\Console\Commands;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Term;
use App\Models\User;
use App\Services\BrowserExtension\SearchProjection;
use App\Services\BrowserExtension\SearchService;
use App\Services\BrowserExtension\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BenchmarkBrowserExtensionSearch extends Command
{
    protected $signature = 'referentiel:extension-search-benchmark {--terms=100000} {--samples=30}';

    protected $description = 'Create synthetic fixtures and benchmark extension search in an empty isolated MariaDB test schema.';

    public function handle(SearchProjection $projection, SearchService $search, TextNormalizer $normalizer): int
    {
        $connection = config('database.connections.'.config('database.default'));
        if (! app()->environment('testing') || getenv('MYABI_ISOLATED_TESTS') !== '1'
            || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'])
            || ! preg_match('/\Amyabi_test_[a-z0-9_]+\z/', $connection['database'] ?? '')
            || ! in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || ! $this->emptySchema()) {
            $this->error('Requires an empty isolated local MariaDB testing schema and MYABI_ISOLATED_TESTS=1.');

            return self::FAILURE;
        }
        $count = filter_var($this->option('terms'), FILTER_VALIDATE_INT);
        $samples = filter_var($this->option('samples'), FILTER_VALIDATE_INT);
        if ($count < 1000 || $count > 600000 || $samples < 20 || $samples > 200) {
            $this->error('Terms must be 1000–600000 and samples 20–200.');

            return self::FAILURE;
        }
        $user = User::create(['name' => 'Synthetic benchmark reader', 'email' => 'benchmark@example.org', 'password' => Hash::make(bin2hex(random_bytes(32))), 'roles' => ['reader'], 'active' => true]);
        $formats = array_values(FormatRegistry::all());
        $projection->rebuild();
        $buildStart = hrtime(true);
        $queries = [];
        for ($offset = 0; $offset < $count; $offset += 200) {
            DB::transaction(function () use ($offset, $count, $formats, $user, $projection, &$queries) {
                $rows = [];
                $attrsByKey = [];
                for ($i = $offset; $i < min($count, $offset + 200); $i++) {
                    $format = $formats[$i % count($formats)];
                    $key = 'SYNTHETIC'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
                    $parts = array_map(fn ($column) => $key.$column, $format->identityColumns);
                    $attrs = [];
                    $validated = [];
                    foreach ($format->attributeNames as $attribute) {
                        $token = preg_replace('/[^a-zA-Z0-9]/', '', $format->code.$key.$attribute);
                        $fr = $i % 100 === 0 ? 'Boutoncommun'.preg_replace('/[^a-zA-Z0-9]/', '', $attribute) : 'Étiquette'.$token;
                        $attrs[$attribute] = ['reference' => 'Referenz'.$token, 'reference_language' => $i % 19 === 0 ? null : 'de',
                            'translations' => ['de' => 'Referenz'.$token, 'fr' => $fr, 'it' => 'Italiano'.$token, 'en' => 'English'.$token],
                            'columns' => ['de' => 'de_CH', 'fr' => 'fr_CH', 'it' => 'it_CH', 'en' => 'en_US']];
                        if ($i % 2 === 0) {
                            $validated[$attribute]['fr'] = 'Validation'.$token;
                        }
                        if ($i % max(1, intdiv($count, 30)) === 1 && $attribute === $format->attributeNames[0]) {
                            $queries[] = $fr;
                        }
                    }
                    $attrsByKey[$key] = $attrs;
                    $rows[] = ['type' => $format->code, 'identity_hash' => hash('sha256', json_encode([$format->code, $parts])), 'key' => json_encode($parts), 'label' => $key,
                        'context' => '', 'source_text' => 'Synthetic reference', 'search_text' => 'Synthetic reference', 'semantic_hash' => hash('sha256', $key),
                        'source_hash' => hash('sha256', json_encode($attrs)), 'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
                        'validated' => json_encode($validated), 'review_needed' => '{}', 'revision_no' => 1, 'lock_version' => 1, 'obsolete' => false,
                        'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('terms')->insert($rows);
                $terms = Term::whereIn('label', array_keys($attrsByKey))->get();
                $attributes = [];
                foreach ($terms as $term) {
                    $attributes[$term->id] = $attrsByKey[$term->label];
                }
                $projection->syncMany($terms, $attributes);
            });
            if (($offset + 200) % 10000 === 0) {
                $this->line('Synthetic terms projected: '.min($count, $offset + 200));
            }
        }
        $buildSeconds = (hrtime(true) - $buildStart) / 1e9;
        $timings = ['exact' => [], 'normalized' => [], 'fuzzy' => []];
        // Warm up the query paths, then record complete service time, excluding client/network.
        foreach ($timings as $kind => $_) {
            $query = $queries[0];
            $query = $kind === 'normalized' ? mb_strtoupper($normalizer->searchKey($query)) : ($kind === 'fuzzy' ? mb_substr($query, 0, mb_strlen($query) - 3) : $query);
            $search->search($user, ['text' => $query, 'lang' => 'fr', 'textTruncated' => false, 'maxResults' => 5]);
        }
        for ($i = 0; $i < $samples; $i++) {
            foreach (array_keys($timings) as $kind) {
                $query = $queries[$i % count($queries)];
                if ($i % 10 === 9) {
                    $query = 'Boutoncommunlabel';
                }
                $query = $kind === 'normalized' ? mb_strtoupper($normalizer->searchKey($query)) : ($kind === 'fuzzy' ? mb_substr($query, 0, mb_strlen($query) - 3) : $query);
                $start = hrtime(true);
                $response = $search->search($user, ['text' => $query, 'lang' => 'fr', 'textTruncated' => false, 'maxResults' => 5]);
                $timings[$kind][] = (hrtime(true) - $start) / 1e6;
                if ($response['results'] === []) {
                    $this->error('Synthetic benchmark query returned no result.');

                    return self::FAILURE;
                }
            }
        }
        $statistics = [];
        foreach ($timings as $kind => $values) {
            sort($values);
            $statistics[$kind] = ['p50_ms' => round($values[(int) floor(count($values) * 0.5)], 3),
                'p95_ms' => round($values[(int) ceil(count($values) * 0.95) - 1], 3), 'max_ms' => round(max($values), 3), 'samples' => count($values)];
        }
        DB::enableQueryLog();
        $search->search($user, ['text' => $queries[0], 'lang' => 'fr', 'textTruncated' => false, 'maxResults' => 5]);
        $logged = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $plans = [];
        foreach ($logged as $query) {
            if (preg_match('/^\s*\(?select\b/i', $query['query']) && str_contains($query['query'], 'browser_extension_search_values')) {
                try {
                    $plans[] = ['sql' => $query['query'], 'plan' => DB::select('EXPLAIN FORMAT=JSON '.$query['query'], $query['bindings'])];
                } catch (Throwable) {
                    $plans[] = ['sql' => $query['query'], 'plan_unavailable' => true];
                }
            }
        }
        $report = ['terms' => $count, 'formats' => count($formats), 'cells' => DB::query()->fromSub(DB::table(SearchProjection::VALUES)->select('term_id', 'attribute', 'language')->distinct(), 'cells')->count(),
            'values' => DB::table(SearchProjection::VALUES)->count(), 'tokens' => DB::table(SearchProjection::TOKENS)->count(), 'build_seconds' => round($buildSeconds, 3),
            'php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY, 'processor' => getenv('PROCESSOR_IDENTIFIER') ?: php_uname('m'),
            'database_version' => DB::selectOne('SELECT VERSION() AS version')->version, 'php_peak_memory_bytes' => memory_get_peak_usage(true),
            'sql_query_count' => count($logged), 'statistics' => $statistics, 'sql_plans' => $plans];
        $directory = base_path('.runtime/browser-extension-benchmark');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->line(json_encode(['terms' => $count, 'values' => $report['values'], 'statistics' => $statistics], JSON_THROW_ON_ERROR));

        return $statistics['exact']['p95_ms'] < 750 && $statistics['normalized']['p95_ms'] < 750 && $statistics['fuzzy']['p95_ms'] < 1500 ? self::SUCCESS : self::FAILURE;
    }

    private function emptySchema(): bool
    {
        $technical = ['migrations', 'catalogue_state', 'common_term_transition', SearchProjection::STATE];
        foreach (Schema::getTableListing() as $table) {
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if (! in_array($name, $technical, true) && DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }
}
