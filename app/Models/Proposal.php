<?php

namespace App\Models;

use App\Services\CampaignService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class Proposal extends Model
{
    public const ARBITRATION = 'arbitration';

    public const OPEN_STATUSES = ['pending', self::ARBITRATION];

    protected $guarded = [];

    protected $hidden = ['screenshot_path'];

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
        return ['anomalies' => 'array', 'edit_history' => 'array', 'divergence' => 'boolean', 'claimed' => 'boolean', 'decided_at' => 'datetime',
            'screenshot_bytes' => 'integer', 'screenshot_width' => 'integer', 'screenshot_height' => 'integer'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function scopeInLanguage(Builder $query, string $language): Builder
    {
        return $query->where('proposals.language', $language)
            ->whereRaw('HEX(proposals.language) = ?', [strtoupper(bin2hex($language))]);
    }

    public static function openAttributesForTerms(array $termIds, string $language): Collection
    {
        return static::whereIn('term_id', $termIds)->inLanguage($language)->whereIn('status', self::OPEN_STATUSES)
            ->select('term_id', 'attribute', 'status')->groupBy('term_id', 'attribute', 'status')
            ->groupByRaw('HEX(attribute)')->toBase()->get()->groupBy('term_id')
            ->map(fn ($rows) => $rows->groupBy('attribute')->map(fn ($cell) => $cell->contains('status', self::ARBITRATION) ? self::ARBITRATION : 'pending'));
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
