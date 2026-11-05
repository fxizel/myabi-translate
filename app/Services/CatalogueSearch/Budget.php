<?php

namespace App\Services\CatalogueSearch;

use Illuminate\Support\Facades\DB;

final class Budget
{
    private float $deadline;

    private int $initialMemory;

    public function __construct(?float $started = null)
    {
        $this->deadline = ($started ?? hrtime(true) / 1e9) + (float) config('catalogue-search.work_seconds');
        $this->initialMemory = memory_get_usage(true);
    }

    public function check(): void
    {
        if (hrtime(true) / 1e9 >= $this->deadline || memory_get_usage(true) - $this->initialMemory > config('catalogue-search.max_memory_bytes')) {
            throw new SearchStopped;
        }
    }

    /** Restore session settings even after cancellation; no limit leaks into business writes. */
    public function sql(callable $callback, ?float $statementSeconds = null): mixed
    {
        $this->check();
        $mysql = in_array(DB::getDriverName(), ['mariadb', 'mysql']);
        $previous = $mysql ? DB::selectOne('SELECT @@max_statement_time AS timeout')->timeout : null;
        try {
            if ($mysql) {
                $seconds = max(0.001, min($statementSeconds ?? (float) config('catalogue-search.statement_seconds'), $this->deadline - hrtime(true) / 1e9));
                DB::statement('SET SESSION max_statement_time = '.sprintf('%.6F', $seconds));
            }
            $result = $callback();
            $this->check();

            return $result;
        } finally {
            if ($mysql) {
                DB::statement('SET SESSION max_statement_time = '.(float) $previous);
            }
        }
    }
}
