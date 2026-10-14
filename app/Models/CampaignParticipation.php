<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignParticipation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'direct' => 'boolean', 'confirmed_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function campaign()
    {
        return $this->belongsTo(TranslationCampaign::class, 'translation_campaign_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class)->withoutGlobalScope('current');
    }

    public function trackedProposal()
    {
        return $this->belongsTo(Proposal::class, 'tracked_proposal_id');
    }

    public function events()
    {
        return $this->hasMany(CampaignEvent::class);
    }
}
