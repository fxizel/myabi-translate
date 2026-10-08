<?php

namespace App\Services;

use App\Domain\Devconf\TranslationValidator;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
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

    public function propose(User $user, Term $term, string $attribute, string $language, string $value, ?int $expected = null, ?int $supersedesId = null, ?int $revisionId = null): Proposal
    {
        abort_unless($user->canTranslate($language, $term->organization_id), 403);

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $term, $attribute, $language, $value, $expected, $supersedesId, $revisionId) {
            $term = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->canTranslate($language, $term->organization_id), 403);
            if ($expected !== null && $term->lock_version !== $expected) {
                $this->stale();
            }
            $warnings = $this->checkValue($term, $attribute, $language, $value);
            if ($supersedesId !== null) {
                abort_unless(Proposal::whereKey($supersedesId)->where('term_id', $term->id)->where('attribute', $attribute)->where('language', $language)->where('status', 'rejected')->exists(), 422);
            }
            if ($revisionId !== null) {
                abort_unless($term->revisions()->whereKey($revisionId)->exists(), 422);
            }
            $proposal = Proposal::create(['term_id' => $term->id, 'attribute' => $attribute, 'language' => $language, 'value' => $value, 'value_hash' => hash('sha256', $value),
                'status' => 'pending', 'author_id' => $user->id, 'organization_id' => $user->organization_id, 'anomalies' => $warnings, 'edit_history' => [], 'claimed' => true, 'supersedes_id' => $supersedesId, 'restored_revision_id' => $revisionId]);
            app(SearchText::class)->sync($term);
            $this->audit->record('proposal.created', $proposal, [], ['value' => $value], $user);
            $this->generation();

            return $proposal->fresh();
        }));
    }

    public function edit(User $user, Proposal $proposal, string $value, int $expected): Proposal
    {
        return $this->lock->run(fn () => DB::transaction(function () use ($user, $proposal, $value, $expected) {
            $term = Term::whereKey($proposal->term_id)->lockForUpdate()->firstOrFail();
            $p = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->canTranslate($p->language, $term->organization_id), 403);
            abort_unless($p->author_id === $user->id || ($p->import_id && ! $p->claimed), 403);
            if ($p->status !== 'pending' || $p->lock_version !== $expected) {
                $this->stale();
            }
            $warnings = $this->checkValue($term, $p->attribute, $p->language, $value);
            $before = $p->value;
            $history = $p->edit_history;
            $history[] = ['value' => $before, 'author_id' => $p->author_id, 'at' => now()->toIso8601String()];
            $p->update(['value' => $value, 'value_hash' => hash('sha256', $value), 'author_id' => $user->id, 'claimed' => true, 'lock_version' => $p->lock_version + 1, 'anomalies' => $warnings, 'edit_history' => $history]);
            app(SearchText::class)->sync($term);
            $this->audit->record('proposal.edited', $p, ['value' => $before], ['value' => $value], $user);
            $this->generation();

            return $p;
        }));
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
            $term->update(['validated' => $values, 'review_needed' => $review, 'revision_no' => $term->revision_no + 1, 'lock_version' => $term->lock_version + 1, 'updated_by' => $user->id,
                'search_text' => $term->label.' '.$term->source_text.' '.implode(' ', array_merge(...array_values($values)))]);
            $p->update(['status' => 'validated', 'decided_by' => $user->id, 'decided_at' => now(), 'lock_version' => $p->lock_version + 1]);
            $superseded = Proposal::where('term_id', $term->id)->where('attribute', $p->attribute)->where('language', $p->language)->where('status', 'pending');
            $superseded->update(['status' => 'rejected', 'rejection_reason' => 'superseded', 'decided_by' => $user->id, 'decided_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            app(SearchText::class)->sync($term);
            $this->source->snapshot($term, 'proposal', $p->id, $user->id);
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
