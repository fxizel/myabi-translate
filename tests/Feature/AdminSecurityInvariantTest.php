<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSecurityInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        $organization = Organisation::create(['name' => 'Synthetic police', 'code' => bin2hex(random_bytes(4))]);

        return User::factory()->create($attributes + [
            'organization_id' => $organization->id, 'roles' => ['admin' => ['fr']],
            'invitation_accepted_at' => now(),
        ]);
    }

    private function userInput(User $user, array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Synthetic', 'last_name' => 'Administrator', 'email' => $user->email,
            'organization_id' => $user->organization_id, 'roles' => ['reader' => ['fr']], 'active' => true,
        ];
    }

    #[DataProvider('unavailableAdministrators')]
    public function test_unavailable_administrator_cannot_replace_the_last_available_one(array $attributes, bool $inactiveOrganization): void
    {
        $admin = $this->admin();
        $other = $this->admin($attributes);
        if ($inactiveOrganization) {
            $other->organization->update(['active' => false]);
        }
        $this->actingAs($admin)->patch(route('admin.users.update', $admin), $this->userInput($admin))
            ->assertRedirect()->assertSessionHasErrors('roles');
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.updated']);
    }

    public static function unavailableAdministrators(): array
    {
        return [
            'expired invitation' => [['invitation_accepted_at' => null, 'invitation_token' => 'expired', 'invitation_expires_at' => '2020-01-01'], false],
            'pending invitation' => [['invitation_accepted_at' => null, 'invitation_token' => 'pending', 'invitation_expires_at' => '2037-01-01'], false],
            'inactive organization' => [[], true],
            'inactive account' => [['active' => false], false],
            'technical account' => [['is_technical' => true], false],
            'no organization' => [['organization_id' => null], false],
        ];
    }

    public function test_last_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('admin.users.update', $admin), $this->userInput($admin, [
            'active' => false, 'roles' => $admin->roles,
        ]))->assertSessionHasErrors('roles');
        $this->assertTrue($admin->fresh()->active);
    }

    public function test_last_administrator_organization_cannot_be_deactivated(): void
    {
        $admin = $this->admin();
        $generation = DB::table('catalogue_state')->value('generation');
        $this->actingAs($admin)->patch(route('admin.organisations.update', $admin->organization), [
            'name' => 'Synthetic police', 'code' => $admin->organization->code, 'active' => false,
        ])->assertSessionHasErrors('active');
        $this->assertTrue($admin->organization->fresh()->active);
        $this->assertSame($generation, DB::table('catalogue_state')->value('generation'));
        $this->assertDatabaseMissing('audit_events', ['action' => 'organization.updated']);
    }

    public function test_another_activated_administrator_allows_changes_despite_a_temporary_login_lock(): void
    {
        $admin = $this->admin();
        $other = $this->admin(['locked_until' => now()->addMinutes(15), 'failed_login_attempts' => 5]);
        $this->actingAs($admin)->patch(route('admin.users.update', $admin), $this->userInput($admin))
            ->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->hasRole('admin'));
        $this->assertTrue($other->fresh()->hasRole('admin'));
    }

    public function test_organization_can_be_deactivated_when_an_administrator_remains_elsewhere(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->actingAs($admin)->patch(route('admin.organisations.update', $other->organization), [
            'name' => 'Synthetic police', 'code' => $other->organization->code, 'active' => false,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($other->organization->fresh()->active);
    }

    public function test_the_initial_administrator_cannot_be_made_unavailable_by_an_invitation(): void
    {
        Notification::fake();
        $admin = $this->admin(['invitation_accepted_at' => null, 'invitation_token' => null]);
        try {
            app(InvitationService::class)->send($admin);
            $this->fail('Inviting the last initial administrator must fail.');
        } catch (ValidationException) {
            $this->assertNull($admin->fresh()->invitation_token);
            $this->assertDatabaseMissing('audit_events', ['action' => 'account.invited']);
            Notification::assertNothingSent();
        }
    }

    #[DataProvider('securityActions')]
    public function test_security_mutations_roll_back_with_their_sessions_when_audit_fails(string $method, string $event): void
    {
        $admin = $this->admin();
        $target = $this->admin(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)]);
        $before = $target->fresh()->getRawOriginal();
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->mock(AuditService::class)->shouldReceive('record')->once()->with($event, \Mockery::type(User::class))
            ->andThrow(new \RuntimeException('Synthetic audit failure'));
        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $admin);
        try {
            app(AdminController::class)->$method($request, $target);
            $this->fail('Audit failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseHas('sessions', ['id' => 'synthetic-session']);
        $this->assertDatabaseMissing('audit_events', ['action' => $event]);
    }

    public static function securityActions(): array
    {
        return [['unlock', 'account.unlocked'], ['revokeSessions', 'account.sessions_revoked']];
    }

    public function test_security_actions_reload_stale_accounts_and_write_one_event_each(): void
    {
        $admin = $this->admin();
        $target = $this->admin();
        User::whereKey($target->id)->update(['session_version' => 8, 'failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)]);
        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $admin);
        app(AdminController::class)->revokeSessions($request, $target);
        app(AdminController::class)->unlock($request, $target);
        $this->assertSame(9, $target->fresh()->session_version);
        $this->assertSame(0, $target->fresh()->failed_login_attempts);
        $this->assertNull($target->fresh()->locked_until);
        $this->assertSame(1, AuditEvent::where('action', 'account.sessions_revoked')->count());
        $this->assertSame(1, AuditEvent::where('action', 'account.unlocked')->count());
    }
}
