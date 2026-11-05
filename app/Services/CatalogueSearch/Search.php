<?php

namespace App\Services\CatalogueSearch;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Search
{
    public function __construct(private Profile $profile, private Alignment $alignment) {}

    public function snapshot(callable $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            // Next transaction only: an installation using READ COMMITTED must
            // still read state, values, counters and page from one snapshot.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction($callback);
    }

    public function search(Builder $scope, array $data, int $page, float $started): array
    {
        if (DB::transactionLevel() === 0) {
            return $this->snapshot(fn () => $this->search($scope, $data, $page, $started));
        }
        $state = DB::table('catalogue_search_state')->where('id', 1)->first();
        if (! $state?->references_ready) {
            throw ValidationException::withMessages(['q' => __('search.not_prepared')]);
        }
        $language = $data['language'] ?? 'fr';
        $query = trim($data['q']);
        $quoted = strlen($query) >= 2 && str_starts_with($query, '"') && str_ends_with($query, '"');
        $literal = $quoted ? substr($query, 1, -1) : $query;
        $budget = new Budget($started);
        $available = $state->ready && $state->active_generation && (int) $state->normalization_version === Profile::VERSION
            && ! DB::table('catalogue_search_dirty')->exists();
        $fallbackStatus = 'unavailable';
        try {
            $smallIds = $budget->sql(fn () => (clone $scope)->withoutEagerLoads()->select('id')->limit(501)->pluck('id'));
            $smallIds = $smallIds->count() <= 500 ? $smallIds : null;
        } catch (QueryException) {
            $smallIds = null;
        } catch (SearchStopped) {
            throw new ListUnavailable;
        }
        $sort = match ($data['sort'] ?? 'updated') {
            'key' => 'label', 'created' => 'created_at', default => 'updated_at',
        };
        $perPage = (int) ($data['per_page'] ?? 25);
        try {
            $invalidReferences = (clone $scope)->withoutEagerLoads()->whereIn('id', DB::table('catalogue_search_dirty')->select('term_id'))->where(function ($q) {
                $q->whereNull('catalogue_reference_de')->orWhereRaw("COALESCE(JSON_EXTRACT(catalogue_reference_de, '$.source_hash'), '') <> ".(DB::getDriverName() === 'sqlite' ? 'source_hash' : 'JSON_QUOTE(source_hash)'));
                $q->orWhereRaw("COALESCE(JSON_EXTRACT(catalogue_reference_de, '$.type'), '') <> ".(DB::getDriverName() === 'sqlite' ? 'type' : 'JSON_QUOTE(type)'));
                foreach (['source_import_id', 'source_offset', 'source_length'] as $column) {
                    $q->orWhereRaw("COALESCE(CAST(JSON_EXTRACT(catalogue_reference_de, '$.$column') AS ".(DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'UNSIGNED')."), -1) <> $column");
                }
            });
            if ($budget->sql(fn () => $invalidReferences->exists())) {
                throw ValidationException::withMessages(['q' => __('search.not_prepared')]);
            }
            try {
                $usual = $this->usual($scope, $literal, $language, $available ? (int) $state->active_generation : null, $smallIds, $budget);
                $baseline = $this->page($usual, $sort, $perPage, $page, $budget, $available ? 3.0 : 6.0);
            } catch (QueryException $error) {
                if (! $available) {
                    throw $error;
                }
                // The fast ordinary seed may use the fuzzy vocabulary, but the
                // fallback must survive its SQL failure using only business data.
                $available = false;
                $fallbackStatus = in_array((int) ($error->errorInfo[1] ?? 0), [1969, 1205, 1317], true) ? 'timeout' : 'unavailable';
                $usual = $this->usual($scope, $literal, $language, null, $smallIds, $budget);
                $baseline = $this->page($usual, $sort, $perPage, $page, $budget, 6.0);
            }
        } catch (SearchStopped|QueryException) {
            throw new ListUnavailable;
        }
        $baseline += ['usualCount' => $baseline['typeCounts']->sum(), 'approximateCount' => 0, 'status' => 'exact_only'];
        if ($baseline['usualCount'] === 0) {
            // The same snapshot has already proved this relation empty.
            $usual = (clone $scope)->withoutEagerLoads()->whereRaw('1 = 0');
        }
        $baseline['matches'] = $this->usualMatches($baseline['ids'], $literal, $language);
        if ($quoted || mb_strlen(preg_replace('/\s/u', '', $query)) < 3 || $literal === '') {
            return $baseline;
        }
        if (! $available) {
            return [...$baseline, 'status' => $fallbackStatus];
        }
        $suffix = bin2hex(random_bytes(6));
        $variants = 'cs_variants_'.$suffix;
        $results = 'cs_results_'.$suffix;
        try {
            DB::statement('CREATE TEMPORARY TABLE '.$variants.' (slot INTEGER NOT NULL, word_id BIGINT NOT NULL, cost INTEGER NOT NULL, PRIMARY KEY (word_id,slot))');
            DB::statement('CREATE TEMPORARY TABLE '.$results.' (term_id BIGINT PRIMARY KEY, type VARCHAR(20) NOT NULL, cost INTEGER NOT NULL, disorder INTEGER NOT NULL, gap INTEGER NOT NULL, value_id BIGINT NOT NULL, ranges TEXT NOT NULL)');
            foreach (array_unique([$language, 'de']) as $valueLanguage) {
                $tokens = $this->profile->important($literal, $valueLanguage);
                if (! $tokens) {
                    continue;
                }
                DB::table($variants)->delete();
                foreach ($tokens as $slot => $token) {
                    $costs = [];
                    $forms = $token['protected'] ? [$token['word'] => 0] : $this->profile->variants($token['word'], $valueLanguage);
                    foreach ($forms as $form => $familyCost) {
                        foreach ($this->wordCandidates($form, $token['protected'], (int) $state->active_generation, $valueLanguage, $budget) as $word) {
                            $budget->check();
                            $cost = $this->profile->cost($form, $word->word, $token['protected']);
                            if ($cost !== null) {
                                $costs[$word->id] = min($costs[$word->id] ?? PHP_INT_MAX, $cost + $familyCost);
                            }
                        }
                    }
                    foreach (array_chunk($costs, 300, true) as $chunk) {
                        $rows = [];
                        foreach ($chunk as $wordId => $cost) {
                            $rows[] = ['slot' => $slot, 'word_id' => $wordId, 'cost' => $cost];
                        }
                        DB::table($variants)->insert($rows);
                    }
                }
                $this->scoreValues($scope, (int) $state->active_generation, $valueLanguage, $literal, count($tokens), $variants, $results, $budget,
                    $valueLanguage === 'de' && $language !== 'de', $smallIds);
            }
            $budget->check();
            $ordinary = (clone $usual)->withoutEagerLoads()->select('id as term_id', 'type')->selectRaw('0 AS bucket, 0 AS cost, 0 AS disorder, 0 AS gap, NULL AS value_id, NULL AS ranges')->addSelect($sort.' as usual_sort')->toBase();
            $close = DB::table($results)->whereNotIn('term_id', (clone $usual)->withoutEagerLoads()->select('id'))->select('term_id', 'type')->selectRaw('1 AS bucket, cost, disorder, gap, value_id, ranges, NULL AS usual_sort');
            $combined = DB::query()->fromSub($ordinary->unionAll($close), 'matches');
            $counts = $budget->sql(fn () => (clone $combined)->select('type')->selectRaw('COUNT(*) AS total')->groupBy('type')->pluck('total', 'type'))->map(fn ($count) => (int) $count);
            $rows = $budget->sql(fn () => (clone $combined)->orderBy('bucket')->orderBy('usual_sort', $sort === 'label' ? 'asc' : 'desc')
                ->orderBy('cost')->orderBy('disorder')->orderBy('gap')->orderBy('term_id')->forPage($page, $perPage)->get());
            $ids = $rows->pluck('term_id')->map(fn ($id) => (int) $id);
            $matches = $this->usualMatches($rows->where('bucket', 0)->pluck('term_id'), $literal, $language);
            $matches += $this->approximateMatches($rows->where('bucket', 1));
            $budget->check();

            return ['ids' => $ids, 'typeCounts' => $counts, 'usualCount' => $baseline['usualCount'], 'approximateCount' => $counts->sum() - $baseline['usualCount'],
                'status' => 'complete', 'matches' => $matches];
        } catch (SearchStopped|QueryException $error) {
            // The complete ordinary page was prepared before any optional expansion.
            $timeout = $error instanceof SearchStopped || in_array((int) ($error->errorInfo[1] ?? 0), [1969, 1205, 1317], true);

            return [...$baseline, 'status' => $timeout ? 'timeout' : 'unavailable'];
        } finally {
            foreach ([$variants, $results] as $table) {
                DB::statement((DB::getDriverName() === 'sqlite' ? 'DROP TABLE IF EXISTS ' : 'DROP TEMPORARY TABLE IF EXISTS ').$table);
            }
        }
    }

