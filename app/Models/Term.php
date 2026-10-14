<?php

namespace App\Models;

use App\Services\CampaignService;
use App\Services\SourceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Term extends Model
{
    protected $guarded = [];

    /** Source/decision workers synchronize after their proposals have been persisted. */
    public bool $campaignSyncDeferred = false;

    protected static function booted(): void
    {
        // Consolidated records retain their historical IDs and revisions, but
        // only the canonical term participates in the current catalogue.
        static::addGlobalScope('current', function ($query) {
            if (Schema::hasColumn('terms', 'consolidated_into_id')) {
                $query->whereNull($query->getModel()->qualifyColumn('consolidated_into_id'));
            }
        });
        static::updated(function (Term $term) {
            if ($term->wasChanged(['validated', 'source_import_id', 'source_offset', 'source_length', 'context', 'key', 'source_hash', 'obsolete'])
                && Schema::hasTable('campaign_participations')) {
                app(CampaignService::class)->referenceChanged($term);
                if (! $term->campaignSyncDeferred) {
                    app(CampaignService::class)->synchronizeForTerm($term->fresh(), User::find($term->updated_by));
                }
            }
        });
    }

    public function group()
    {
        return $this->belongsTo(TermGroup::class, 'term_group_id');
    }

    public function campaignParticipations()
    {
        return $this->hasMany(CampaignParticipation::class);
    }

    protected function casts(): array
    {
        return ['key' => 'array', 'validated' => 'array', 'review_needed' => 'array', 'obsolete' => 'boolean'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function sourceImport()
    {
        return $this->belongsTo(ImportBatch::class, 'source_import_id');
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function revisions()
    {
        return $this->hasMany(Revision::class);
    }

    public function lastPublication()
    {
        return $this->belongsTo(Publication::class, 'last_publication_id');
    }

    public function attributesForDisplay(): array
    {
        return app(SourceRecord::class)->attributes($this);
    }

    public function state(string $attribute, string $language): string
    {
        if ($this->obsolete) {
            return 'obsolete';
        }
        if (data_get($this->review_needed, "$attribute.$language")) {
            return 'review';
        }
        if (data_get($this->validated, "$attribute.$language") === null) {
            return 'missing';
        }
        if ($this->last_published_revision === $this->revision_no && $this->lastPublication?->status === 'published') {
            return 'published';
        }

        return 'validated';
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $user->canSeeCatalogue() ? $query : $query->whereRaw('1 = 0');
    }

    public function scopeCommon($query)
    {
        return $query;
    }
}
