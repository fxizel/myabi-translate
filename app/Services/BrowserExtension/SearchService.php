<?php

namespace App\Services\BrowserExtension;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SearchService
{
    public function __construct(private TextNormalizer $normalizer) {}

    public function search(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            // Writers' transactional triggers acquire the same row exclusively. All reads below
            // consequently use one complete generation, including the metadata and pending counts.
            $state = DB::table(SearchProjection::STATE)->where('id', 1)->sharedLock()->first();
            if (! $state?->ready || ! $state->active_generation || (int) $state->normalization_version !== TextNormalizer::VERSION || DB::table(SearchProjection::DIRTY)->exists()) {
                throw new ApiProblem(503, 'search_index_unavailable');
            }
            if (! $user->canSeeCatalogue()) {
                throw new ApiProblem(403, 'permission_denied');
            }
            $language = $data['searchLanguage'] ?? strtolower(explode('-', str_replace('_', '-', $data['lang'] ?? ''))[0]);
            if (! in_array($language, ['de', 'fr', 'it', 'en'], true)) {
                throw new ApiProblem(422, 'search_language_required');
            }
            $literal = $this->normalizer->literal($data['text']);
            $key = $this->normalizer->searchKey($data['text']);
            $truncated = (bool) ($data['textTruncated'] ?? false);
            $limit = (int) ($data['maxResults'] ?? 5);
            $alternatives = array_map(fn ($value) => $this->normalizer->literal($value), $data['alternativeTexts'] ?? []);
            $tokens = $this->normalizer->tokens($key);
            $hint = $data['dataAttributes']['data-i18n-key'] ?? null;
            $context = $this->normalizer->searchKey(implode(' ', array_filter([$data['surroundingText'] ?? '', $data['parentText'] ?? '', $data['sectionTitle'] ?? '', $data['dialogTitle'] ?? '', $data['tableColumn'] ?? ''])));
            $base = $this->base((int) $state->active_generation, $language);
            $exactQuery = (clone $base)->where('v.language', $language)->where('v.literal_hash', hash('sha256', $literal));
            $this->index($exactQuery, 'be_search_literal');
            $exactQuery->whereRaw(in_array(DB::getDriverName(), ['mysql', 'mariadb']) ? 'BINARY v.literal = BINARY ?' : 'v.literal = ? COLLATE BINARY', [$literal]);
            $exactCells = $exactQuery->select('v.term_id', 'v.attribute')->distinct();
            $exactCount = $truncated ? 0 : DB::query()->fromSub($exactCells, 'exact_cells')->count();
            $best = [];
            $overflow = false;
            // Separate index lookups avoid an OR predicate scanning the complete language corpus.
            $literalRows = (clone $base)->where('v.literal_hash', hash('sha256', $literal))->select('v.*', 't.type as term_type', 't.key as term_key', 't.context as term_context');
            $this->index($literalRows, 'be_search_literal');
            $normalizedRows = (clone $base)->where('v.normalized_hash', hash('sha256', $key))->select('v.*', 't.type as term_type', 't.key as term_key', 't.context as term_context');
            $this->index($normalizedRows, 'be_search_normalized');
            $equalities = $literalRows->union($normalizedRows);
            if ($alternatives) {
                $alternativeRows = (clone $base)->whereIn('v.literal_hash', array_map(fn ($value) => hash('sha256', $value), $alternatives))
                    ->select('v.*', 't.type as term_type', 't.key as term_key', 't.context as term_context');
                $this->index($alternativeRows, 'be_search_literal');
                $equalities->union($alternativeRows);
            }
            foreach ($equalities->orderBy('id')->lazy(500) as $value) {
                $this->consider($best, $overflow, $value, $literal, $key, $tokens, $alternatives, $truncated, $language, $hint, $context, $limit + 1);
            }
            $complete = ! $truncated;
            if (mb_strlen($key) >= 3) {
                $cells = $this->fuzzyCells((int) $state->active_generation, $language, $key, $tokens);
                if ($cells->count() > 200) {
                    $complete = false;
                    $overflow = true;
                    $cells = $cells->take(200);
                }
                if ($cells->isNotEmpty()) {
                    $values = (clone $base)->where(function ($query) use ($cells) {
                        foreach ($cells as $cell) {
                            $query->orWhere(fn ($cellQuery) => $cellQuery->where('v.term_id', $cell->term_id)->where('v.attribute', $cell->attribute));
                        }
                    })->select('v.*', 't.type as term_type', 't.key as term_key', 't.context as term_context');
                    $this->index($values, 'be_search_cell');
                    $values = $values->get();
                    foreach ($values as $value) {
                        $this->consider($best, $overflow, $value, $literal, $key, $tokens, $alternatives, $truncated, $language, $hint, $context, $limit + 1);
                    }
                }
            }
            $candidates = array_values($best);
            usort($candidates, fn ($a, $b) => $this->compare($a, $b));
            $strong = $complete && $exactCount === 1 && ($candidates[0]['exact'] ?? false) && (($candidates[0]['score'] - ($candidates[1]['score'] ?? 0)) >= 0.15 - 1e-9);
            $matchState = $exactCount ? ($strong ? 'strong' : 'multiple') : ($candidates || ! $complete ? 'noExact' : 'none');
            $shown = array_slice($candidates, 0, $limit);
            $results = $this->hydrate($shown, (int) $state->active_generation, $language);

            return ['contractVersion' => 2, 'query' => $literal, 'matchState' => $matchState, 'exactCandidateCount' => $exactCount,
                'hasMore' => $overflow || count($candidates) > $limit, 'candidateSetComplete' => $complete, 'feedbackAllowed' => false, 'results' => $results];
        });
    }

    private function base(int $generation, string $language): Builder
    {
        return DB::table(SearchProjection::VALUES.' as v')->join('terms as t', 't.id', '=', 'v.term_id')
            ->where('v.generation', $generation)->whereIn('v.language', [$language, ''])->where('t.obsolete', false)->whereNull('t.consolidated_into_id');
    }

    /** At most three eligible origins per cell: 603 rows suffice to reveal 201 distinct cells. */
    private function fuzzyCells(int $generation, string $language, string $key, array $tokens): Collection
    {
        $cells = [];
        $collect = function ($rows) use (&$cells) {
            foreach ($rows as $row) {
                $cells[$row->term_id.'|'.$row->attribute] = $row;
                if (count($cells) === 201) {
                    return true;
                }
            }

            return false;
        };
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($key, 0, 191));
        foreach (['', $language] as $valueLanguage) {
            $prefix = $this->base($generation, $language)->where('v.language', $valueLanguage)->whereRaw("v.prefix LIKE ? ESCAPE '!'", [$escaped.'%'])
                ->where('v.normalized_length', '<=', mb_strlen($key) * 2)->orderBy('v.prefix')->orderBy('v.id')->limit(603);
            $this->index($prefix, 'be_search_prefix');
            $this->seed($prefix, ['v.term_id', 'v.attribute']);
            if ($collect($prefix->get())) {
                return collect(array_values($cells));
            }
        }
        foreach ($tokens as $token) {
            foreach (['', $language] as $valueLanguage) {
                $query = DB::table(SearchProjection::TOKENS.' as tok')->join(SearchProjection::VALUES.' as v', 'v.id', '=', 'tok.value_id')
                    ->join('terms as t', 't.id', '=', 'v.term_id')->where('tok.generation', $generation)->where('tok.language', $valueLanguage)
                    ->where('tok.token_hash', hash('sha256', $token))->where('v.generation', $generation)->where('t.obsolete', false)->whereNull('t.consolidated_into_id')
                    ->orderBy('tok.value_id')->limit(603);
                $this->index($query, 'be_search_token_lookup');
                $this->seed($query, ['v.term_id', 'v.attribute']);
                if ($collect($query->get())) {
                    return collect(array_values($cells));
                }
            }
        }

        return collect(array_values($cells));
    }

    private function seed(Builder $query, array $columns): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            $query->selectRaw('STRAIGHT_JOIN '.implode(', ', $columns));
        } else {
            $query->select($columns);
        }
    }

    private function index(Builder $query, string $name): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            $query->forceIndex($name);
        }
    }

    private function consider(array &$best, bool &$overflow, object $value, string $literal, string $key, array $tokens, array $alternatives, bool $truncated, string $language, ?string $hint, string $context, int $keep): void
    {
        if ((int) $value->normalization_version !== TextNormalizer::VERSION) {
            throw new ApiProblem(503, 'search_index_unavailable');
        }
        $exact = ! $truncated && $value->language === $language && $value->literal === $literal;
        $score = 0.0;
        $reason = 'approximate';
        if ($exact) {
            $score = 1.0;
            $reason = 'literal';
        } elseif ($value->normalized === $key) {
            $score = 0.88;
            $reason = 'normalized';
        } elseif (mb_strlen($key) >= 3 && in_array($value->literal, $alternatives, true)) {
            $score = 0.82;
            $reason = 'alternative';
        } elseif (mb_strlen($key) >= 3) {
            $valueTokens = $this->normalizer->tokens($value->normalized);
            $union = count(array_unique([...$tokens, ...$valueTokens]));
            $jaccard = $union ? count(array_intersect($tokens, $valueTokens)) / $union : 0;
            if ($jaccard >= 0.5) {
                $score = 0.6 + 0.2 * $jaccard;
            }
            if (str_starts_with($value->normalized, $key)) {
                $coverage = mb_strlen($key) / max(1, mb_strlen($value->normalized));
                if ($coverage >= 0.5) {
                    $score = max($score, 0.6 + 0.2 * $coverage);
                }
            }
        }
        if (! $score) {
            return;
        }
        if (! $exact && $context !== '' && $value->term_context !== '' && str_contains($context, $this->normalizer->searchKey($value->term_context))) {
            $score = min(0.89, $score + 0.01);
        }
        $parts = json_decode($value->term_key, true, 32, JSON_THROW_ON_ERROR);
        $candidate = ['termId' => (int) $value->term_id, 'attribute' => $value->attribute, 'keyParts' => $parts, 'type' => $value->term_type,
            'score' => $score, 'exact' => $exact, 'matchReason' => $reason, 'matchedText' => $value->value, 'valueOrigin' => $value->origin,
            'hint' => count($parts) === 1 && $parts[0] === $hint, 'revision' => (int) $value->revision_no, 'sourceHash' => $value->source_hash];
        $cell = $value->term_id.'|'.$value->attribute;
        if (isset($best[$cell]) && $this->compare($best[$cell], $candidate, true) <= 0) {
            return;
        }
        $best[$cell] = $candidate;
        if (count($best) > $keep) {
            uasort($best, fn ($a, $b) => $this->compare($a, $b));
            array_pop($best);
            $overflow = true;
        }
    }

    private function compare(array $a, array $b, bool $sameCell = false): int
    {
        $score = $b['score'] <=> $a['score'];
        if ($score) {
            return $score;
        }
        if ($sameCell) {
            return ['validated' => 0, 'imported' => 1, 'reference' => 2][$a['valueOrigin']] <=> ['validated' => 0, 'imported' => 1, 'reference' => 2][$b['valueOrigin']];
        }
        $hint = $b['hint'] <=> $a['hint'];
        if ($hint) {
            return $hint;
        }
        $type = strcmp($a['type'], $b['type']);
        if ($type) {
            return $type;
        }
        foreach ($a['keyParts'] as $i => $part) {
            $key = strcmp($part, $b['keyParts'][$i] ?? '');
            if ($key) {
                return $key;
            }
        }
        $attrs = FormatRegistry::get($a['type'])->attributeNames;

        return (array_search($a['attribute'], $attrs, true) <=> array_search($b['attribute'], $attrs, true)) ?: ($a['termId'] <=> $b['termId']);
    }

    private function hydrate(array $candidates, int $generation, string $language): array
    {
        $ids = array_unique(array_column($candidates, 'termId'));
        $terms = Term::with('lastPublication')->whereIn('id', $ids)->get()->keyBy('id');
        $valueQuery = DB::table(SearchProjection::VALUES)->where('generation', $generation)->whereIn('term_id', $ids);
        $this->index($valueQuery, 'be_search_cell');
        $values = $valueQuery->get()->groupBy(fn ($value) => $value->term_id.'|'.$value->attribute);
        $pending = Proposal::whereIn('term_id', $ids)->where('language', $language)->where('status', 'pending')
            ->select('term_id', 'attribute')->selectRaw('COUNT(*) as aggregate')->groupBy('term_id', 'attribute')->get()->keyBy(fn ($proposal) => $proposal->term_id.'|'.$proposal->attribute);
        $results = [];
        foreach ($candidates as $candidate) {
            $term = $terms->get($candidate['termId']);
            if (! $term || $term->obsolete || (int) $term->revision_no !== $candidate['revision'] || $term->source_hash !== $candidate['sourceHash']) {
                throw new ApiProblem(503, 'search_index_unavailable');
            }
            $cell = $candidate['termId'].'|'.$candidate['attribute'];
            $origins = $values->get($cell, collect());
            $reference = $origins->firstWhere('origin', 'reference');
            $imported = $origins->first(fn ($value) => $value->origin === 'imported' && $value->language === $language);
            $texts = ['sourceText' => $reference?->value ?? '', 'currentTranslation' => data_get($term->validated, $candidate['attribute'].'.'.$language) ?? '',
                'importedTranslation' => $imported?->value ?? '', 'matchedText' => $candidate['matchedText']];
            $preview = false;
            foreach ($texts as &$text) {
                if (mb_strlen($text) > 1000) {
                    $text = mb_substr($text, 0, 999).'…';
                    $preview = true;
                }
            }
            unset($text);
            $identity = json_encode([$term->id, $candidate['attribute'], $language], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $results[] = ['id' => 'cell2.'.rtrim(strtr(base64_encode($identity), '+/', '-_'), '='), 'termId' => $term->id, 'type' => $term->type, 'key' => $term->label,
                'keyParts' => $term->key, 'attribute' => $candidate['attribute'], 'targetLanguage' => $language, 'sourceLanguage' => $reference?->language ?? '',
                ...$texts, 'proposedTranslation' => '', 'pendingCount' => (int) ($pending->get($cell)?->aggregate ?? 0), 'valueOrigin' => $candidate['valueOrigin'],
                'matchReason' => $candidate['matchReason'], 'module' => '', 'status' => $term->state($candidate['attribute'], $language), 'score' => $candidate['score'],
                'exact' => $candidate['exact'], 'previewTruncated' => $preview,
                'translationUrl' => rtrim(config('app.url'), '/').route('terms.show', ['term' => $term->id, 'language' => $language], false)];
        }

        return $results;
    }
}
