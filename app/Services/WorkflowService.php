<?php

namespace App\Services;

use App\Domain\Devconf\TranslationValidator;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function __construct(private OperationLock $lock, private AuditService $audit, private SourceRecord $source) {}

    private function checkValue(Term $term, string $attribute, string $language, string $value): array
    {
        $attrs = $term->attributesForDisplay();
        if (! isset($attrs[$attribute]['columns'][$language])) {
            throw ValidationException::withMessages(['value' => __('ui.invalid_attribute')]);
        }
        $result = TranslationValidator::validate($attrs[$attribute]['reference'], $value);
        if ($result['errors']) {
            throw ValidationException::withMessages(['value' => $result['errors']]);
        }

        return $result['warning_codes'];
    }

    public function propose(User $user, Term $term, string $attribute, string $language, string $value, ?int $expected = null, ?int $supersedesId = null, ?int $revisionId = null, ?UploadedFile $screenshot = null, string $screenshotAction = 'keep'): Proposal
    {
        abort_unless($user->canTranslate($language), 403);
        $prepared = app(ProposalScreenshots::class)->prepare($screenshot, $screenshotAction);

        return $this->lock->run(fn () => DB::transaction(fn () => $this->proposeUnderOperationLock($user, $term, $attribute, $language, $value, $expected, $supersedesId, $revisionId, $prepared, $screenshotAction)), $screenshotAction !== 'keep' || $supersedesId !== null);
    }

    /** Caller owns the transaction and holds the operation lock through its completion. */
    public function proposeUnderOperationLock(User $user, Term $term, string $attribute, string $language, string $value, ?int $expected = null, ?int $supersedesId = null, ?int $revisionId = null, ?array $prepared = null, string $screenshotAction = 'keep'): Proposal
    {
        $term = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->canTranslate($language), 403);
        if ($expected !== null && $term->lock_version !== $expected) {
            $this->stale();
        }
        $warnings = $this->checkValue($term, $attribute, $language, $value);
        $rejected = null;
        if ($supersedesId !== null) {
            $rejected = Proposal::whereKey($supersedesId)->where('term_id', $term->id)->where('attribute', $attribute)->where('language', $language)->where('status', 'rejected')->first();
            abort_unless($rejected, 422);
        }
        if ($revisionId !== null) {
            abort_unless(Revision::whereKey($revisionId)->where(fn ($query) => $query->where('term_id', $term->id)
                ->orWhereIn('term_id', DB::table('term_consolidations')->where('common_term_id', $term->id)->select('legacy_term_id')))->exists(), 422);
        }
        $screenshots = app(ProposalScreenshots::class);
        $image = $screenshots->change($rejected, $screenshotAction, $prepared, true);
        $proposal = Proposal::create([...$image, 'term_id' => $term->id, 'attribute' => $attribute, 'language' => $language, 'value' => $value, 'value_hash' => hash('sha256', $value),
            'status' => 'pending', 'author_id' => $user->id, 'organization_id' => $user->organization_id, 'anomalies' => $warnings, 'edit_history' => [], 'claimed' => true, 'supersedes_id' => $supersedesId, 'restored_revision_id' => $revisionId]);
        app(SearchText::class)->sync($term);
        $this->audit->record('proposal.created', $proposal, [], ['value' => $value, 'screenshot' => $screenshots->summary($image), 'screenshot_source_id' => $image['screenshot_path'] && $screenshotAction === 'keep' ? $supersedesId : null], $user);
        $this->generation();

        return $proposal->fresh();
    }

    public function edit(User $user, Proposal $proposal, string $value, int $expected, ?UploadedFile $screenshot = null, string $screenshotAction = 'keep'): Proposal
    {
        abort_unless($user->canTranslate($proposal->language) && ($proposal->author_id === $user->id || ($proposal->import_id && ! $proposal->claimed)), 403);
        $screenshots = app(ProposalScreenshots::class);
        $prepared = $screenshots->prepare($screenshot, $screenshotAction);

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $proposal, $value, $expected, $prepared, $screenshotAction, $screenshots) {
            $term = Term::whereKey($proposal->term_id)->lockForUpdate()->firstOrFail();
            $p = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->canTranslate($p->language), 403);
            abort_unless($p->author_id === $user->id || ($p->import_id && ! $p->claimed), 403);
            if ($p->status !== 'pending' || $p->lock_version !== $expected) {
                $this->stale();
            }
            $warnings = $this->checkValue($term, $p->attribute, $p->language, $value);
            $before = $p->value;
            $beforeImage = $screenshots->summary($p->only(ProposalScreenshots::FIELDS));
            $image = $screenshots->change($p, $screenshotAction, $prepared);
            $history = $p->edit_history;
            $history[] = ['value' => $before, 'author_id' => $p->author_id, 'organization_id' => $p->organization_id,
                'import_ids' => array_values(array_unique(array_filter([$p->import_id, ...DB::table('proposal_imports')->where('proposal_id', $p->id)->pluck('import_id')->all()]))),
                'screenshot' => $beforeImage, 'screenshot_action' => $screenshotAction, 'at' => now()->toIso8601String()];
            $p->update([...$image, 'value' => $value, 'value_hash' => hash('sha256', $value), 'author_id' => $user->id, 'claimed' => true, 'lock_version' => $p->lock_version + 1, 'anomalies' => $warnings, 'edit_history' => $history]);
            $p->update(['context_fingerprint' => app(CampaignService::class)->contextFingerprint($term, $p->attribute, $p->language)]);
            app(SearchText::class)->sync($term);
            $this->audit->record('proposal.edited', $p, ['value' => $before, 'screenshot' => $beforeImage], ['value' => $value, 'screenshot' => $screenshots->summary($image), 'screenshot_action' => $screenshotAction], $user);
            $this->generation();

            return $p;
        }), $screenshotAction !== 'keep');
    }

    public function decide(User $user, Proposal $proposal, string $decision, int $expected, ?string $reason = null, bool $override = false): void
    {
        $this->lock->run(fn () => DB::transaction(fn () => $this->decideLocked($user, $proposal, $decision, $expected, $reason, $override)));
    }

    private function decideLocked(User $user, Proposal $proposal, string $decision, int $expected, ?string $reason, bool $override): void
    {
        $term = Term::whereKey($proposal->term_id)->lockForUpdate()->firstOrFail();
        $p = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->canValidate($p->language), 403);
        if ($p->status !== 'pending' || $p->lock_version !== $expected) {
            $this->stale();
        }
        if (! in_array($decision, ['validate', 'reject'])) {
            abort(422);
        }
        if ($decision === 'reject') {
            if (! trim($reason ?? '')) {
                throw ValidationException::withMessages(['reason' => __('ui.reason_required')]);
            }
            $p->update(['status' => 'rejected', 'rejection_reason' => $reason, 'decided_by' => $user->id, 'decided_at' => now(), 'lock_version' => $p->lock_version + 1]);
            app(SearchText::class)->sync($term);
        } else {
            if ($p->author_id === $user->id) {
                if (! $user->canManage() || ! $override) {
                    throw ValidationException::withMessages(['override' => __('ui.four_eyes_required')]);
                }
                $this->audit->record('validation.four_eyes_override', $p, [], ['explicit' => true], $user);
            }
            $this->checkValue($term, $p->attribute, $p->language, $p->value);
            $before = $term->validated;
            $values = $before;
            $values[$p->attribute][$p->language] = $p->value;
            $review = $term->review_needed;
            unset($review[$p->attribute][$p->language]);
            $term->campaignSyncDeferred = true;
            $term->update(['validated' => $values, 'review_needed' => $review, 'revision_no' => $term->revision_no + 1, 'lock_version' => $term->lock_version + 1, 'updated_by' => $user->id,
                'search_text' => $term->label.' '.$term->source_text.' '.implode(' ', array_merge(...array_values($values)))]);
            $p->update(['status' => 'validated', 'decided_by' => $user->id, 'decided_at' => now(), 'lock_version' => $p->lock_version + 1]);
            $superseded = Proposal::where('term_id', $term->id)->where('attribute', $p->attribute)->where('language', $p->language)->where('status', 'pending');
            $supersededIds = (clone $superseded)->pluck('id');
            $superseded->update(['status' => 'rejected', 'rejection_reason' => 'superseded', 'decided_by' => $user->id, 'decided_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            foreach (Proposal::whereIn('id', $supersededIds)->get() as $other) {
                app(CampaignService::class)->proposalDecided($other, $user);
            }
            app(SearchText::class)->sync($term);
            $this->source->snapshot($term, 'proposal', $p->id, $user->id);
        }
        app(CampaignService::class)->proposalDecided($p->fresh(), $user);
        if ($decision === 'validate') {
            app(CampaignService::class)->synchronizeForTerm($term->fresh(), $user);
        }
        $this->audit->record('proposal.'.$decision, $p, [], ['value' => $p->value, 'reason' => $reason, 'manager' => $user->canManage()], $user);
        $this->generation();
    }

    public function bulk(User $user, array $selection, string $decision, ?string $reason, bool $override): void
    {
        $this->lock->run(fn () => $this->bulkUnderOperationLock($user, $selection, $decision, $reason, $override));
    }

    /** Internal worker entry: caller must hold the operation lock until transaction commit. */
    public function bulkUnderOperationLock(User $user, array $selection, string $decision, ?string $reason, bool $override): void
    {
        if (count($selection) < 1 || count($selection) > 500) {
            abort(422);
        }
        DB::transaction(function () use ($user, $selection, $decision, $reason, $override) {
            ksort($selection);
            foreach ($selection as $id => $version) {
                $this->decideLocked($user, Proposal::findOrFail($id), $decision, (int) $version, $reason, $override);
            }
            $this->audit->record('proposal.bulk', 'proposal', [], ['count' => count($selection), 'decision' => $decision], $user);
        });
    }

    public function confirm(User $user, Term $term, string $attribute, string $language, int $expected): void
    {
        abort_unless($user->canValidate($language), 403);
        $this->lock->run(fn () => DB::transaction(function () use ($user, $term, $attribute, $language, $expected) {
            $t = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
            if ($t->lock_version !== $expected) {
                $this->stale();
            }
            $value = data_get($t->validated, "$attribute.$language");
            if ($value === null || ! data_get($t->review_needed, "$attribute.$language")) {
                abort(422);
            }
            $this->checkValue($t, $attribute, $language, $value);
            $review = $t->review_needed;
            unset($review[$attribute][$language]);
            $t->update(['review_needed' => $review, 'lock_version' => $t->lock_version + 1, 'updated_by' => $user->id]);
            $this->audit->record('translation.confirmed', $t, [], compact('attribute', 'language', 'value'), $user);
            $this->generation();
        }));
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['version' => __('ui.stale_change')]);
    }

    private function generation(): void
    {
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
    }
}
