<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/** Committed connections need fresh disposable schemas, without reversing business migrations. */
trait IsolatedDatabaseMigrations
{
    use DatabaseMigrations;
    use GuardedSchemaReset;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->resetIsolatedTestSchema();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function () {
            $this->resetIsolatedTestSchema();
            DB::disconnect();
        });
    }
}
