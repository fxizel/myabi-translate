<?php

namespace App\Models;

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
        return $user->canSeeCatalogue() ? $query : $query->whereRaw('1 = 0');
    }
}
