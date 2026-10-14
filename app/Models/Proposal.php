<?php

namespace App\Models;

use App\Services\CampaignService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Proposal extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Proposal $proposal) {
            if ($proposal->context_fingerprint === null && Schema::hasColumn('proposals', 'context_fingerprint')) {
                try {
                    $term = $proposal->term;
                    if ($term !== null) {
                        $proposal->context_fingerprint = app(CampaignService::class)->contextFingerprint($term, $proposal->attribute, $proposal->language);
                    }
                } catch (\Throwable) {
                    // Legacy/test records without accessible source stay conservatively unattached.
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['anomalies' => 'array', 'edit_history' => 'array', 'divergence' => 'boolean', 'claimed' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organisation::class, 'organization_id');
    }

    public function imports()
    {
        return $this->belongsToMany(ImportBatch::class, 'proposal_imports', 'proposal_id', 'import_id');
    }

    public function import()
    {
        return $this->belongsTo(ImportBatch::class, 'import_id');
    }
}
