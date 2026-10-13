<?php

namespace App\Services;

use App\Models\Term;
use App\Models\TermGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GroupService
{
    public function __construct(private OperationLock $lock) {}

    public function create(User $user, array $data): TermGroup
    {
        abort_unless($user->canManage(), 403);

        return $this->lock->run(fn () => DB::transaction(fn () => TermGroup::create($this->values($data) + ['organization_id' => $user->organization_id, 'created_by' => $user->id, 'updated_by' => $user->id])));
    }

    public function update(User $user, TermGroup $group, array $data): TermGroup
    {
        abort_unless($user->canManage(), 403);

        return $this->lock->run(fn () => DB::transaction(function () use ($user, $group, $data) {
            $group = TermGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $group->update($this->values($data) + ['updated_by' => $user->id]);

            return $group;
        }));
    }

    public function assign(User $user, Term $term, ?TermGroup $group): void
    {
        $this->assignTerms($user, $group, [$term->id]);
    }

    public function assignTerms(User $user, ?TermGroup $group, array $termIds): void
    {
        abort_unless($user->canManage(), 403);
        $this->lock->run(fn () => DB::transaction(function () use ($user, $group, $termIds) {
            if ($group !== null) {
                $group = TermGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
                abort_if($group->archived_at !== null, 422);
            }
            $ids = array_unique(array_map('intval', $termIds));
            abort_if(count($ids) > 500, 422);
            $terms = Term::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($terms->count() === count($ids), 404);
            $campaigns = app(CampaignService::class);
            foreach ($terms as $term) {
                if ($term->term_group_id !== $group?->id) {
                    $term->update(['term_group_id' => $group?->id, 'updated_by' => $user->id]);
                    $campaigns->synchronizeForTerm($term, $user);
                }
            }
            DB::table('catalogue_state')->where('id', 1)->increment('generation');
        }, 3));
    }

    public function archive(User $user, TermGroup $group): void
    {
        $this->archiveState($user, $group, true);
    }

    public function unarchive(User $user, TermGroup $group): void
    {
        $this->archiveState($user, $group, false);
    }

    private function archiveState(User $user, TermGroup $group, bool $archive): void
    {
        abort_unless($user->canManage(), 403);
        $this->lock->run(fn () => DB::transaction(fn () => TermGroup::whereKey($group->id)->lockForUpdate()->firstOrFail()->update(['archived_at' => $archive ? now() : null, 'updated_by' => $user->id])));
    }

    public function delete(User $user, TermGroup $group): void
    {
        abort_unless($user->canManage(), 403);
        $this->lock->run(fn () => DB::transaction(function () use ($group) {
            $group = TermGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_if($group->terms()->exists() || $group->campaigns()->exists(), 422);
            $group->delete();
        }));
    }

    private function values(array $data): array
    {
        return Validator::make($data + ['color' => TermGroup::COLORS[0]], ['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'], 'color' => ['required', Rule::in(TermGroup::COLORS)]])->validate();
    }
}
