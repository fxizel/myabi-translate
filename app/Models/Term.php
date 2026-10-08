<?php

namespace App\Models;

use App\Services\CommonScope;
use App\Services\SourceRecord;
use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['key' => 'array', 'validated' => 'array', 'review_needed' => 'array', 'obsolete' => 'boolean', 'scope_unconfirmed' => 'boolean', 'scope_overridden' => 'boolean'];
    }

    public function organization()
    {
        return $this->belongsTo(Organisation::class, 'organization_id');
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
        if ($user->canManage() || $user->canValidateAny()) {
            return $query;
        }

        return CommonScope::forOrganization($query, $user->organization_id);
    }

    public function scopeCommon($query)
    {
        return CommonScope::query($query);
    }
}
