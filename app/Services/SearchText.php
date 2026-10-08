<?php

namespace App\Services;

use App\Models\Term;
use Illuminate\Support\Facades\DB;

/** Transactional search projection; never changes a term's business revision. */
class SearchText
{
    public function compose(Term $term, array $attributes, iterable $proposals = []): string
    {
        $values = [$term->label, $term->context];
        foreach ($attributes as $attribute) {
            $values[] = $attribute['reference'];
            foreach ($attribute['translations'] as $value) {
                $values[] = $value;
            }
        }
        foreach ($term->validated as $translations) {
            foreach ($translations as $value) {
                $values[] = $value;
            }
        }
        foreach ($proposals as $value) {
            $values[] = $value;
        }

        return implode("\n", array_unique(array_filter($values, fn ($value) => $value !== null && $value !== '')));
    }

    public function sync(Term $term): void
    {
        $this->syncMany(collect([$term]));
    }

    /** Caller supplies at most one bounded import chunk of terms. */
    public function syncMany($terms, array $attributes = []): void
    {
        if ($terms->isEmpty()) {
            return;
        }
        $values = DB::table('proposals')->whereIn('term_id', $terms->pluck('id'))->select('term_id', 'value')->get()->groupBy('term_id');
        foreach ($terms as $term) {
            $text = $this->compose($term, $attributes[$term->id] ?? $term->attributesForDisplay(), $values->get($term->id, collect())->pluck('value'));
            if ($text === $term->search_text) {
                continue;
            }
            DB::table('terms')->where('id', $term->id)->update(['search_text' => $text]);
            $term->search_text = $text;
        }
    }
}
