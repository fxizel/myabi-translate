<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ProfileController;
use App\Models\Organisation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OperationLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\DatabaseProcess;
use Tests\TestCase;

class AdminConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB row locks and independent committed connections.');
        }
    }

    #[DataProvider('concurrentChanges')]
    public function test_two_administrators_cannot_both_lose_access(string $firstAction, string $secondAction): void
    {
        $directory = storage_path('framework/testing/admin-concurrency-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
        $administrators = [];
        foreach (['FIRST', 'SECOND'] as $code) {
            $organization = Organisation::create(['name' => 'Synthetic '.$code, 'code' => $code]);
            $administrators[] = User::factory()->create([
                'organization_id' => $organization->id, 'roles' => ['admin' => ['fr']],
                'invitation_accepted_at' => now(),
            ]);
        }
        [$first, $second] = $administrators;
        $process = null;
        DB::beginTransaction();
        try {
            $request = Request::create('/admin', 'PATCH', $firstAction === 'user' ? [
                'first_name' => 'Synthetic', 'last_name' => 'First', 'email' => $first->email,
                'organization_id' => $first->organization_id, 'roles' => ['reader' => ['fr']], 'active' => true,
            ] : ['name' => 'Synthetic first', 'code' => 'FIRST', 'active' => false]);
            $request->setUserResolver(fn () => $first);
            if ($firstAction === 'user') {
                app(AdminController::class)->updateUser($request, $first);
            } else {
                app(AdminController::class)->updateOrganisation($request, $first->organization, app(OperationLock::class));
            }
            $process = DatabaseProcess::start(<<<'PHP'
                $user = \App\Models\User::findOrFail($parameters['id']);
                $organization = $user->organization;
                $request = \Illuminate\Http\Request::create('/admin', 'PATCH', $parameters['action'] === 'user' ? [
                    'first_name' => 'Synthetic', 'last_name' => 'Second', 'email' => $user->email,
                    'organization_id' => $user->organization_id, 'roles' => ['reader' => ['fr']], 'active' => true,
                ] : ['name' => 'Synthetic second', 'code' => $organization->code, 'active' => false]);
                $app->instance('request', $request);
                $request->setUserResolver(fn () => $user);
                echo 'connection:'.\Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
                try {
                    if ($parameters['action'] === 'user') {
                        app(\App\Http\Controllers\AdminController::class)->updateUser($request, $user);
                    } else {
                        app(\App\Http\Controllers\AdminController::class)->updateOrganisation($request, $organization, app(\App\Services\OperationLock::class));
                    }
                    echo "unexpected-success\n";
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    echo 'rejected:'.implode(',', array_keys($exception->errors()))."\n";
                }
                PHP, ['id' => $second->id, 'action' => $secondAction]);
            DatabaseProcess::awaitOutput($process, 'connection:');
            preg_match('/connection:(\d+)/', $process->getOutput(), $matches);
            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.PROCESSLIST WHERE ID = ? AND INFO LIKE '%users%for update%'", [(int) $matches[1]])->total;
                if ($waiting) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline && $process->isRunning());
            $this->assertGreaterThan(0, (int) $waiting, 'The second connection must actually wait for the shared administration locks. '.$process->getOutput().$process->getErrorOutput());
            DB::commit();
            $this->assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('rejected:'.($secondAction === 'user' ? 'roles' : 'active'), $process->getOutput());
            $this->assertTrue($second->fresh()->hasRole('admin'));
            $this->assertTrue($second->organization->fresh()->active);
            $this->assertFalse($first->fresh()->hasRole('admin') && $first->organization->fresh()->active);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
        }
    }

    public static function concurrentChanges(): array
    {
        return [['user', 'user'], ['organization', 'organization'], ['user', 'organization'], ['organization', 'user']];
    }

    public function test_profile_audit_does_not_deadlock_with_an_administrative_change(): void
    {
        $organization = Organisation::create(['name' => 'Synthetic police', 'code' => 'PROFILE']);
        $first = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['admin' => ['fr']]]);
        $second = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['admin' => ['fr']]]);
        $this->actingAs($first);
        $process = null;
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) use ($second, &$process) {
            $process = DatabaseProcess::start(<<<'PHP'
                $user = \App\Models\User::findOrFail($parameters['id']);
                auth()->setUser($user);
                $request = \Illuminate\Http\Request::create('/admin', 'PATCH', [
                    'first_name' => 'Synthetic', 'last_name' => 'Second', 'email' => $user->email,
                    'organization_id' => $user->organization_id, 'roles' => ['reader' => ['fr']], 'active' => true,
                ]);
                $request->setUserResolver(fn () => $user);
                echo 'connection:'.\Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
                app(\App\Http\Controllers\AdminController::class)->updateUser($request, $user);
                echo "completed\n";
                PHP, ['id' => $second->id]);
            $this->assertWaitingForAccountLock($process);

            return (new AuditService)->record(...$arguments);
        });
        $request = Request::create('/profile', 'PATCH', ['locale' => 'it']);
        $request->setUserResolver(fn () => $first);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('security.version', $first->session_version);
        try {
            app(ProfileController::class)->update($request);
            $this->assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame('it', $first->fresh()->locale);
            $this->assertFalse($second->fresh()->hasRole('admin'));
            $this->assertSame(1, DB::table('audit_events')->where('action', 'profile.updated')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'account.updated')->count());
        } finally {
            $process?->stop();
        }
    }

    public function test_mutual_session_revocations_lock_actors_before_audit_foreign_keys(): void
    {
        $organization = Organisation::create(['name' => 'Synthetic police', 'code' => 'MUTUAL']);
        $first = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['admin' => ['fr']]]);
        $second = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['admin' => ['fr']]]);
        $this->actingAs($first);
        $process = null;
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) use ($first, $second, &$process) {
            $process = DatabaseProcess::start(<<<'PHP'
                $actor = \App\Models\User::findOrFail($parameters['actor']);
                $target = \App\Models\User::findOrFail($parameters['target']);
                auth()->setUser($actor);
                $request = \Illuminate\Http\Request::create('/admin');
                $request->setUserResolver(fn () => $actor);
                echo 'connection:'.\Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
                app(\App\Http\Controllers\AdminController::class)->revokeSessions($request, $target);
                echo "completed\n";
                PHP, ['actor' => $second->id, 'target' => $first->id]);
            $this->assertWaitingForAccountLock($process);

            return (new AuditService)->record(...$arguments);
        });
        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $first);
        try {
            app(AdminController::class)->revokeSessions($request, $second);
            $this->assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame(2, $first->fresh()->session_version);
            $this->assertSame(2, $second->fresh()->session_version);
            $this->assertSame(2, DB::table('audit_events')->where('action', 'account.sessions_revoked')->count());
        } finally {
            $process?->stop();
        }
    }

    private function assertWaitingForAccountLock(Process $process): void
    {
        DatabaseProcess::awaitOutput($process, 'connection:');
        preg_match('/connection:(\d+)/', $process->getOutput(), $matches);
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.PROCESSLIST WHERE ID = ? AND INFO LIKE '%users%for update%'", [(int) $matches[1]])->total;
            if ($waiting) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $process->isRunning());
        $this->assertGreaterThan(0, (int) $waiting, $process->getOutput().$process->getErrorOutput());
    }

    public function test_security_changes_are_atomic_with_restricted_application_grants(): void
    {
        $connection = DB::connection();
        $default = DB::getDefaultConnection();
        $username = 'audit_security_'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(20));
        $account = $connection->getPdo()->quote($username)."@'%'";
        $schema = '`'.str_replace('`', '``', $connection->getDatabaseName()).'`';
        $user = User::factory()->create(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)]);
        DB::table('sessions')->insert(['id' => 'restricted-security-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $user);
        $connection->statement('CREATE USER '.$account.' IDENTIFIED BY '.$connection->getPdo()->quote($password));
        try {
            foreach (['users' => 'SELECT, UPDATE', 'sessions' => 'SELECT, DELETE', 'audit_events' => 'SELECT, INSERT'] as $table => $grants) {
                $connection->statement("GRANT {$grants} ON {$schema}.`{$table}` TO {$account}");
            }
            config(['database.connections.restricted_security' => array_replace($connection->getConfig(), [
                'username' => $username, 'password' => $password, 'url' => null, 'name' => 'restricted_security',
            ])]);
            DB::setDefaultConnection('restricted_security');
            $this->artisan('security:check-audit')->assertSuccessful();

            // A real INSERT privilege failure must roll back every preceding write.
            $connection->statement("REVOKE INSERT ON {$schema}.`audit_events` FROM {$account}");
            foreach (['unlock', 'revokeSessions'] as $method) {
                $before = $user->fresh()->getRawOriginal();
                try {
                    app(AdminController::class)->$method($request, $user);
                    $this->fail('A rejected audit INSERT must abort the security mutation.');
                } catch (QueryException $exception) {
                    $this->assertSame(1142, (int) $exception->errorInfo[1]);
                }
                $this->assertSame($before, $user->fresh()->getRawOriginal());
                $this->assertDatabaseHas('sessions', ['id' => 'restricted-security-session']);
            }
            $connection->statement("GRANT INSERT ON {$schema}.`audit_events` TO {$account}");
            app(AdminController::class)->unlock($request, $user);
            app(AdminController::class)->revokeSessions($request, $user);
            $this->assertNull($user->fresh()->locked_until);
            $this->assertSame(0, $user->fresh()->failed_login_attempts);
            $this->assertSame(2, $user->fresh()->session_version);
            $this->assertDatabaseMissing('sessions', ['id' => 'restricted-security-session']);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'account.unlocked')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'account.sessions_revoked')->count());
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('restricted_security');
            $connection->statement('DROP USER '.$account);
        }
    }
}
