<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationCampaign extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deadline' => 'date', 'archived_at' => 'datetime', 'first_admitted_at' => 'datetime', 'admission_report' => 'array'];
    }

    public function groups()
    {
        return $this->belongsToMany(TermGroup::class, 'campaign_groups');
    }

    public function assignees()
    {
        return $this->belongsToMany(User::class, 'campaign_assignees');
    }

    public function participations()
    {
        return $this->hasMany(CampaignParticipation::class);
    }

    public function events()
    {
        return $this->hasMany(CampaignEvent::class);
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $user->canSeeCatalogue() ? $query : $query->whereRaw('1 = 0');
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'completed' && $this->deadline !== null && $this->deadline->toDateString() < now()->timezone('Europe/Zurich')->toDateString();
    }

    public function metrics(): array
    {
        $counts = $this->participations()->where('active', true)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $result = array_fill_keys(['to_process', 'to_arbitrate', 'proposal_sent', 'confirmed'], 0);
        foreach ($counts as $status => $count) {
            $result[$status] = (int) $count;
        }
        $result['total'] = array_sum($result);
        $percent = fn (int $count) => $result['total'] === 0 ? null : ($count === $result['total'] ? 100.0 : min(99.9, round($count / $result['total'] * 100, 1)));
        $result['work_progress'] = $percent($result['proposal_sent'] + $result['confirmed']);
        $result['confirmed_progress'] = $percent($result['confirmed']);

        return $result;
    }
}
