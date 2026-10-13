<?php

namespace App\Models;

use App\Services\CommonScope;
use Illuminate\Database\Eloquent\Model;

class TermGroup extends Model
{
    public const COLORS = ['#2266a4', '#32634d', '#825f20', '#53677b', '#102348', '#a12f2f'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function terms()
    {
        return $this->hasMany(Term::class);
    }

    public function campaigns()
    {
        return $this->belongsToMany(TranslationCampaign::class, 'campaign_groups');
    }

    public function scopeVisibleTo($query, User $user)
    {
        if (! $user->active || $user->is_technical) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->canManage() || $user->canValidateAny()) {
            return $query;
        }

        return CommonScope::forOrganization($query, $user->organization_id, 'term_groups.organization_id')
            ->whereDoesntHave('terms', fn ($q) => $q->whereNotIn('terms.id', Term::visibleTo($user)->select('terms.id')));
    }
}
