<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Transactional SQL projection; application writes go through terms. */
class TermState extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['missing_languages' => 'integer', 'validated_languages' => 'integer',
            'review_languages' => 'integer', 'published_languages' => 'integer', 'obsolete' => 'boolean'];
    }

    public function scopeMatchingState(Builder $query, string $state, string $language, ?int $versionId = null): Builder
    {
        if ($state === 'obsolete') {
            return $query->where('obsolete', true);
        }
        $bit = ['de' => 1, 'fr' => 2, 'it' => 4, 'en' => 8][$language] ?? throw new InvalidArgumentException('Unsupported state language.');
        $column = ['missing' => 'missing_languages', 'validated' => 'validated_languages',
            'review' => 'review_languages', 'published' => 'published_languages'][$state] ?? throw new InvalidArgumentException('Unsupported projected state.');
        $query->whereRaw("($column & ?) <> 0", [$bit]);
        if ($state === 'published') {
            $publications = Publication::where('status', 'published')->select('id');
            if ($versionId !== null) {
                $publications->where('version_id', $versionId);
            }
            // Withdrawal changes availability immediately. It must not require
            // rewriting every term in an immutable publication's manifest.
            $query->whereIn('last_publication_id', $publications);
        }

        return $query;
    }
}
