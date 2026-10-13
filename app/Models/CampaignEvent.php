<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function participation()
    {
        return $this->belongsTo(CampaignParticipation::class, 'campaign_participation_id');
    }

    public function campaign()
    {
        return $this->belongsTo(TranslationCampaign::class, 'translation_campaign_id');
    }
}
