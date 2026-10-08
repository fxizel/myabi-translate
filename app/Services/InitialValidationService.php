<?php

namespace App\Services;

use App\Domain\Devconf\TranslationValidator;
use App\Models\BulkOperation;
use App\Models\ImportBatch;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Resumable initial validation; each checkpoint commits together with its decisions. */
class InitialValidationService
{
    public function __construct(private WorkflowService $workflow, private OperationLock $lock, private AuditService $audit) {}

    public function preview(User $user, ImportBatch $import, string $language, bool $override = false): array
    {
        $this->authorize($user, $import, $language);
        $generation = $this->generation();
        $maximum = (int) $this->importProposals($import->id)->where('language', $language)->max('id');
        $counts = ['total' => 0, 'eligible' => 0, 'excluded' => 0, 'reasons' => ['invalid' => 0, 'divergence' => 0, 'competing' => 0, 'self' => 0, 'obsolete' => 0]];
        $this->query($import->id, $language, $maximum)->where('status', 'pending')->chunkById(300, function ($proposals) use (&$counts, $user, $override) {
            $attributes = [];
            foreach ($proposals as $proposal) {
                $counts['total']++;
                $reason = $this->exclusion($proposal, $user, $override, $attributes);
                if ($reason) {
                    $counts['excluded']++;
                    $counts['reasons'][$reason]++;
                } else {
                    $counts['eligible']++;
                }
            }
        });
        if ($this->generation() !== $generation) {
            $this->stale();
        }

        return ['import_id' => $import->id, 'language' => $language, 'override' => $override, 'generation' => $generation,
            'max_proposal_id' => $maximum, 'counts' => $counts, 'created_at' => now()->toIso8601String()];
    }

