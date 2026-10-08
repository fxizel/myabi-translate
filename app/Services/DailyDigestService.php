<?php

namespace App\Services;

use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonInterface;

class DailyDigestService
{
    public function counts(User $user, ?CarbonInterface $until = null): array
    {
        // The first successful delivery must also include decisions retained through an outage.
        $since = $user->last_digest_at ?? $user->created_at->copy()->subSecond();
        $until ??= now();
        $counts = [];
        if ($user->canValidate()) {
            $pending = Proposal::where('status', 'pending');
            if (! $user->canManage()) {
                $pending->whereIn('language', $user->roleLanguages('validator'));
            }
            $counts['pending'] = (clone $pending)->count();
            $counts['overdue'] = (clone $pending)->where('updated_at', '<=', now()->subDays(30))->count();
        }
        if ($user->hasRole('translator') || $user->canValidate()) {
            $decisions = Proposal::where('author_id', $user->id)->where('decided_at', '>', $since)->where('decided_at', '<=', $until);
            $counts['validated'] = (clone $decisions)->where('status', 'validated')->count();
            $counts['rejected'] = (clone $decisions)->where('status', 'rejected')->count();
        }
        if ($user->canManage()) {
            $counts['imports'] = ImportBatch::where('status', 'applied')->where('updated_at', '>', $since)->where('updated_at', '<=', $until)->count();
            $counts['failed_imports'] = ImportBatch::where('status', 'failed')->where('updated_at', '>', $since)->where('updated_at', '<=', $until)->count();
            $counts['obsolete'] = Term::where('obsolete', true)->where('updated_at', '>', $since)->where('updated_at', '<=', $until)->count();
            $attributes = [];
            foreach (FormatRegistry::all() as $format) {
                $attributes = array_merge($attributes, $format->attributeNames);
            }
            $counts['review'] = Term::where('obsolete', false)->where(function ($query) use ($attributes) {
                foreach (array_unique($attributes) as $attribute) {
                    foreach (User::LANGUAGES as $language) {
                        $query->orWhere('review_needed->'.$attribute.'->'.$language, true);
                    }
                }
            })->count();
        }

        return array_filter($counts, fn ($count) => $count > 0);
    }
}