    private function usual(Builder $scope, string $literal, string $language, ?int $generation, ?Collection $smallIds, Budget $budget): Builder
    {
        $query = (clone $scope)->withoutEagerLoads();
        [$reference, $current, $pattern] = $this->literalSql($literal, $language);
        // The compact ordinary projection is a superset. Dirty terms also enter the
        // verification so a direct SQL write can never hide a new admissible value.
        $tokens = $this->profile->tokens($literal);
        if ($smallIds !== null) {
            $seeds = DB::table('terms')->select('id as term_id')->whereIn('id', $smallIds);
        } elseif ($generation && $tokens) {
            usort($tokens, fn ($a, $b) => mb_strlen($b['word']) <=> mb_strlen($a['word']));
            $unique = [];
            foreach ($tokens as $token) {
                $unique[$token['word']] ??= $token;
            }
            $anchors = array_slice(array_values($unique), 0, 3);
            $lookups = [];
            foreach ($anchors as $anchor) {
                $patternWord = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $anchor['word']).'%';
                $lookup = DB::table('catalogue_search_words')->select('id')->where('generation', $generation)
                    ->whereIn('language', array_unique([$language, 'de']))->whereRaw($this->like('word'), [$patternWord]);
                if (! $budget->sql(fn () => (clone $lookup)->exists())) {
                    return $query->whereRaw('1 = 0');
                }
                $frequency = $budget->sql(fn () => DB::table('catalogue_search_occurrences')->whereIn('word_id', $lookup)->count());
                $lookups[] = ['query' => $lookup, 'frequency' => $frequency, 'start' => $anchor['start']];
            }
            usort($lookups, fn ($a, $b) => $a['frequency'] <=> $b['frequency']);
            $base = array_shift($lookups);
            $first = $base['query'];
            $seeds = DB::query()->fromSub($first, 'sw')->join('catalogue_search_occurrences as so', 'so.word_id', '=', 'sw.id')
                ->join('catalogue_search_values as sv', 'sv.id', '=', 'so.value_id')
                ->selectRaw((DB::getDriverName() === 'sqlite' ? '' : 'STRAIGHT_JOIN ').'sv.term_id')->where('sv.generation', $generation)
                ->where(fn ($q) => $q->where('sv.language', $language)->orWhere(fn ($q) => $q->where('sv.language', 'de')->where('sv.origin', 'reference')))
                ->groupBy('sv.term_id');
            foreach ($lookups as $index => $lookup) {
                $alias = 'anchor_'.$index;
                $presence = DB::table('catalogue_search_occurrences as '.$alias)->selectRaw('1')
                    ->whereColumn($alias.'.value_id', 'sv.id')->whereIn($alias.'.word_id', $lookup['query'])
                    ->whereColumn($alias.'.position', $lookup['start'] < $base['start'] ? '<=' : '>=', 'so.position')->limit(1);
                $seeds->whereRaw('('.$presence->toSql().') IS NOT NULL', $presence->getBindings());
            }
        } else {
            $seeds = DB::table('term_search')->select('term_id')->whereIn('term_id', (clone $scope)->withoutEagerLoads()->select('id'))->whereRaw($this->like('search_text'), [$pattern])
                ->union(DB::table('catalogue_search_dirty')->select('term_id'));
        }
        // The candidate relation must drive authoritative JSON verification.
        // A semijoin can otherwise scan/decompress every term before testing membership.
        $join = DB::getDriverName() === 'sqlite' ? 'JOIN' : 'STRAIGHT_JOIN';
        $query->fromRaw('('.$seeds->toSql().') AS seed_terms '.$join.' terms ON terms.id = seed_terms.term_id', $seeds->getBindings());

