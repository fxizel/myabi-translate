<?php

namespace App\Services\BrowserExtension;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IndexStatus
{
    public function check(): bool
    {
        foreach ([SearchProjection::STATE, SearchProjection::DIRTY, SearchProjection::VALUES, SearchProjection::TOKENS] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return DB::transaction(fn () => $this->isReady(
            DB::table(SearchProjection::STATE)->where('id', 1)->sharedLock()->first(),
        ), 3);
    }

    public function isReady(?object $state): bool
    {
        return $state?->ready && $state->active_generation
            && (int) $state->normalization_version === TextNormalizer::VERSION
            && ! DB::table(SearchProjection::DIRTY)->exists();
    }
}
