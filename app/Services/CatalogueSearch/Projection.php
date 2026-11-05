<?php

namespace App\Services\CatalogueSearch;

use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\Term;
use App\Services\OperationLock;
use App\Services\SourceRecord;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class Projection
{
    public function __construct(private Profile $profile, private SourceRecord $source, private OperationLock $lock) {}

    public static function signature(Term $term): string
    {
        return hash('sha256', json_encode([$term->type, $term->source_import_id, $term->source_offset, $term->source_length, $term->source_hash], JSON_THROW_ON_ERROR));
    }

    /** The DE reference cache is independent of the optional fuzzy index and shares the business commit. */
    public function syncMany(Collection $terms, array $attributes = []): void
    {
        if ($terms->isEmpty() || ! Schema::hasTable('catalogue_search_state')) {
            return;
        }
        DB::transaction(function () use ($terms, $attributes) {
            $this->references($terms, $attributes);
            $state = DB::table('catalogue_search_state')->where('id', 1)->lockForUpdate()->first();
            if (! $state->ready || ! $state->active_generation) {
                return;
            }
            if ((int) $state->normalization_version !== Profile::VERSION) {
                DB::table('catalogue_search_state')->where('id', 1)->update(['ready' => false]);

                return;
            }
            try {
                // Catch outside the nested transaction: partial index writes must roll back to its savepoint.
                DB::transaction(fn () => $this->write($terms, (int) $state->active_generation));
                DB::table('catalogue_search_dirty')->whereIn('term_id', $terms->pluck('id'))->where('mutation_no', '<=', $state->mutation_no)->delete();
            } catch (Throwable $error) {
                if ($error instanceof DeadlockException || ! DB::connection()->getPdo()->inTransaction()) {
                    // A lost/deadlocked outer transaction cannot preserve its business action.
                    throw $error;
                }
                // Never log query bindings or values from a failed projection.
                DB::table('catalogue_search_state')->where('id', 1)->update(['ready' => false]);
                if (app()->bound('session') && request()->hasSession()) {
                    $session = request()->session();
                    DB::afterCommit(fn () => $session->flash('catalogue_search_warning', true));
                }
            }
        });
    }

    public function references(Collection $terms, array $attributes = []): void
    {
        foreach ($terms as $term) {
            $signature = self::signature($term);
            $stored = $term->catalogue_reference_de;
            if (is_string($stored)) {
                $stored = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            }
            if (($stored['signature'] ?? null) === $signature && isset($stored['source_hash'])) {
                continue;
            }
            $attrs = $attributes[$term->id] ?? $this->source->attributes($term);
            $values = [];
            foreach ($attrs as $attribute => $value) {
                if (($value['reference_language'] ?? null) === 'de' && $value['reference'] !== '') {
                    $values[] = ['attribute' => $attribute, 'value' => $value['reference']];
                }
            }
            $cache = json_encode(['signature' => $signature, 'type' => $term->type, 'source_hash' => $term->source_hash, 'source_import_id' => $term->source_import_id,
                'source_offset' => $term->source_offset, 'source_length' => $term->source_length, 'values' => $values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            DB::table('terms')->where('id', $term->id)->update(['catalogue_reference_de' => $cache]);
            $term->catalogue_reference_de = $cache;
        }
    }

    /** Each term/value is projected once; no whole-catalogue PHP collection. */
    protected function write(Collection $terms, int $generation): array
    {
        $old = DB::table('catalogue_search_values')->where('generation', $generation)->whereIn('term_id', $terms->pluck('id'))->select('id');
        DB::table('catalogue_search_occurrences')->whereIn('value_id', $old)->delete();
        DB::table('catalogue_search_values')->where('generation', $generation)->whereIn('term_id', $terms->pluck('id'))->delete();
        $batch = [];
        $counts = ['values' => 0, 'occurrences' => 0];
        $flush = function () use (&$batch, &$counts, $generation) {
            if ($batch) {
                $result = $this->insertValues($batch, $generation);
                $counts['values'] += $result['values'];
                $counts['occurrences'] += $result['occurrences'];
                $batch = [];
            }
        };
        $append = function ($term, $attribute, $language, $origin, $originId, $text) use (&$batch, $flush) {
            if (! is_string($text) || $text === '' || ! in_array($language, ['de', 'fr', 'it', 'en'], true)) {
                return;
            }
            $batch[] = ['term_id' => $term->id, 'attribute' => $attribute, 'language' => $language, 'origin' => $origin,
                'origin_id' => $originId, 'fingerprint' => hash('sha256', $text), 'text_length' => mb_strlen($text), 'tokens' => $this->profile->tokens($text)];
            if (count($batch) >= 100) {
                $flush();
            }
        };
        foreach ($terms as $term) {
            if ($term->consolidated_into_id !== null) {
                continue;
            }
            $cache = is_array($term->catalogue_reference_de) ? $term->catalogue_reference_de : json_decode($term->catalogue_reference_de, true, flags: JSON_THROW_ON_ERROR);
            if (($cache['signature'] ?? null) !== self::signature($term)) {
                throw new RuntimeException('Reference projection requires repair.');
            }
            foreach ($cache['values'] as $value) {
                $append($term, $value['attribute'], 'de', 'reference', 0, $value['value']);
            }
            foreach ($term->validated as $attribute => $languages) {
                foreach ($languages as $language => $text) {
                    $append($term, $attribute, $language, 'current', 0, $text);
                }
            }
        }
        $byId = $terms->keyBy('id');
        foreach (DB::table('proposals')->whereIn('term_id', $terms->pluck('id'))->where('status', 'pending')->orderBy('id')->lazyById(200) as $proposal) {
            $term = $byId[$proposal->term_id];
            if ($term->consolidated_into_id === null && in_array($proposal->attribute, FormatRegistry::get($term->type)->attributeNames, true)) {
                $append($term, $proposal->attribute, $proposal->language, 'proposal', $proposal->id, $proposal->value);
            }
        }
        $flush();

        return $counts;
    }

    private function insertValues(array $batch, int $generation): array
    {
        $words = [];
        foreach ($batch as $value) {
            foreach ($value['tokens'] as $token) {
                $words[$value['language'].'|'.$token['word']] = ['generation' => $generation, 'language' => $value['language'], 'word' => $token['word'],
                    'word_hash' => hash('sha256', $token['word']), 'length' => mb_strlen($token['word'])];
            }
        }
        $wordIds = [];
        foreach (array_chunk($words, 300, true) as $chunk) {
            $hashes = array_column($chunk, 'word_hash');
            $found = DB::table('catalogue_search_words')->where('generation', $generation)->whereIn('language', array_unique(array_column($chunk, 'language')))->whereIn('word_hash', $hashes)->get();
            foreach ($found as $word) {
                $key = $word->language.'|'.$word->word;
                if (isset($chunk[$key])) {
                    $wordIds[$key] = $word->id;
                    unset($chunk[$key]);
                }
            }
            if ($chunk) {
                $max = DB::table('catalogue_search_words')->max('id') ?? 0;
                DB::table('catalogue_search_words')->insert(array_values($chunk));
                $fragments = [];
                foreach (DB::table('catalogue_search_words')->where('id', '>', $max)->orderBy('id')->get() as $word) {
                    $wordIds[$word->language.'|'.$word->word] = $word->id;
                    foreach ($this->profile->fragments($word->word) as $gram => $frequency) {
                        $fragments[] = ['generation' => $generation, 'language' => $word->language, 'word_id' => $word->id, 'fragment' => $gram, 'frequency' => $frequency];
                        if (count($fragments) === 300) {
                            DB::table('catalogue_search_fragments')->insert($fragments);
                            $fragments = [];
                        }
                    }
                }
                if ($fragments) {
                    DB::table('catalogue_search_fragments')->insert($fragments);
                }
            }
        }
        $max = DB::table('catalogue_search_values')->max('id') ?? 0;
        $rows = [];
        foreach ($batch as $value) {
            $row = $value;
            unset($row['tokens']);
            $rows[] = [...$row, 'generation' => $generation, 'word_count' => count($value['tokens'])];
        }
        DB::table('catalogue_search_values')->insert($rows);
        $inserted = DB::table('catalogue_search_values')->where('id', '>', $max)->orderBy('id')->get();
        if ($inserted->count() !== count($batch)) {
            throw new RuntimeException('Projection writer is not serialized.');
        }
        $occurrences = [];
        $count = 0;
        foreach ($inserted as $i => $value) {
            foreach ($batch[$i]['tokens'] as $position => $token) {
                $occurrences[] = ['value_id' => $value->id, 'word_id' => $wordIds[$value->language.'|'.$token['word']],
                    'position' => $position, 'start' => $token['start'], 'length' => $token['length']];
                $count++;
                if (count($occurrences) === 300) {
                    DB::table('catalogue_search_occurrences')->insert($occurrences);
                    $occurrences = [];
                }
            }
        }
        if ($occurrences) {
            DB::table('catalogue_search_occurrences')->insert($occurrences);
        }

        return ['values' => count($batch), 'occurrences' => $count];
    }

    public function rebuild(): array
    {
        return $this->lock->run(function () {
            DB::transaction(function () {
                $state = DB::table('catalogue_search_state')->where('id', 1)->lockForUpdate()->first();
                $resume = $state->build_generation && $state->build_mutation === $state->mutation_no && (int) $state->normalization_version === Profile::VERSION;
                DB::table('catalogue_search_state')->where('id', 1)->update(['ready' => false,
                    'build_generation' => $resume ? $state->build_generation : max($state->active_generation, $state->build_generation) + 1,
                    'build_mutation' => $state->mutation_no, 'cursor' => $resume ? $state->cursor : 0,
                    'build_values' => $resume ? $state->build_values : 0, 'build_occurrences' => $resume ? $state->build_occurrences : 0,
                    'normalization_version' => Profile::VERSION]);
            });
            foreach (ImportBatch::whereIn('id', Term::select('source_import_id'))->lazyById(100) as $import) {
                if (! is_file($import->originalPath()) || hash_file('sha256', $import->originalPath()) !== $import->sha256) {
                    throw new RuntimeException('Private source integrity check failed.');
                }
            }
            do {
                $done = DB::transaction(function () {
                    $state = DB::table('catalogue_search_state')->where('id', 1)->lockForUpdate()->first();
                    if ($state->mutation_no !== $state->build_mutation) {
                        throw new RuntimeException('Catalogue changed during reconstruction. Retry the command.');
                    }
                    $terms = Term::with('sourceImport')->where('id', '>', $state->cursor)->orderBy('id')->limit(config('catalogue-search.batch_size'))->get();
                    if ($terms->isNotEmpty()) {
                        $this->references($terms);
                        $counts = $this->write($terms, (int) $state->build_generation);
                        DB::table('catalogue_search_state')->where('id', 1)->update(['cursor' => $terms->last()->id,
                            'build_values' => $state->build_values + $counts['values'], 'build_occurrences' => $state->build_occurrences + $counts['occurrences']]);

                        return false;
                    }

                    return true;
                });
            } while (! $done);
            // ANALYZE implicitly commits on MariaDB. Keep it outside activation
            // and refresh estimates after bulk loading, before exposing the index.
            if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mariadb', 'mysql'])) {
                DB::statement('ANALYZE TABLE catalogue_search_values, catalogue_search_words, catalogue_search_occurrences, catalogue_search_fragments');
            }
            DB::transaction(function () {
                $state = DB::table('catalogue_search_state')->where('id', 1)->lockForUpdate()->first();
                if ($state->mutation_no !== $state->build_mutation || (int) $state->normalization_version !== Profile::VERSION) {
                    throw new RuntimeException('Catalogue changed before activation. Retry the command.');
                }
                $values = DB::table('catalogue_search_values')->where('generation', $state->build_generation);
                $occurrences = DB::table('catalogue_search_occurrences')->whereIn('value_id', (clone $values)->select('id'));
                $missingWords = DB::table('catalogue_search_occurrences as o')->leftJoin('catalogue_search_words as w', 'w.id', '=', 'o.word_id')
                    ->whereIn('o.value_id', (clone $values)->select('id'))->whereNull('w.id')->exists();
                if ((clone $values)->count() !== (int) $state->build_values || (clone $occurrences)->count() !== (int) $state->build_occurrences
                    || (int) (clone $values)->sum('word_count') !== (int) $state->build_occurrences
                    || $missingWords || Term::whereNull('catalogue_reference_de')->exists()) {
                    throw new RuntimeException('Projection completeness check failed.');
                }
                DB::table('catalogue_search_dirty')->delete();
                DB::table('catalogue_search_state')->where('id', 1)->update(['active_generation' => $state->build_generation, 'build_generation' => 0, 'cursor' => 0,
                    'ready' => true, 'references_ready' => true]);
            });
            $state = DB::table('catalogue_search_state')->where('id', 1)->first();
            foreach (DB::table('catalogue_search_values')->where('generation', '<>', $state->active_generation)->orderBy('id')->lazyById(200)->chunk(200) as $rows) {
                DB::transaction(function () use ($rows) {
                    $ids = $rows->pluck('id');
                    DB::table('catalogue_search_occurrences')->whereIn('value_id', $ids)->delete();
                    DB::table('catalogue_search_values')->whereIn('id', $ids)->delete();
                });
            }
            foreach (DB::table('catalogue_search_words')->where('generation', '<>', $state->active_generation)->orderBy('id')->lazyById(200)->chunk(200) as $rows) {
                DB::transaction(function () use ($rows) {
                    $ids = $rows->pluck('id');
                    DB::table('catalogue_search_fragments')->whereIn('word_id', $ids)->delete();
                    DB::table('catalogue_search_words')->whereIn('id', $ids)->delete();
                });
            }

            return ['generation' => (int) $state->active_generation, 'values' => (int) $state->build_values, 'occurrences' => (int) $state->build_occurrences];
        }, true);
    }
}
