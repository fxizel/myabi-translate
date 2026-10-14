<?php

namespace App\Services\BrowserExtension;

use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\Term;
use App\Services\SourceRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SearchProjection
{
    public const STATE = 'browser_extension_search_state';

    public const VALUES = 'browser_extension_search_values';

    public const TOKENS = 'browser_extension_search_tokens';

    public const DIRTY = 'browser_extension_search_dirty';

    public function __construct(private TextNormalizer $normalizer, private SourceRecord $source) {}

    /** Called within the business transaction; already-read import attributes avoid CSV rereads. */
    public function syncMany(Collection $terms, array $attributes = []): void
    {
        if ($terms->isEmpty() || ! Schema::hasTable(self::STATE)) {
            return;
        }
        DB::transaction(function () use ($terms, $attributes) {
            $state = DB::table(self::STATE)->where('id', 1)->lockForUpdate()->first();
            if (! $state->active_generation || ! $state->ready) {
                return;
            }
            if ((int) $state->normalization_version !== TextNormalizer::VERSION) {
                DB::table(self::STATE)->where('id', 1)->update(['ready' => false]);

                return;
            }
            try {
                $this->write($terms, (int) $state->active_generation, $attributes);
                DB::table(self::DIRTY)->whereIn('term_id', $terms->pluck('id'))->delete();
            } catch (Throwable) {
                // The business change may remain valid; the whole index becomes unavailable.
                DB::table(self::STATE)->where('id', 1)->update(['ready' => false]);
            }
        });
    }

    /** Bounded, resumable build. No staged generation is visible before its atomic activation. */
    public function rebuild(): array
    {
        DB::transaction(function () {
            $state = DB::table(self::STATE)->where('id', 1)->lockForUpdate()->first();
            $resume = $state->build_generation && $state->build_mutation === $state->mutation_no && (int) $state->normalization_version === TextNormalizer::VERSION;
            DB::table(self::STATE)->where('id', 1)->update([
                'ready' => false,
                'build_generation' => $resume ? $state->build_generation : max($state->build_generation, $state->active_generation) + 1,
                'build_mutation' => $state->mutation_no,
                'cursor' => $resume ? $state->cursor : 0,
                'normalization_version' => TextNormalizer::VERSION,
            ]);
        });
        try {
            // Check every original even on cursor resume: a file from an already built
            // batch might have changed between attempts without a SQL mutation.
            $imports = ImportBatch::whereIn('id', Term::where('obsolete', false)->select('source_import_id'))->get();
            foreach ($imports as $import) {
                $path = $import->originalPath();
                if (! is_file($path) || @hash_file('sha256', $path) !== $import->sha256) {
                    throw new ApiProblem(503, 'search_index_unavailable');
                }
            }
            while (true) {
                $done = DB::transaction(function () {
                    $state = DB::table(self::STATE)->where('id', 1)->lockForUpdate()->first();
                    if (! $state->build_generation && $state->ready) {
                        return true;
                    }
                    if ($state->mutation_no !== $state->build_mutation) {
                        throw new ApiProblem(503, 'search_index_unavailable');
                    }
                    $terms = Term::with('sourceImport')->where('id', '>', $state->cursor)->orderBy('id')->limit(200)->get();
                    if ($terms->isEmpty()) {
                        DB::table(self::DIRTY)->delete();
                        DB::table(self::STATE)->where('id', 1)->update(['active_generation' => $state->build_generation, 'build_generation' => 0, 'cursor' => 0, 'ready' => true]);

                        return true;
                    }
                    $this->write($terms, (int) $state->build_generation);
                    DB::table(self::STATE)->where('id', 1)->update(['cursor' => $terms->last()->id]);

                    return false;
                });
                if ($done) {
                    break;
                }
            }
        } catch (Throwable) {
            throw new ApiProblem(503, 'search_index_unavailable');
        }
        $state = DB::table(self::STATE)->where('id', 1)->first();
        // Obsolete generations are safe to purge after activation. Searches hold a shared state lock.
        DB::transaction(function () {
            $state = DB::table(self::STATE)->where('id', 1)->lockForUpdate()->first();
            DB::table(self::TOKENS)->whereNotIn('generation', [$state->active_generation, $state->build_generation])->delete();
            DB::table(self::VALUES)->whereNotIn('generation', [$state->active_generation, $state->build_generation])->delete();
        });

        return ['generation' => (int) $state->active_generation, 'values' => DB::table(self::VALUES)->where('generation', $state->active_generation)->count()];
    }

    private function write(Collection $terms, int $generation, array $attributes = []): void
    {
        $ids = $terms->pluck('id');
        $oldValues = DB::table(self::VALUES)->where('generation', $generation)->whereIn('term_id', $ids)->select('id');
        DB::table(self::TOKENS)->whereIn('value_id', $oldValues)->delete();
        DB::table(self::VALUES)->where('generation', $generation)->whereIn('term_id', $ids)->delete();
        $rows = [];
        foreach ($terms as $term) {
            if ($term->obsolete || $term->consolidated_into_id !== null) {
                continue;
            }
            $attrs = $attributes[$term->id] ?? $this->source->attributes($term);
            foreach (FormatRegistry::get($term->type)->attributeNames as $attribute) {
                $source = $attrs[$attribute] ?? throw new \RuntimeException('Invalid source attributes.');
                $values = [['language' => $source['reference_language'] ?? '', 'origin' => 'reference', 'value' => $source['reference']]];
                foreach ($source['translations'] as $language => $value) {
                    $values[] = ['language' => $language, 'origin' => 'imported', 'value' => $value];
                }
                foreach (($term->validated[$attribute] ?? []) as $language => $value) {
                    $values[] = ['language' => $language, 'origin' => 'validated', 'value' => $value];
                }
                foreach ($values as $value) {
                    if (! in_array($value['language'], ['', 'de', 'fr', 'it', 'en'], true) || ! is_string($value['value'])) {
                        continue;
                    }
                    if ($value['language'] === '' && $value['origin'] !== 'reference') {
                        continue;
                    }
                    $literal = $this->normalizer->literal($value['value']);
                    if ($literal === '') {
                        continue;
                    }
                    $normalized = $this->normalizer->searchKey($value['value']);
                    $rows[] = [...$value, 'generation' => $generation, 'term_id' => $term->id, 'attribute' => $attribute,
                        'literal' => $literal, 'normalized' => $normalized, 'literal_hash' => hash('sha256', $literal), 'normalized_hash' => hash('sha256', $normalized),
                        'prefix' => mb_substr($normalized, 0, 191), 'normalized_length' => mb_strlen($normalized), 'revision_no' => $term->revision_no,
                        'source_hash' => $term->source_hash, 'normalization_version' => TextNormalizer::VERSION];
                }
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table(self::VALUES)->insert($chunk);
        }
        $tokens = [];
        foreach (DB::table(self::VALUES)->where('generation', $generation)->whereIn('term_id', $ids)->select('id', 'language', 'normalized')->get() as $value) {
            foreach ($this->normalizer->tokens($value->normalized) as $token) {
                $tokens[] = ['value_id' => $value->id, 'generation' => $generation, 'language' => $value->language, 'token_hash' => hash('sha256', $token)];
                if (count($tokens) === 300) {
                    DB::table(self::TOKENS)->insertOrIgnore($tokens);
                    $tokens = [];
                }
            }
        }
        if ($tokens) {
            DB::table(self::TOKENS)->insertOrIgnore($tokens);
        }
    }
}
