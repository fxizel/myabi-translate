<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Destructive schema resets are restricted to an explicitly disposable local test database. */
trait GuardedSchemaReset
{
    protected function assertIsolatedTestDatabase(): void
    {
        $connection = DB::connection();
        if (! $this->app->environment('testing') || env('MYABI_ISOLATED_TESTS') !== '1'
            || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || ! str_starts_with($connection->getDatabaseName(), 'myabi_test_')
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new LogicException('Schema resets require an explicitly isolated local myabi_test_ database in the testing environment.');
        }
    }

    protected function resetIsolatedTestSchema(): void
    {
        $this->assertIsolatedTestDatabase();
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->assertSuccessful();
        $this->app[Kernel::class]->setArtisan(null);
    }
}
