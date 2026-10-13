<?php

namespace App\Models;

use App\Services\CommonScope;
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
        if (! $user->active || $user->is_technical) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->canManage() || $user->canValidateAny()) {
            return $query;
        }

        return CommonScope::forOrganization($query, $user->organization_id, 'translation_campaigns.organization_id')
            ->whereDoesntHave('participations', fn ($q) => $q->whereNotIn('term_id', Term::visibleTo($user)->select('terms.id')))
            ->whereDoesntHave('groups', fn ($q) => $q->whereNotIn('term_groups.id', TermGroup::visibleTo($user)->select('term_groups.id')))
            ->whereDoesntHave('assignees', fn ($q) => CommonScope::outsideOrganization($q, (int) $user->organization_id, 'users.organization_id'));
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
