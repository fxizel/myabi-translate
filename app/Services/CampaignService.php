<?php

namespace App\Services;

use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Models\CampaignEvent;
use App\Models\CampaignParticipation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Models\TranslationCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public array $lastAdmissionReport = [];

    private array $verifiedSources = [];

    public function __construct(private OperationLock $lock, private SourceRecord $source) {}

    public function primaryAttribute(Term $term): string
    {
        return FormatRegistry::get($term->type)->attributeNames[0];
    }

    public function canReview(User $user, TranslationCampaign $campaign): bool
    {
        return $user->canValidate($campaign->language) && TranslationCampaign::visibleTo($user)->whereKey($campaign->id)->exists();
    }

    public function canWork(User $user, TranslationCampaign $campaign, ?Term $term = null): bool
    {
        return $campaign->status === 'in_progress' && $campaign->archived_at === null
            && $campaign->assignees()->whereKey($user->id)->exists()
            && TranslationCampaign::visibleTo($user)->whereKey($campaign->id)->exists()
            && ($term === null ? $user->canTranslate($campaign->language, $user->organization_id) : $user->canTranslate($campaign->language, $term->organization_id));
    }

    public function create(User $user, array $data): TranslationCampaign
    {
        abort_unless($user->canManage(), 403);

        return $this->write(function () use ($user, $data) {
            $campaign = TranslationCampaign::create($this->values($data) + ['organization_id' => $user->organization_id, 'created_by' => $user->id, 'updated_by' => $user->id]);
            $this->assign($campaign, $data['assignee_ids'] ?? []);
            $this->groups($campaign, $data['group_ids'] ?? []);
            $this->lastAdmissionReport = $this->synchronizeLocked($campaign, $user);
            $this->lastAdmissionReport = $this->mergeReports($this->lastAdmissionReport, $this->addDirectLocked($campaign, $data['term_ids'] ?? [], $user));
            $campaign->update(['admission_report' => $this->lastAdmissionReport]);

            return $campaign->fresh();
        });
    }

    public function update(User $user, TranslationCampaign $campaign, array $data): TranslationCampaign
    {
        abort_unless($user->canManage(), 403);

        return $this->write(function () use ($user, $campaign, $data) {
            $campaign = $this->locked($campaign);
            $values = $this->values($data + $campaign->only(['title', 'description', 'objective', 'language', 'deadline']));
            if (($values['language'] !== $campaign->language || $values['objective'] !== $campaign->objective) && ($campaign->first_admitted_at !== null || $campaign->status !== 'todo')) {
                $this->invalid('language', __('campaigns.errors.locked'));
            }
            $campaign->update($values + ['updated_by' => $user->id]);
            if (array_key_exists('assignee_ids', $data)) {
                $this->assign($campaign, $data['assignee_ids']);
            }
            if (array_key_exists('group_ids', $data)) {
                $this->mutable($campaign);
                $this->groups($campaign, $data['group_ids']);
            }
            $this->lastAdmissionReport = $this->report();
            if (array_key_exists('term_ids', $data)) {
                $this->mutable($campaign);
                $this->lastAdmissionReport = $this->addDirectLocked($campaign, $data['term_ids'], $user, false);
            }
            if ($campaign->status !== 'completed') {
                $this->lastAdmissionReport = $this->mergeReports($this->lastAdmissionReport, $this->synchronizeLocked($campaign, $user));
            }
            if ($campaign->status !== 'completed') {
                $campaign->update(['admission_report' => $this->lastAdmissionReport]);
            }

            return $campaign->fresh();
        });
    }

    public function setGroups(User $user, TranslationCampaign $campaign, array $groupIds): array
    {
        abort_unless($user->canManage(), 403);

        return $this->write(function () use ($user, $campaign, $groupIds) {
            $campaign = $this->locked($campaign);
            $this->mutable($campaign);
            $this->groups($campaign, $groupIds);

            return $this->lastAdmissionReport = $this->synchronizeLocked($campaign, $user);
        });
    }

    public function addDirectTerms(User $user, TranslationCampaign $campaign, array $termIds): array
    {
        abort_unless($user->canManage(), 403);

        return $this->write(function () use ($user, $campaign, $termIds) {
            $campaign = $this->locked($campaign);
            $this->mutable($campaign);

            return $this->lastAdmissionReport = $this->addDirectLocked($campaign, $termIds, $user);
        });
    }

    public function removeDirectTerm(User $user, TranslationCampaign $campaign, Term $term): void
    {
        abort_unless($user->canManage(), 403);
        $this->write(function () use ($user, $campaign, $term) {
            $campaign = $this->locked($campaign);
            $this->mutable($campaign);
            $p = $this->participation($campaign, $term, false);
            $p->update(['direct' => false]);
            $this->event($campaign, $p, 'direct_removed', $user);
            $this->synchronizeLocked($campaign, $user);
        });
    }

    public function start(User $user, TranslationCampaign $campaign): void
    {
        abort_unless($user->canManage(), 403);
        $this->write(function () use ($user, $campaign) {
            $campaign = $this->locked($campaign);
            abort_unless($campaign->status === 'todo' && $campaign->archived_at === null, 422);
            $this->synchronizeLocked($campaign, $user);
            if (! $campaign->participations()->where('active', true)->exists() || ! $campaign->assignees()->where('active', true)->where('is_technical', false)->exists()) {
                $this->invalid('campaign', __('campaigns.errors.start_requires'));
            }
            $campaign->update(['status' => 'in_progress', 'updated_by' => $user->id]);
            $this->event($campaign, null, 'started', $user);
            $this->complete($campaign);
        });
    }

    public function propose(User $user, TranslationCampaign $campaign, Term $term, string $value, ?int $expected = null): Proposal
    {
        return $this->write(function () use ($user, $campaign, $term, $value, $expected) {
            [$campaign, $term, $p] = $this->work($user, $campaign, $term);
            abort_if($p->status === 'to_arbitrate', 422);
            $this->assertSource($term, $campaign->language);
            if ($this->pertinentPending($term, $campaign->language)->isNotEmpty()) {
                $this->invalid('proposal', __('campaigns.errors.existing_pending'));
            }
            $proposal = app(WorkflowService::class)->propose($user, $term, $p->attribute, $campaign->language, $value, $expected);
            $this->track($campaign, $p, $proposal, $user, 'proposal_created');

            return $proposal;
        });
    }

    public function attachProposal(User $user, TranslationCampaign $campaign, Term $term, Proposal $proposal): void
    {
        $this->write(function () use ($user, $campaign, $term, $proposal) {
            [$campaign, $term, $p] = $this->work($user, $campaign, $term);
            $pending = $this->pertinentPending($term, $campaign->language);
            if ($p->status === 'to_arbitrate' || $pending->count() > 1) {
                $this->invalid('proposal', __('campaigns.errors.competing'));
            }
            abort_unless($pending->contains('id', $proposal->id), 422);
            $this->track($campaign, $p, $proposal, $user, 'proposal_attached');
        });
    }

    public function confirm(User $user, TranslationCampaign $campaign, Term $term, ?int $expected = null): void
    {
        $this->write(function () use ($user, $campaign, $term, $expected) {
            [$campaign, $term, $p] = $this->work($user, $campaign, $term);
            abort_if($p->status === 'to_arbitrate', 422);
            if ($expected !== null && $term->lock_version !== $expected) {
                $this->invalid('version', __('ui.stale_change'));
            }
            $this->confirmLocked($campaign, $term, $p, $user, 'confirmed_without_change');
        });
    }

    public function requestArbitration(User $user, TranslationCampaign $campaign, Term $term, string $reason): void
    {
        $this->reason($reason);
        $this->write(function () use ($user, $campaign, $term, $reason) {
            [$campaign, $term, $p] = $this->work($user, $campaign, $term);
            $previous = $p->tracked_proposal_id;
            $p->update($this->reset() + ['status' => 'to_arbitrate', 'reason' => $reason]);
            $this->event($campaign, $p, 'arbitration_requested', $user, $reason, ['previous_proposal_id' => $previous]);
        });
    }

    public function resolveArbitration(User $user, TranslationCampaign $campaign, Term $term, string $outcome, string $reason, ?int $proposalId = null): void
    {
        $this->reason($reason);
        $this->write(function () use ($user, $campaign, $term, $outcome, $reason, $proposalId) {
            $campaign = $this->locked($campaign);
            abort_unless($this->canReview($user, $campaign), 403);
            $this->mutable($campaign);
            abort_unless($campaign->status === 'in_progress', 422);
            $term = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
            $p = $this->participation($campaign, $term);
            abort_unless($p->status === 'to_arbitrate', 422);
            if ($outcome === 'to_process') {
                $p->update($this->reset() + ['status' => 'to_process', 'reason' => $reason]);
            } elseif ($outcome === 'proposal_sent') {
                $proposal = $this->pertinentPending($term, $campaign->language)->firstWhere('id', $proposalId);
                abort_unless($proposal, 422);
                $this->track($campaign, $p, $proposal, $user, 'arbitration_proposal_selected', $reason);
            } elseif ($outcome === 'confirmed') {
                $this->confirmLocked($campaign, $term, $p, $user, 'confirmed_without_change', $reason);
            } else {
                abort(422);
            }
            $this->event($campaign, $p, 'arbitration_resolved', $user, $reason, ['outcome' => $outcome, 'proposal_id' => $proposalId]);
        });
    }

    public function reexamine(User $user, TranslationCampaign $campaign, Term $term, string $reason): void
    {
        $this->reason($reason);
        $this->write(function () use ($user, $campaign, $term, $reason) {
            $campaign = $this->locked($campaign);
            abort_unless($this->canReview($user, $campaign), 403);
            $term = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
            $p = $this->participation($campaign, $term, false);
            abort_unless($p->status === 'confirmed', 422);
            // Keep the requested term before synchronizing a newly reopened campaign.
            if (! $p->direct && ! $campaign->groups()->whereKey($term->term_group_id)->exists()) {
                $p->update(['direct' => true]);
                $this->event($campaign, $p, 'direct_added_for_reexamination', $user, $reason);
            }
            $p->update($this->reset() + ['active' => true, 'removed_at' => null, 'status' => 'to_process', 'reason' => $reason]);
            if ($campaign->status === 'completed') {
                $campaign->update(['status' => 'in_progress', 'archived_at' => null, 'updated_by' => $user->id]);
                $this->event($campaign, null, 'reopened', $user, $reason);
            }
            $this->event($campaign, $p, 'reexamination_requested', $user, $reason);
            $this->synchronizeLocked($campaign, $user);
        });
    }

    public function archive(User $user, TranslationCampaign $campaign): void
    {
        $this->archiveState($user, $campaign, true);
    }

    public function unarchive(User $user, TranslationCampaign $campaign): void
    {
        $this->archiveState($user, $campaign, false);
    }

    private function archiveState(User $user, TranslationCampaign $campaign, bool $archive): void
    {
        abort_unless($user->canManage(), 403);
        $this->write(function () use ($user, $campaign, $archive) {
            $campaign = $this->locked($campaign);
            abort_unless($campaign->status === 'completed', 422);
            $campaign->update(['archived_at' => $archive ? now() : null, 'updated_by' => $user->id]);
            $this->event($campaign, null, $archive ? 'archived' : 'unarchived', $user);
        });
    }

    public function delete(User $user, TranslationCampaign $campaign): void
    {
        abort_unless($user->canManage(), 403);
        $this->write(function () use ($campaign) {
            $campaign = $this->locked($campaign);
            abort_if($campaign->participations()->exists() || $campaign->events()->exists() || $campaign->first_admitted_at !== null, 422);
            $campaign->delete();
        });
    }

    /** Internal synchronization: callers hold the operation lock and transaction. */
    public function synchronizeForTerm(Term $term, ?User $actor = null): void
    {
        TranslationCampaign::where('status', '!=', 'completed')->where(fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereKey($term->term_group_id))->orWhereHas('participations', fn ($p) => $p->where('term_id', $term->id)))
            ->orderBy('id')->get()->each(function ($campaign) use ($term, $actor) {
                $campaign = $this->locked($campaign);
                $report = $this->report();
                $belongs = $campaign->groups()->whereKey($term->term_group_id)->exists();
                if ($belongs) {
                    $this->admit($campaign, $term, false, $actor, $report);
                }
                $p = $campaign->participations()->where('term_id', $term->id)->lockForUpdate()->first();
                if ($p?->active && ! $p->direct && ! $belongs) {
                    $previous = $p->tracked_proposal_id;
                    $p->update(['active' => false, 'removed_at' => now(), 'tracked_proposal_id' => null]);
                    $this->event($campaign, $p, 'removed', $actor, null, ['previous_proposal_id' => $previous]);
                    $report['removed']++;
                }
                $this->complete($campaign);
                if ($report['admitted'] + $report['returned'] + $report['removed'] + array_sum($report['excluded']) > 0) {
                    $campaign->update(['admission_report' => $report]);
                }
            });
    }

    public function synchronize(User $user, TranslationCampaign $campaign): array
    {
        abort_unless($user->canManage(), 403);

        return $this->write(fn () => $this->synchronizeLocked($this->locked($campaign), $user));
    }

    private function synchronizeLocked(TranslationCampaign $campaign, ?User $actor): array
    {
        $report = $this->report();
        if ($campaign->status === 'completed') {
            return $report;
        }
        $groupIds = $campaign->groups()->pluck('term_groups.id');
        foreach (Term::whereIn('term_group_id', $groupIds)->orderBy('id')->lockForUpdate()->cursor() as $term) {
            $this->admit($campaign, $term, false, $actor, $report);
        }
        foreach ($campaign->participations()->where('active', true)->where('direct', false)->with('term')->lockForUpdate()->get() as $p) {
            if (! $groupIds->contains($p->term->term_group_id)) {
                $previous = $p->tracked_proposal_id;
                $p->update(['active' => false, 'removed_at' => now(), 'tracked_proposal_id' => null]);
                $this->event($campaign, $p, 'removed', $actor, null, ['previous_proposal_id' => $previous]);
                $report['removed']++;
            }
        }
        $this->complete($campaign);
        $campaign->update(['admission_report' => $report]);

        return $report;
    }

    private function addDirectLocked(TranslationCampaign $campaign, array $termIds, ?User $actor, bool $complete = true): array
    {
        $ids = array_unique(array_map('intval', $termIds));
        abort_if(count($ids) > 500, 422);
        $terms = Term::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($terms->count() === count($ids), 404);
        $report = $this->report();
        foreach ($terms as $term) {
            $this->admit($campaign, $term, true, $actor, $report);
        }
        if ($complete) {
            $this->complete($campaign);
        }
        $campaign->update(['admission_report' => $report]);

        return $report;
    }

    private function admit(TranslationCampaign $campaign, Term $term, bool $direct, ?User $actor, array &$report): void
    {
        $p = $campaign->participations()->where('term_id', $term->id)->lockForUpdate()->first();
        if ($p?->active) {
            if ($direct && ! $p->direct) {
                $p->update(['direct' => true]);
                $this->event($campaign, $p, 'direct_added', $actor);
            }

            return;
        }
        $attribute = $this->primaryAttribute($term);
        try {
            $attrs = $term->attributesForDisplay();
        } catch (\RuntimeException|\InvalidArgumentException) {
            $report['excluded']['blocked']++;

            return;
        }
        $excluded = ! isset($attrs[$attribute]['columns'][$campaign->language]) ? 'missing_column' : ($term->obsolete || $term->scope_unconfirmed ? 'blocked' : null);
        if ($excluded === null) {
            try {
                $this->assertSource($term, $campaign->language);
            } catch (ValidationException|\RuntimeException|\InvalidArgumentException) {
                $excluded = 'blocked';
            }
        }
        if ($excluded === null) {
            $hasValue = data_get($term->validated, "$attribute.$campaign->language") !== null;
            $excluded = ($campaign->objective === 'translation' ? $hasValue : ! $hasValue) ? 'objective' : null;
        }
        if ($excluded !== null) {
            $report['excluded'][$excluded]++;
            $report['missing_column'] = $report['excluded']['missing_column'];

            return;
        }
        if ($p !== null) {
            $p->update($this->reset() + ['active' => true, 'direct' => $direct, 'status' => 'to_process', 'reason' => null, 'removed_at' => null]);
            $this->event($campaign, $p, 'returned', $actor);
            $report['returned']++;
        } else {
            $p = $campaign->participations()->create(['term_id' => $term->id, 'attribute' => $attribute, 'active' => true, 'direct' => $direct, 'status' => 'to_process']);
            $this->event($campaign, $p, 'admitted', $actor, null, ['direct' => $direct]);
            $pending = $this->pertinentPending($term, $campaign->language);
            if ($pending->count() === 1) {
                $this->track($campaign, $p, $pending->first(), $actor, 'proposal_attached_automatically');
            } elseif ($pending->count() > 1) {
                $p->update(['status' => 'to_arbitrate', 'reason' => 'Plusieurs propositions pertinentes sont en attente.']);
                $this->event($campaign, $p, 'competing_proposals', $actor, $p->reason, ['proposal_ids' => $pending->modelKeys()]);
            }
            $report['admitted']++;
        }
        if ($campaign->first_admitted_at === null) {
            $campaign->update(['first_admitted_at' => now()]);
        }
    }

    public function contextFingerprint(Term $term, string $attribute, string $language, ?array $attributes = null, ?array $row = null): string
    {
        $attributes ??= $term->attributesForDisplay();
        $a = $attributes[$attribute] ?? [];
        $row ??= $this->source->row($term);
        $format = FormatRegistry::get($term->type);
        $excluded = ['Default', 'Caption', 'Default Label', 'Default Language', 'de_CH', 'fr_CH', 'it_CH', 'en_US'];
        foreach ($format->attributeNames as $name) {
            $excluded[] = $name.' Default';
            foreach (['DE', 'FR', 'IT', 'EN'] as $suffix) {
                $excluded[] = $name.'_'.$suffix;
            }
        }
        foreach ($attributes as $entry) {
            $excluded = [...$excluded, ...array_values($entry['columns'] ?? [])];
        }
        for ($i = 1; $i <= 4; $i++) {
            $excluded[] = 'Language '.$i;
            $excluded[] = 'Translation '.$i;
        }
        foreach ($row as $column => $_) {
            $normalized = strtolower(str_replace([' ', '_'], '', $column));
            if (in_array($normalized, ['createtime', 'createuser', 'createdt', 'updatetime', 'updateuser', 'updatedt'], true)) {
                $excluded[] = $column;
            }
        }
        $context = array_diff_key($row, array_fill_keys($excluded, true));

        return hash('sha256', json_encode([$term->type, $term->key, $term->organization_id, $term->context, $context, $attribute, $a['reference'] ?? null, $a['reference_language'] ?? null, $a['columns'][$language] ?? null], JSON_THROW_ON_ERROR));
    }

    private function confirmationFingerprint(Term $term, string $attribute, string $language): string
    {
        return hash('sha256', $this->contextFingerprint($term, $attribute, $language).'\0'.json_encode(data_get($term->validated, "$attribute.$language"), JSON_THROW_ON_ERROR));
    }

    public function pertinentPending(Term $term, string $language)
    {
        $attribute = $this->primaryAttribute($term);
        try {
            $attrs = $term->attributesForDisplay();
        } catch (\RuntimeException|\InvalidArgumentException) {
            return new Collection;
        }

        return $term->proposals()->where('status', 'pending')->where('attribute', $attribute)->where('language', $language)->orderBy('id')->get()
            ->filter(fn ($p) => $this->isRelevant($p, $term, $attrs))->values();
    }

    public function isRelevant(Proposal $proposal, Term $term, ?array $attributes = null): bool
    {
        if ($proposal->status !== 'pending' || $proposal->term_id !== $term->id || $proposal->attribute !== $this->primaryAttribute($term) || $term->obsolete || $term->scope_unconfirmed) {
            return false;
        }
        try {
            $attrs = $attributes ?? $term->attributesForDisplay();
            $this->assertSource($term, $proposal->language);
        } catch (ValidationException|\RuntimeException|\InvalidArgumentException) {
            return false;
        }
        $a = $attrs[$proposal->attribute] ?? [];
        if (! isset($a['columns'][$proposal->language]) || TranslationValidator::validate($a['reference'], $proposal->value)['errors']) {
            return false;
        }
        if ($proposal->context_fingerprint !== null) {
            return hash_equals($proposal->context_fingerprint, $this->contextFingerprint($term, $proposal->attribute, $proposal->language, $attrs));
        }
        // Legacy imports have an immutable source revision. Unknown manual context is never guessed.
        if ($proposal->import_id === null) {
            return false;
        }
        $revision = $term->revisions()->where('source_import_id', $proposal->import_id)->first();
        if ($revision === null || ! array_key_exists('context', $revision->metadata) || data_get($revision->metadata, 'context') !== $term->context || data_get($revision->metadata, 'key') !== $term->key || data_get($revision->metadata, 'organization_id') !== $term->organization_id) {
            return false;
        }
        $legacyTerm = clone $term;
        $legacyTerm->forceFill($revision->only(['source_import_id', 'source_offset', 'source_length']));
        $legacyTerm->unsetRelation('sourceImport');
        try {
            $this->assertSource($legacyTerm, $proposal->language);
        } catch (ValidationException|\RuntimeException|\InvalidArgumentException) {
            return false;
        }

        return $this->contextFingerprint($term, $proposal->attribute, $proposal->language, $this->source->attributes($revision), $this->source->row($revision)) === $this->contextFingerprint($term, $proposal->attribute, $proposal->language, $attrs);
    }

    /** Called inside the reference write transaction; completed campaigns remain frozen. */
    public function referenceChanged(Term $term): void
    {
        $term = $term->fresh();
        $participations = CampaignParticipation::where('term_id', $term->id)->where('active', true)->whereHas('campaign', fn ($q) => $q->where('status', '!=', 'completed'))->with('campaign', 'trackedProposal')->lockForUpdate()->get();
        foreach ($participations as $p) {
            $campaign = $this->locked($p->campaign);
            if ($p->status === 'confirmed' && $p->confirmation_fingerprint !== $this->confirmationFingerprint($term, $p->attribute, $campaign->language)) {
                $p->update($this->reset() + ['status' => 'to_process', 'reason' => 'La traduction examinée ou sa référence a changé.']);
                $this->event($campaign, $p, 'automatic_reexamination', null, $p->reason);
            } elseif ($p->trackedProposal !== null && $p->trackedProposal->status === 'pending' && ! $this->isRelevant($p->trackedProposal, $term)) {
                $previous = $p->tracked_proposal_id;
                $p->update($this->reset() + ['status' => 'to_process', 'reason' => 'La référence de la proposition a changé.']);
                $this->event($campaign, $p, 'proposal_context_changed', null, $p->reason, ['previous_proposal_id' => $previous]);
            }
        }
    }

    /** Only currently followed proposals cause decision transitions, in every active campaign. */
    public function proposalDecided(Proposal $proposal, User $actor): void
    {
        foreach (CampaignParticipation::where('tracked_proposal_id', $proposal->id)->where('active', true)->whereHas('campaign', fn ($q) => $q->where('status', '!=', 'completed'))->with('campaign', 'term')->lockForUpdate()->get() as $p) {
            $campaign = $this->locked($p->campaign);
            if ($proposal->status === 'validated') {
                $this->confirmLocked($campaign, $p->term->fresh(), $p, $actor, 'confirmed_by_validation', null, $proposal->id);
            } elseif ($proposal->status === 'rejected') {
                $p->update($this->reset() + ['status' => 'to_process', 'reason' => $proposal->rejection_reason]);
                $this->event($campaign, $p, 'proposal_rejected', $actor, $proposal->rejection_reason, ['proposal_id' => $proposal->id]);
            }
        }
    }

    private function work(User $user, TranslationCampaign $campaign, Term $term): array
    {
        $campaign = $this->locked($campaign);
        $term = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
        abort_unless($this->canWork($user, $campaign, $term), 403);

        return [$campaign, $term, $this->participation($campaign, $term)];
    }

    private function confirmLocked(TranslationCampaign $campaign, Term $term, CampaignParticipation $p, User $actor, string $action, ?string $reason = null, ?int $proposalId = null): void
    {
        $this->assertSource($term, $campaign->language);
        $value = data_get($term->validated, "$p->attribute.$campaign->language");
        if ($value === null || TranslationValidator::validate($term->attributesForDisplay()[$p->attribute]['reference'], $value)['errors']) {
            $this->invalid('value', __('campaigns.errors.no_current_value'));
        }
        $previous = $p->tracked_proposal_id;
        $p->update(['status' => 'confirmed', 'tracked_proposal_id' => null, 'reason' => $reason, 'confirmed_at' => now(), 'confirmed_by' => $actor->id, 'confirmed_revision' => $term->revision_no, 'confirmation_fingerprint' => $this->confirmationFingerprint($term, $p->attribute, $campaign->language)]);
        $this->event($campaign, $p, $action, $actor, $reason, ['proposal_id' => $proposalId, 'previous_proposal_id' => $previous, 'revision' => $term->revision_no, 'fingerprint' => $p->confirmation_fingerprint]);
        $this->complete($campaign);
    }

    private function track(TranslationCampaign $campaign, CampaignParticipation $p, Proposal $proposal, ?User $actor, string $action, ?string $reason = null): void
    {
        $previous = $p->tracked_proposal_id;
        $p->update(array_replace($this->reset(), ['status' => 'proposal_sent', 'tracked_proposal_id' => $proposal->id, 'reason' => $reason]));
        $this->event($campaign, $p, $action, $actor, $reason, ['proposal_id' => $proposal->id, 'previous_proposal_id' => $previous]);
    }

    private function assertSource(Term $term, string $language): void
    {
        $format = FormatRegistry::get($term->type);
        $import = $term->sourceImport;
        if ($import !== null) {
            $path = $import->originalPath();
            $cacheKey = $import->id.'|'.$import->sha256;
            if (! isset($this->verifiedSources[$cacheKey])) {
                if (! is_file($path) || ! hash_equals($import->sha256, (string) hash_file('sha256', $path))) {
                    $this->invalid('term', __('campaigns.errors.altered_original'));
                }
                $this->verifiedSources[$cacheKey] = true;
            }
            $presence = DB::table('import_rows')->where('import_id', $term->source_import_id)->where('offset', $term->source_offset)->where('length', $term->source_length)->first();
            if ($presence === null || $presence->collision || ($presence->term_id !== $term->id && $import->status !== 'applying')) {
                $this->invalid('term', __('campaigns.errors.source_blocked'));
            }
        }
        $row = $this->source->row($term);
        if ($term->obsolete || $term->scope_unconfirmed || $format->identity($row) !== $term->key || ! isset($term->attributesForDisplay()[$this->primaryAttribute($term)]['columns'][$language])
            || DB::table('import_rows')->where('import_id', $term->source_import_id)->where('offset', $term->source_offset)->where('collision', true)->exists()) {
            $this->invalid('term', __('campaigns.errors.source_blocked'));
        }
    }

    private function assign(TranslationCampaign $campaign, array $ids): void
    {
        $ids = array_unique(array_map('intval', $ids));
        $users = User::whereIn('id', $ids)->where('active', true)->where('is_technical', false)->get();
        abort_unless($users->count() === count($ids), 422);
        foreach ($users as $user) {
            abort_unless($user->canTranslate($campaign->language, $user->organization_id), 422);
        }
        if ($campaign->status === 'in_progress' && $ids === []) {
            $this->invalid('assignee_ids', __('campaigns.errors.last_assignee'));
        }
        $campaign->assignees()->sync($ids);
    }

    private function groups(TranslationCampaign $campaign, array $ids): void
    {
        $ids = array_unique(array_map('intval', $ids));
        $existing = $campaign->groups()->pluck('term_groups.id');
        $groups = TermGroup::whereIn('id', $ids)->get();
        abort_unless($groups->count() === count($ids), 422);
        foreach ($groups as $group) {
            abort_if($group->archived_at !== null && ! $existing->contains($group->id), 422);
        }
        $campaign->groups()->sync($ids);
    }

    private function values(array $data): array
    {
        return Validator::make($data, ['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'], 'language' => ['required', Rule::in(User::LANGUAGES)], 'objective' => ['required', Rule::in(['translation', 'revision'])], 'deadline' => ['nullable', 'date']])->validate();
    }

    private function mutable(TranslationCampaign $campaign): void
    {
        abort_if($campaign->status === 'completed' || $campaign->archived_at !== null, 422);
    }

    private function locked(TranslationCampaign $campaign): TranslationCampaign
    {
        return TranslationCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
    }

    private function participation(TranslationCampaign $campaign, Term $term, bool $active = true): CampaignParticipation
    {
        $q = $campaign->participations()->where('term_id', $term->id)->lockForUpdate();
        if ($active) {
            $q->where('active', true);
        }

        return $q->firstOrFail();
    }

    private function complete(TranslationCampaign $campaign): void
    {
        if ($campaign->status === 'in_progress' && $campaign->participations()->where('active', true)->exists() && ! $campaign->participations()->where('active', true)->where('status', '!=', 'confirmed')->exists()) {
            $campaign->update(['status' => 'completed']);
            $this->event($campaign, null, 'completed', null);
        }
    }

    private function event(TranslationCampaign $campaign, ?CampaignParticipation $p, string $action, ?User $actor, ?string $reason = null, array $payload = []): void
    {
        CampaignEvent::create(['translation_campaign_id' => $campaign->id, 'campaign_participation_id' => $p?->id, 'actor_id' => $actor?->id, 'action' => $action, 'reason' => $reason, 'payload' => $payload, 'created_at' => now()]);
    }

    private function reset(): array
    {
        return ['tracked_proposal_id' => null, 'confirmed_at' => null, 'confirmed_by' => null, 'confirmed_revision' => null, 'confirmation_fingerprint' => null];
    }

    private function report(): array
    {
        return ['admitted' => 0, 'returned' => 0, 'removed' => 0, 'missing_column' => 0, 'excluded' => ['missing_column' => 0, 'objective' => 0, 'blocked' => 0]];
    }

    private function mergeReports(array $a, array $b): array
    {
        foreach (['admitted', 'returned', 'removed', 'missing_column'] as $key) {
            $a[$key] += $b[$key];
        }
        foreach ($a['excluded'] as $key => $_) {
            $a['excluded'][$key] += $b['excluded'][$key];
        }

        return $a;
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:5000']])->validate();
        if (trim($reason) === '') {
            $this->invalid('reason', __('ui.reason_required'));
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function write(callable $callback): mixed
    {
        $this->verifiedSources = [];

        return $this->lock->run(fn () => DB::transaction($callback, 3));
    }
}