        return $query->where(function ($query) use ($reference, $current, $pattern, $language) {
            $proposal = DB::table('proposals as cp')->selectRaw('1')->whereColumn('cp.term_id', 'terms.id')->where('cp.status', 'pending')->where('cp.language', $language)
                ->whereRaw($this->like('cp.value'), [$pattern])->limit(1);
            $query->whereRaw($reference, [$pattern])->orWhereRaw($current, [$pattern])
                ->orWhereRaw('('.$proposal->toSql().') IS NOT NULL', $proposal->getBindings());
        });
    }

    private function literalSql(string $literal, string $language): array
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('cs_fold', fn ($text) => $this->profile->fold($text ?? ''), 1, \PDO::SQLITE_DETERMINISTIC);
            $literal = $this->profile->fold($literal);
            $reference = "EXISTS (SELECT 1 FROM json_each(terms.catalogue_reference_de, '$.values') cr WHERE ".$this->like("json_extract(cr.value, '$.value')").')';
            $current = 'EXISTS (SELECT 1 FROM json_each(terms.validated) cv WHERE '.$this->like("json_extract(cv.value, '$.$language')").')';
        } else {
            $reference = "EXISTS (SELECT 1 FROM JSON_TABLE(terms.catalogue_reference_de, '$.values[*]' COLUMNS (value LONGTEXT PATH '$.value')) cr WHERE ".$this->like('cr.value').')';
            $current = "EXISTS (SELECT 1 FROM JSON_TABLE(terms.validated, '$.*' COLUMNS (value LONGTEXT PATH '$.$language')) cv WHERE ".$this->like('cv.value').')';
        }

        return [$reference, $current, '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $literal).'%'];
    }

    private function like(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "cs_fold($column) LIKE ? ESCAPE '!'" : "$column COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!'";
    }

    private function page(Builder $query, string $sort, int $perPage, int $page, Budget $budget, float $statementSeconds): array
    {
        $counts = $budget->sql(fn () => (clone $query)->select('type')->selectRaw('COUNT(*) AS total')->groupBy('type')->pluck('total', 'type'), $statementSeconds)->map(fn ($count) => (int) $count);
        $ids = $counts->sum() > ($page - 1) * $perPage
            ? $budget->sql(fn () => (clone $query)->select('id')->orderBy($sort, $sort === 'label' ? 'asc' : 'desc')->orderBy('id')->forPage($page, $perPage)->pluck('id'), $statementSeconds)
            : collect();

        return ['ids' => $ids, 'typeCounts' => $counts];
    }

    private function wordCandidates(string $word, bool $protected, int $generation, string $language, Budget $budget): Collection
    {
        $length = mb_strlen($word);
        $query = DB::table('catalogue_search_words')->where('generation', $generation)->where('language', $language);
        if ($protected || $length < 5) {
            $forms = [$word];
            if (! $protected && $length >= 3) {
                $characters = mb_str_split($word);
                for ($i = 0; $i < count($characters) - 1; $i++) {
                    $copy = $characters;
                    [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
                    $forms[] = implode('', $copy);
                }
            }
            $query->whereIn('word_hash', array_map(fn ($form) => hash('sha256', $form), $forms));
        } else {
            // A single edit damages at most three bigrams (adjacent transposition).
            // SUM(candidate frequency) is an upper bound on multiset overlap: it may admit extras, never omit a valid word.
            $fragments = DB::table('catalogue_search_fragments')->select('word_id')->where('generation', $generation)->where('language', $language)
                ->whereIn('fragment', array_keys($this->profile->fragments($word)))->groupBy('word_id')->havingRaw('SUM(frequency) >= ?', [max(1, $length - 7)]);
            $query->whereBetween('length', [max(1, $length - 2), $length + 2])->whereIn('id', $fragments);
        }
        $limit = (int) config('catalogue-search.max_word_candidates');
        $rows = $budget->sql(fn () => $query->orderBy('id')->limit($limit + 1)->get(['id', 'word']));
        if ($rows->count() > $limit) {
            throw new SearchStopped;
        }

        return $rows;
    }

    private function scoreValues(Builder $scope, int $generation, string $language, string $literal, int $slots, string $variants, string $results, Budget $budget, bool $referenceOnly, ?Collection $smallIds): void
    {
        if (DB::table($variants)->distinct()->count('slot') !== $slots) {
            return;
        }
        $wordIds = DB::table($variants)->select('word_id')->distinct()->limit(2)->pluck('word_id');
        $singleWord = $wordIds->count() === 1 ? $wordIds->first() : null;
        if ($smallIds === null && $singleWord !== null && $slots === 1) {
            $this->scoreSingleWord($scope, $generation, $language, $literal, $variants, $results, $budget, $referenceOnly, (int) $singleWord);

            return;
        }
        $processed = 0;
        if ($singleWord !== null) {
            $query = $smallIds !== null
                ? DB::table('catalogue_search_values as v')->join('catalogue_search_occurrences as o', 'o.value_id', '=', 'v.id')
                : DB::table('catalogue_search_occurrences as o')->join('catalogue_search_values as v', 'v.id', '=', 'o.value_id');
            $query->where('o.word_id', $singleWord)->havingRaw('COUNT(DISTINCT o.position) >= ?', [$slots]);
        } else {
            $query = $smallIds !== null
                ? DB::table('catalogue_search_values as v')->join('catalogue_search_occurrences as o', 'o.value_id', '=', 'v.id')->join($variants.' as q', 'q.word_id', '=', 'o.word_id')
                : DB::table($variants.' as q')->join('catalogue_search_occurrences as o', 'o.word_id', '=', 'q.word_id')->join('catalogue_search_values as v', 'v.id', '=', 'o.value_id');
            $query->havingRaw('COUNT(DISTINCT q.slot) = ?', [$slots]);
        }
        $query
            ->where('v.generation', $generation)->where('v.language', $language)
            ->selectRaw((DB::getDriverName() === 'sqlite' ? '' : 'STRAIGHT_JOIN ').'o.value_id AS id')->groupBy('o.value_id');
        $this->restrict($query, $scope, 'v.term_id');
        if ($smallIds !== null) {
            $query->whereIn('v.term_id', $smallIds);
            if (DB::getDriverName() !== 'sqlite') {
                $query->forceIndex('cs_value_identity');
            }
        }
        if ($referenceOnly) {
            $query->where('v.origin', 'reference');
        }
        // Read the grouped candidate set once. Repeating GROUP BY for each page
        // rescans common words; INSERT SELECT would lock the business snapshot.
        $spool = SqlSpool::read($query, 8, fn ($row) => pack('P', $row['id']), $budget);
        foreach ($spool->batches() as $records) {
            $values = array_map(fn ($record) => unpack('Pid', $record)['id'], $records);
            $limit = min((int) config('catalogue-search.max_batch_occurrences'), (int) config('catalogue-search.max_occurrences') - $processed);
            $edges = $budget->sql(fn () => DB::table('catalogue_search_occurrences as o')->join($variants.' as q', 'q.word_id', '=', 'o.word_id')->whereIn('o.value_id', $values)
                ->orderBy('o.value_id')->orderBy('q.slot')->orderBy('o.position')->limit($limit + 1)->get(['o.value_id', 'o.position', 'o.start', 'o.length', 'q.slot', 'q.cost']));
            $processed += $edges->count();
            if ($edges->count() > $limit || $processed > config('catalogue-search.max_occurrences')) {
                throw new SearchStopped;
            }
            $grouped = $edges->groupBy('value_id');
            $metadata = $budget->sql(fn () => DB::table('catalogue_search_values as v')->join('terms as t', 't.id', '=', 'v.term_id')->whereIn('v.id', $values)
                ->select('v.id', 'v.term_id', 'v.text_length', 't.type')->get());
            $best = [];
            foreach ($metadata as $value) {
                $budget->check();
                $matrix = array_fill(0, $slots, []);
                foreach ($grouped->get($value->id, collect()) as $edge) {
                    $matrix[$edge->slot][$edge->position] = ['cost' => (int) $edge->cost, 'start' => (int) $edge->start, 'length' => (int) $edge->length];
                }
                $match = $this->alignment->match($matrix, $budget);
                if ($match === null) {
                    continue;
                }
                $row = ['term_id' => $value->term_id, 'type' => $value->type, 'cost' => $match['cost'], 'disorder' => $match['disorder'],
                    'gap' => abs($value->text_length - mb_strlen($literal)), 'value_id' => $value->id, 'ranges' => json_encode($match['ranges'], JSON_THROW_ON_ERROR)];
                if (! isset($best[$value->term_id]) || $this->rank($row) < $this->rank($best[$value->term_id])) {
                    $best[$value->term_id] = $row;
                }
            }
            $this->storeResults($results, $best, $budget);
        }
    }

    private function scoreSingleWord(Builder $scope, int $generation, string $language, string $literal, string $variants, string $results, Budget $budget, bool $referenceOnly, int $wordId): void
    {
        $cost = (int) DB::table($variants)->where('word_id', $wordId)->value('cost');
        $types = array_keys(FormatRegistry::all());
        $typeCodes = array_flip($types);
        $candidates = DB::table('catalogue_search_occurrences as o')->join('catalogue_search_values as v', 'v.id', '=', 'o.value_id')
            ->where('o.word_id', $wordId)->where('v.generation', $generation)->where('v.language', $language)
            ->selectRaw('v.term_id, v.id AS value_id, ABS(v.text_length - ?) AS gap, o.start, o.length, ROW_NUMBER() OVER (PARTITION BY v.term_id ORDER BY ABS(v.text_length - ?), v.id, o.position) AS rn', [mb_strlen($literal), mb_strlen($literal)]);
        if ($referenceOnly) {
            $candidates->where('v.origin', 'reference');
        }
        $query = DB::query()->fromSub($candidates, 'ranked')->join('terms as t', 't.id', '=', 'ranked.term_id')->where('ranked.rn', 1)
            ->select('ranked.term_id', 't.type', 'ranked.value_id', 'ranked.gap', 'ranked.start', 'ranked.length');
        $this->restrict($query, $scope, 'ranked.term_id');
        $spool = SqlSpool::read($query, 33, fn ($row) => pack('PCvvVPVV', $row['term_id'], $typeCodes[$row['type']], $cost, 0, $row['gap'], $row['value_id'], $row['start'], $row['length']), $budget);
        foreach ($spool->batches() as $records) {
            $best = [];
            foreach ($records as $record) {
                $row = unpack('Pterm_id/Ctype/vcost/vdisorder/Vgap/Pvalue_id/Vstart/Vlength', $record);
                $row['type'] = $types[$row['type']];
                $row['ranges'] = json_encode([[$row['start'], $row['length']]], JSON_THROW_ON_ERROR);
                unset($row['start'], $row['length']);
                $best[$row['term_id']] = $row;
            }
            $this->storeResults($results, $best, $budget);
        }
    }

    private function storeResults(string $results, array $best, Budget $budget): void
    {
        $budget->check();
        if (! $best) {
            return;
        }
        foreach ($budget->sql(fn () => DB::table($results)->whereIn('term_id', array_keys($best))->get()) as $old) {
            if ($this->rank((array) $old) <= $this->rank($best[$old->term_id])) {
                unset($best[$old->term_id]);
            }
        }
        if ($best) {
            $budget->sql(fn () => DB::table($results)->upsert(array_values($best), ['term_id'], ['type', 'cost', 'disorder', 'gap', 'value_id', 'ranges']));
        }
    }

    private function rank(array $row): array
    {
        return [(int) $row['cost'], (int) $row['disorder'], (int) $row['gap'], (int) $row['value_id']];
    }

    private function eligible(Builder $scope): Builder
    {
        $query = (clone $scope)->withoutEagerLoads()->select('id');
        if (DB::getDriverName() !== 'sqlite' && ! $scope->getQuery()->wheres) {
            // Cover the canonical-term check without decompressing the full business row per occurrence.
            $query->forceIndex('terms_consolidated_into_id_index');
        }

        return $query;
    }

    private function restrict(\Illuminate\Database\Query\Builder $query, Builder $scope, string $column): void
    {
        $eligible = $this->eligible($scope)->whereColumn('terms.id', $column)->limit(1);
        // A scalar point lookup avoids materializing all eligible term identifiers.
        $query->whereRaw('('.$eligible->toSql().') IS NOT NULL', $eligible->getBindings());
    }

    private function usualMatches(Collection $ids, string $literal, string $language): array
    {
        $matches = [];
        $terms = Term::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($terms as $term) {
            foreach ($this->admitted($term, $language) as $value) {
                $ranges = $this->profile->literalRanges($value['text'], $literal);
                if ($ranges) {
                    $matches[$term->id] = [...$value, ...$this->profile->excerpt($value['text'], $ranges), 'ranges' => $ranges, 'approximate' => false];
                    break;
                }
            }
        }
        [, , $pattern] = $this->literalSql($literal, $language);
        foreach (DB::table('proposals')->whereIn('term_id', $ids)->where('language', $language)->where('status', 'pending')->whereRaw($this->like('value'), [$pattern])->orderBy('id')->lazyById(200) as $proposal) {
            if (! isset($matches[$proposal->term_id])) {
                $ranges = $this->profile->literalRanges($proposal->value, $literal);
                $matches[$proposal->term_id] = ['attribute' => $proposal->attribute, 'language' => $language, 'origin' => 'proposal', 'proposal_id' => $proposal->id,
                    ...$this->profile->excerpt($proposal->value, $ranges), 'ranges' => $ranges, 'approximate' => false];
            }
        }

        return $matches;
    }

    private function admitted(Term $term, string $language): array
    {
        $values = [];
        $cache = is_array($term->catalogue_reference_de) ? $term->catalogue_reference_de : json_decode($term->catalogue_reference_de ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        if (($cache['signature'] ?? null) !== Projection::signature($term)) {
            throw ValidationException::withMessages(['q' => __('search.not_prepared')]);
        }
        $first = FormatRegistry::get($term->type)->attributeNames[0];
        foreach ($term->validated as $attribute => $languages) {
            if (isset($languages[$language])) {
                $values[] = ['attribute' => $attribute, 'language' => $language, 'origin' => 'current', 'proposal_id' => null, 'text' => $languages[$language]];
            }
        }
        foreach ($cache['values'] as $value) {
            $values[] = ['attribute' => $value['attribute'], 'language' => 'de', 'origin' => 'reference', 'proposal_id' => null, 'text' => $value['value']];
        }
        usort($values, fn ($a, $b) => ($a['attribute'] !== $first) <=> ($b['attribute'] !== $first));

        return $values;
    }

    private function approximateMatches(Collection $rows): array
    {
        $values = DB::table('catalogue_search_values')->whereIn('id', $rows->pluck('value_id'))->get()->keyBy('id');
        $terms = Term::whereIn('id', $rows->pluck('term_id'))->get()->keyBy('id');
        $proposals = DB::table('proposals')->whereIn('id', $values->where('origin', 'proposal')->pluck('origin_id'))->where('status', 'pending')->get()->keyBy('id');
        $matches = [];
        foreach ($rows as $row) {
            $value = $values[$row->value_id];
            $term = $terms[$row->term_id];
            $text = null;
            if ($value->origin === 'proposal') {
                $proposal = $proposals->get($value->origin_id);
                if ($proposal && $proposal->term_id === $term->id && $proposal->attribute === $value->attribute && $proposal->language === $value->language) {
                    $text = $proposal->value;
                }
            } else {
                foreach ($this->admitted($term, $value->language) as $candidate) {
                    if ($candidate['origin'] === $value->origin && $candidate['attribute'] === $value->attribute) {
                        $text = $candidate['text'];
                        break;
                    }
                }
            }
            if ($text === null || hash('sha256', $text) !== $value->fingerprint) {
                throw new SearchStopped;
            }
            $ranges = json_decode($row->ranges, true, flags: JSON_THROW_ON_ERROR);
            $matches[$term->id] = ['attribute' => $value->attribute, 'language' => $value->language, 'origin' => $value->origin,
                'proposal_id' => $value->origin === 'proposal' ? $value->origin_id : null, ...$this->profile->excerpt($text, $ranges),
                'ranges' => $ranges, 'approximate' => true];
        }

        return $matches;
    }
}
