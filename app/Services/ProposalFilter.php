<?php

namespace App\Services;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The validation queue and filtered decisions share the same server-side scope. */
class ProposalFilter
{
    public function fromQuery(User $user, array $parameters): array
    {
        $filters = validator($parameters, [
            'language' => 'nullable|in:de,fr,it,en',
            'q' => 'nullable|string|max:250',
            'type' => 'nullable|in:'.implode(',', array_keys(FormatRegistry::all())),
            'context' => 'nullable|string|max:255',
            'version_id' => 'nullable|integer',
            'organization_id' => 'nullable|integer',
            'import_id' => 'nullable|integer',
            'overdue' => 'nullable|boolean',
        ])->validate();
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $filters['language'] ??= $user->canManage() ? 'fr' : ($user->roleLanguages('validator')[0] ?? 'fr');
        if ($filters['overdue'] ?? false) {
            // Keep the membership fixed even when a background operation runs later.
            $filters['overdue_before'] = now()->subDays(30)->toDateTimeString();
        }
        unset($filters['overdue']);

        return $filters;
    }

    public function query(User $user, array $filters): Builder
    {
        $query = Proposal::where('status', 'pending')->where('language', $filters['language']);
        if (! $user->canManage()) {
            $query->whereIn('language', $user->roleLanguages('validator'));
        }
        if (isset($filters['import_id'])) {
            $query->where(fn ($origin) => $origin->where('import_id', $filters['import_id'])
                ->orWhereHas('imports', fn ($imports) => $imports->where('import_batches.id', $filters['import_id'])));
        }
        foreach (['type', 'context'] as $field) {
            if (isset($filters[$field])) {
                $query->whereHas('term', fn ($term) => $term->where($field, $filters[$field]));
            }
        }
        if (isset($filters['version_id'])) {
            $query->whereHas('term', fn ($term) => $term->whereIn('id', DB::table('import_rows')
                ->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')
                ->where('import_batches.version_id', $filters['version_id'])->where('status', 'applied')->select('term_id')));
        }
        if (isset($filters['organization_id'])) {
            // EXISTS keeps proposals shared by several imports counted exactly once.
            $query->where(fn ($origin) => $origin->where('organization_id', $filters['organization_id'])
                ->orWhereHas('import', fn ($import) => $import->where('organization_id', $filters['organization_id']))
                ->orWhereHas('imports', fn ($imports) => $imports->where('import_batches.organization_id', $filters['organization_id'])));
        }
        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->whereIn('term_id', DB::table('term_search')->select('term_id')->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (isset($filters['overdue_before'])) {
            $query->where('created_at', '<=', $filters['overdue_before']);
        }

        return $query;
    }
}
