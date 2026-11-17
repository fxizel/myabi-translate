<?php

namespace App\Services\CatalogueSearch;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IndexStatus
{
    public const READY = 'ready';

    public const NOT_PREPARED = 'not_prepared';

    public const UNAVAILABLE = 'unavailable';

    public function check(): string
    {
        if (! Schema::hasTable('catalogue_search_state')) {
            return self::NOT_PREPARED;
        }

        $state = DB::table('catalogue_search_state')->where('id', 1)->first();
        if (! $state?->references_ready) {
            return self::NOT_PREPARED;
        }

        return $this->isReady($state) ? self::READY : self::UNAVAILABLE;
    }

    public function isReady(object $state): bool
    {
        return $state->references_ready && $state->ready && $state->active_generation
            && (int) $state->normalization_version === Profile::VERSION
            && ! DB::table('catalogue_search_dirty')->exists();
    }
}
