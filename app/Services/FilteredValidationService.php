<?php

namespace App\Services;

use App\Domain\Devconf\TranslationValidator;
use App\Models\BulkOperation;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

/** Filtered validation shares the durable worker and the ordinary decision rules. */
class FilteredValidationService
{
    public function __construct(private ProposalFilter $filter, private WorkflowService $workflow, private OperationLock $lock, private AuditService $audit) {}

    public function snapshot(User $user, array $filters, int $total, int $generation): ?string
    {
        if ($total < 1 || ! $user->canValidate($filters['language'])) {
            return null;
        }
        $this->authorize($user, $filters['language']);
        $maximum = (int) $this->filter->query($user, $filters)->max('proposals.id');
        if ($this->generation() !== $generation) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'user_id' => $user->id, 'organization_id' => $user->organization_id,
            'filters' => $filters, 'total' => $total, 'generation' => $generation,
            'max_proposal_id' => $maximum, 'created_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function enqueue(User $user, string $token, bool $override = false): BulkOperation
    {
        $preview = $this->decode($token);
        $tokenHash = hash('sha256', $token);

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $preview, $tokenHash, $override) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $preview['filters']['language'], $override);
            $this->checkOwner($user, $preview);
            $existing = BulkOperation::where('type', 'filtered_validation')->where('user_id', $user->id)
                ->where('preview->token_hash', $tokenHash)->first();
            if ($existing) {
                if ($existing->override !== $override) {
                    $this->stale();
                }

                return $existing;
            }
            if ($this->generation() !== $preview['generation'] || $preview['created_at'] < now()->subMinutes(30)->timestamp
                || $preview['created_at'] > now()->timestamp) {
                $this->stale();
            }
            if ($preview['total'] < 1) {
                throw ValidationException::withMessages(['validation' => __('ui.filtered_empty')]);
            }
            if (BulkOperation::where('type', 'filtered_validation')->where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['validation' => __('ui.filtered_already_running')]);
            }
            $preview['token_hash'] = $tokenHash;
            $operation = BulkOperation::create([
                'type' => 'filtered_validation', 'user_id' => $user->id, 'import_id' => null,
                'language' => $preview['filters']['language'], 'override' => $override, 'status' => 'queued',
                'max_proposal_id' => $preview['max_proposal_id'], 'expected_generation' => $preview['generation'],
                'preview' => $preview,
                'counts' => ['total' => $preview['total'], 'examined' => 0, 'validated' => 0, 'excluded' => 0,
                    'reasons' => ['invalid' => 0, 'self' => 0, 'competing' => 0]],
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $this->audit->record('validation.filtered_queued', $operation, [],
                ['language' => $operation->language, 'total' => $preview['total'], 'override' => $override], $user);

            return $operation;
        }));
    }

    /** The worker must hold the exclusive OperationLock until this checkpoint commits. */
    public function process(BulkOperation $operation, int $batchSize = 300): bool
    {
        $batchSize = max(1, min(300, $batchSize));
        try {
            return DB::transaction(function () use ($operation, $batchSize) {
                $operation = BulkOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                abort_unless($operation->type === 'filtered_validation', 422);
                if ($operation->status === 'completed') {
                    return true;
                }
                if (! in_array($operation->status, ['queued', 'processing'], true)) {
                    return false;
                }
                $user = User::whereKey($operation->user_id)->lockForUpdate()->firstOrFail();
                $this->authorize($user, $operation->language, $operation->override);
                $this->checkOwner($user, $operation->preview);
                if ($this->generation() !== (int) $operation->expected_generation) {
                    $this->stale();
                }
                $operation->status = 'processing';
                $operation->started_at ??= now();
                $query = $this->filter->query($user, $operation->preview['filters'])
                    ->where('proposals.id', '<=', $operation->max_proposal_id);
                $proposals = (clone $query)->with('term.sourceImport')->select('proposals.*')
                    ->selectSub(fn ($query) => $query->from('proposals as concurrent')->selectRaw('COUNT(*)')
                        ->whereColumn('concurrent.term_id', 'proposals.term_id')->whereColumn('concurrent.attribute', 'proposals.attribute')
                        ->whereColumn('concurrent.language', 'proposals.language')->where('concurrent.status', 'pending'), 'pending_cell_count')
                    ->where('proposals.id', '>', $operation->cursor)->orderBy('proposals.id')->limit($batchSize)->get();
                $selection = [];
                $attributes = [];
                $counts = $operation->counts;
                foreach ($proposals as $proposal) {
                    $operation->cursor = $proposal->id;
                    $counts['examined']++;
                    $reason = $this->exclusion($proposal, $user, $operation->override, $attributes);
                    if ($reason === null) {
                        $selection[$proposal->id] = $proposal->lock_version;
                    } else {
                        $counts['excluded']++;
                        $counts['reasons'][$reason]++;
                    }
                }
                if ($selection) {
                    $this->workflow->bulkUnderOperationLock($user, $selection, 'validate', null, $operation->override);
                    $counts['validated'] += count($selection);
                }
                $operation->counts = $counts;
                $operation->expected_generation = $this->generation();
                $operation->updated_by = $user->id;
                $hasMore = $query->where('proposals.id', '>', $operation->cursor)->exists();
                if (! $hasMore) {
                    if ($counts['examined'] !== (int) $operation->preview['total']) {
                        $this->stale();
                    }
                    $operation->status = 'completed';
                    $operation->finished_at = now();
                }
                $operation->save();
                $this->audit->record($hasMore ? 'validation.filtered_progress' : 'validation.filtered_completed', $operation, [],
                    ['language' => $operation->language, 'cursor' => $operation->cursor, 'counts' => $counts], $user);

                return ! $hasMore;
            });
        } catch (Throwable $exception) {
            $operation->refresh();
            $error = $exception instanceof ValidationException
                ? LocalizedMessage::store(array_merge(...array_values($exception->errors())))
                : OperationFailure::capture($exception, $operation, 'ui.filtered_failed');
            $operation->update(['status' => 'failed', 'error' => $error, 'updated_by' => $operation->user_id]);
            $this->audit->record('validation.filtered_failed', $operation, [], ['counts' => $operation->counts, 'error' => $error], $operation->user);

            return false;
        }
    }

    public function retry(User $user, BulkOperation $operation): void
    {
        $this->lock->run(fn () => DB::transaction(function () use ($user, $operation) {
            $operation = BulkOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless($operation->type === 'filtered_validation' && $operation->user_id === $user->id, 403);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $operation->language, $operation->override);
            $this->checkOwner($user, $operation->preview);
            abort_unless($operation->status === 'failed', 409);
            if ($this->generation() !== (int) $operation->expected_generation) {
                $this->stale();
            }
            if (BulkOperation::where('type', 'filtered_validation')->where('user_id', $user->id)
                ->where('id', '!=', $operation->id)->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['validation' => __('ui.filtered_already_running')]);
            }
            $operation->update(['status' => 'queued', 'error' => null, 'updated_by' => $user->id]);
            $this->audit->record('validation.filtered_retried', $operation, [], ['cursor' => $operation->cursor, 'counts' => $operation->counts], $user);
        }));
    }

    private function exclusion(Proposal $proposal, User $user, bool $override, array &$attributesCache): ?string
    {
        if ($proposal->author_id === $user->id && ! $override) {
            return 'self';
        }
        // A competitor outside the filter still requires an explicit individual choice.
        if ((int) $proposal->pending_cell_count > 1) {
            return 'competing';
        }
        $attributes = $attributesCache[$proposal->term_id] ??= $proposal->term->attributesForDisplay();
        $reference = $attributes[$proposal->attribute]['reference'] ?? null;
        if ($reference === null || ! isset($attributes[$proposal->attribute]['columns'][$proposal->language])) {
            return 'invalid';
        }

        return TranslationValidator::validate($reference, $proposal->value)['errors'] ? 'invalid' : null;
    }

    private function authorize(User $user, string $language, bool $override = false): void
    {
        abort_unless(in_array($language, ['de', 'fr', 'it', 'en'], true) && $user->canValidate($language), 403);
        abort_if($override && ! $user->canManage(), 403);
    }

    private function checkOwner(User $user, array $preview): void
    {
        abort_unless($preview['user_id'] === $user->id, 403);
        if (($preview['organization_id'] ?? null) !== $user->organization_id) {
            $this->stale();
        }
    }

    private function decode(string $token): array
    {
        try {
            $preview = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->stale();
        }
        if (! is_array($preview) || ! is_array($preview['filters'] ?? null) || ! is_string($preview['filters']['language'] ?? null)) {
            $this->stale();
        }
        foreach (['user_id', 'total', 'generation', 'max_proposal_id', 'created_at'] as $key) {
            if (! is_int($preview[$key] ?? null) || $preview[$key] < 0) {
                $this->stale();
            }
        }

        return $preview;
    }

    private function generation(): int
    {
        return (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['validation' => __('ui.filtered_stale')]);
    }
}