    /** HTTP requests enqueue analysis; a large import never blocks the request. */
    public function enqueuePreview(User $user, ImportBatch $import, string $language, bool $override = false): BulkOperation
    {
        $this->authorize($user, $import, $language);

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $import, $language, $override) {
            ImportBatch::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $existing = BulkOperation::where('type', 'initial_preview')->where('user_id', $user->id)->where('import_id', $import->id)
                ->where('language', $language)->where('override', $override)->whereIn('status', ['queued', 'processing'])->first();
            if ($existing) {
                return $existing;
            }
            $maximum = (int) $this->importProposals($import->id)->where('language', $language)->max('id');
            $preview = ['import_id' => $import->id, 'language' => $language, 'override' => $override, 'generation' => $this->generation(),
                'max_proposal_id' => $maximum, 'created_at' => now()->toIso8601String(),
                'counts' => ['total' => 0, 'eligible' => 0, 'excluded' => 0, 'reasons' => ['invalid' => 0, 'divergence' => 0, 'competing' => 0, 'self' => 0, 'obsolete' => 0]]];
            $operation = BulkOperation::create(['type' => 'initial_preview', 'user_id' => $user->id, 'import_id' => $import->id, 'language' => $language, 'override' => $override,
                'status' => 'queued', 'max_proposal_id' => $maximum, 'expected_generation' => $preview['generation'], 'preview' => $preview,
                'counts' => ['validated' => 0, 'excluded' => 0, 'examined' => 0], 'created_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->record('validation.initial_preview_queued', $operation, [], ['import_id' => $import->id, 'language' => $language, 'override' => $override], $user);

            return $operation;
        }));
    }

    public function reviewedPreview(User $user, BulkOperation $operation): array
    {
        $this->authorize($user, $operation->import, $operation->language);
        abort_unless($operation->user_id === $user->id && $operation->type === 'initial_preview' && $operation->status === 'ready', 403);
        if ($this->generation() !== (int) $operation->expected_generation) {
            $this->stale();
        }

        return $operation->preview;
    }

    public function enqueue(User $user, ImportBatch $import, array $preview): BulkOperation
    {
        $this->authorize($user, $import, $preview['language']);
        if ((int) $preview['import_id'] !== $import->id || now()->diffInMinutes($preview['created_at'], true) > 30) {
            $this->stale();
        }
        if (($preview['counts']['eligible'] ?? 0) < 1) {
            throw ValidationException::withMessages(['validation' => __('ui.initial_no_eligible')]);
        }

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $import, $preview) {
            ImportBatch::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($this->generation() !== (int) $preview['generation']) {
                $this->stale();
            }
            if (BulkOperation::where('type', 'initial_validation')->where('import_id', $import->id)->where('language', $preview['language'])->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['validation' => __('ui.initial_already_running')]);
            }
            $operation = BulkOperation::create(['user_id' => $user->id, 'import_id' => $import->id, 'language' => $preview['language'],
                'override' => $preview['override'], 'status' => 'queued', 'max_proposal_id' => $preview['max_proposal_id'],
                'expected_generation' => $preview['generation'], 'preview' => $preview, 'counts' => ['validated' => 0, 'excluded' => $preview['counts']['excluded'], 'examined' => 0],
                'created_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->record('validation.initial_queued', $operation, [], ['import_id' => $import->id, 'language' => $operation->language, 'preview' => $preview['counts'], 'override' => $operation->override], $user);

            return $operation;
        }));
    }

    /** The worker must hold the exclusive OperationLock. Returns true when completed. */
    public function process(BulkOperation $operation, int $batchSize = 300): bool
    {
        $batchSize = max(1, min(300, $batchSize));
        try {
            if ($operation->type === 'initial_preview') {
                return $this->processPreview($operation, $batchSize);
            }

            return DB::transaction(function () use ($operation, $batchSize) {
                $operation = BulkOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if ($operation->status === 'completed') {
                    return true;
                }
                if (! in_array($operation->status, ['queued', 'processing'], true)) {
                    return false;
                }
                $user = User::findOrFail($operation->user_id);
                $import = ImportBatch::findOrFail($operation->import_id);
                $this->authorize($user, $import, $operation->language);
                if ($this->generation() !== (int) $operation->expected_generation) {
                    $this->stale();
                }
                $operation->status = 'processing';
                $operation->started_at ??= now();
                $proposals = $this->query($import->id, $operation->language, $operation->max_proposal_id)
                    ->where('id', '>', $operation->cursor)->orderBy('id')->limit($batchSize)->get();
                $selection = [];
                $attributes = [];
                $counts = $operation->counts;
                foreach ($proposals as $proposal) {
                    $operation->cursor = $proposal->id;
                    if ($proposal->status !== 'pending') {
                        continue;
                    }
                    $counts['examined']++;
                    if ($this->exclusion($proposal, $user, $operation->override, $attributes) === null) {
                        $selection[$proposal->id] = $proposal->lock_version;
                    }
                }
                if ($selection) {
                    $this->workflow->bulkUnderOperationLock($user, $selection, 'validate', null, $operation->override);
                    $counts['validated'] += count($selection);
                }
                $operation->counts = $counts;
                $operation->expected_generation = $this->generation();
                $operation->updated_by = $user->id;
                $hasMore = $this->importProposals($import->id)->where('language', $operation->language)
                    ->where('id', '>', $operation->cursor)->where('id', '<=', $operation->max_proposal_id)->exists();
                if (! $hasMore) {
                    if ($counts['validated'] !== (int) $operation->preview['counts']['eligible']) {
                        $this->stale();
                    }
                    $operation->status = 'completed';
                    $operation->finished_at = now();
                }
                $operation->save();
                $this->audit->record($hasMore ? 'validation.initial_progress' : 'validation.initial_completed', $operation, [],
                    ['import_id' => $import->id, 'language' => $operation->language, 'cursor' => $operation->cursor, 'counts' => $counts], $user);

                return ! $hasMore;
            });
        } catch (Throwable $exception) {
            $operation->refresh();
            $error = $exception instanceof ValidationException
                ? LocalizedMessage::store(array_merge(...array_values($exception->errors())))
                : OperationFailure::capture($exception, $operation, 'ui.initial_failed');
            $operation->update(['status' => 'failed', 'error' => $error, 'updated_by' => $operation->user_id]);
            $this->audit->record('validation.initial_failed', $operation, [], ['counts' => $operation->counts, 'error' => $error], $operation->user);

            return false;
        }
    }

    public function retry(User $user, BulkOperation $operation): void
    {
        $this->authorize($user, $operation->import, $operation->language);
        $this->lock->run(fn () => DB::transaction(function () use ($user, $operation) {
            $operation = BulkOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless($operation->status === 'failed', 409);
            if ($this->generation() !== (int) $operation->expected_generation) {
                $this->stale();
            }
            if (BulkOperation::where('type', $operation->type)->where('import_id', $operation->import_id)->where('language', $operation->language)
                ->where('id', '!=', $operation->id)->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['validation' => __('ui.initial_already_running')]);
            }
            $operation->update(['status' => 'queued', 'error' => null, 'updated_by' => $user->id]);
            $this->audit->record('validation.initial_retried', $operation, [], ['cursor' => $operation->cursor, 'counts' => $operation->counts], $user);
        }));
    }

    private function processPreview(BulkOperation $operation, int $batchSize): bool
    {
        return DB::transaction(function () use ($operation, $batchSize) {
            $operation = BulkOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($operation->status === 'ready') {
                return true;
            }
            if (! in_array($operation->status, ['queued', 'processing'], true)) {
                return false;
            }
            $user = $operation->user;
            $this->authorize($user, $operation->import, $operation->language);
            if ($this->generation() !== (int) $operation->expected_generation) {
                $this->stale();
            }
            $preview = $operation->preview;
            $attributes = [];
            $proposals = $this->query($operation->import_id, $operation->language, $operation->max_proposal_id)
                ->where('id', '>', $operation->cursor)->orderBy('id')->limit($batchSize)->get();
            foreach ($proposals as $proposal) {
                $operation->cursor = $proposal->id;
                if ($proposal->status !== 'pending') {
                    continue;
                }
                $preview['counts']['total']++;
                $reason = $this->exclusion($proposal, $user, $operation->override, $attributes);
                if ($reason) {
                    $preview['counts']['excluded']++;
                    $preview['counts']['reasons'][$reason]++;
                } else {
                    $preview['counts']['eligible']++;
                }
            }
            $hasMore = $this->importProposals($operation->import_id)->where('language', $operation->language)
                ->where('id', '>', $operation->cursor)->where('id', '<=', $operation->max_proposal_id)->exists();
            $operation->started_at ??= now();
            $operation->status = $hasMore ? 'processing' : 'ready';
            if (! $hasMore) {
                $operation->finished_at = now();
                $preview['created_at'] = now()->toIso8601String();
            }
            $operation->preview = $preview;
            $operation->counts = ['validated' => 0, 'excluded' => $preview['counts']['excluded'], 'examined' => $preview['counts']['total']];
            $operation->save();
            if (! $hasMore) {
                $this->audit->record('validation.initial_preview_ready', $operation, [], ['counts' => $preview['counts']], $user);
            }

            return ! $hasMore;
        });
    }

    private function importProposals(int $importId)
    {
        return Proposal::where(fn ($query) => $query->where('import_id', $importId)
            ->orWhereIn('proposals.id', DB::table('proposal_imports')->where('import_id', $importId)->select('proposal_id')));
    }

    private function query(int $importId, string $language, int $maximum)
    {
        return $this->importProposals($importId)->with('term.sourceImport')->select('proposals.*')
            ->selectSub(fn ($query) => $query->from('proposals as concurrent')->selectRaw('COUNT(*)')
                ->whereColumn('concurrent.term_id', 'proposals.term_id')->whereColumn('concurrent.attribute', 'proposals.attribute')
                ->whereColumn('concurrent.language', 'proposals.language')->where('concurrent.status', 'pending'), 'pending_cell_count')
            ->where('language', $language)->where('id', '<=', $maximum);
    }

    private function exclusion(Proposal $proposal, User $user, bool $override, array &$attributesCache): ?string
    {
        if ($proposal->divergence) {
            return 'divergence';
        }
        if ($proposal->term->obsolete) {
            return 'obsolete';
        }
        if ($proposal->author_id === $user->id && ! $override) {
            return 'self';
        }
        // Count all pending candidates, including manual proposals and other imports.
        if ((int) $proposal->pending_cell_count > 1) {
            return 'competing';
        }
        $attributes = $attributesCache[$proposal->term_id] ??= $proposal->term->attributesForDisplay();
        $reference = $attributes[$proposal->attribute]['reference'] ?? null;
        if ($reference === null) {
            return 'invalid';
        }
        $check = TranslationValidator::validate($reference, $proposal->value);
        if ($check['errors'] || $check['warnings']) {
            return 'invalid';
        }

        return null;
    }

    private function authorize(User $user, ImportBatch $import, string $language): void
    {
        abort_unless($user->active && $user->canManage() && $user->canValidate($language), 403);
        abort_unless($import->status === 'applied' && in_array($language, ['de', 'fr', 'it', 'en'], true), 409);
    }

    private function generation(): int
    {
        return (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['validation' => __('ui.initial_preview_stale')]);
    }
}
