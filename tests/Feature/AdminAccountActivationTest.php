<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        $organization = Organisation::create([
            'name' => 'Police test', 'code' => 'P-'.bin2hex(random_bytes(4)), 'active' => true,
        ]);

        return User::factory()->create(array_merge([
            'organization_id' => $organization->id, 'roles' => ['reader' => ['fr']],
            'password' => 'Original-password-123', 'email_verified_at' => null,
            'active' => true, 'is_technical' => false,
        ], $attributes))->refresh();
    }

    private function admin(): User
    {
        return $this->user([
            'roles' => ['admin' => ['de', 'fr', 'it']],
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['synthetic-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function passwordInput(array $overrides = []): array
    {
        return array_replace([
            'password' => 'Administrator-password-456',
            'password_confirmation' => 'Administrator-password-456',
        ], $overrides);
    }

    private function newUserInput(User $admin, array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ADA@example.test',
            'organization_id' => $admin->organization_id, 'roles' => ['reader' => ['fr']],
        ], $overrides);
    }

    public function test_administrator_activates_account_and_revokes_previous_credentials_without_verifying_email(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $target = $this->user(['active' => false, 'failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)]);
        $unrelated = $this->user();
        $unrelatedBefore = $unrelated->getRawOriginal();
        app(InvitationService::class)->send($target);
        $invitationUrl = null;
        Notification::assertSentTo($target, AccountInvitation::class, function ($notification) use (&$invitationUrl) {
            $invitationUrl = $notification->url;

            return true;
        });
        $resetToken = Password::broker()->createToken($target);
        $rememberToken = $target->remember_token;
        foreach (['target-session' => $target->id, 'unrelated-session' => $unrelated->id] as $id => $userId) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp,
            ]);
        }

        $this->actingAs($admin)->from('/admin')->post(route('admin.users.activate', $target), $this->passwordInput([
            'user_id' => $unrelated->id, 'email' => $unrelated->email,
        ]))->assertRedirect('/admin')->assertSessionHasNoErrors();

        $updated = $target->fresh();
        $this->assertTrue($updated->active);
        $this->assertTrue($updated->isLoginAllowed());
        $this->assertTrue(Hash::check('Administrator-password-456', $updated->password));
        $this->assertFalse(Hash::check('Original-password-123', $updated->password));
        $this->assertNotNull($updated->invitation_accepted_at);
        $this->assertNull($updated->email_verified_at);
        $this->assertNull($updated->invitation_token);
        $this->assertNull($updated->invitation_expires_at);
        $this->assertSame(0, $updated->failed_login_attempts);
        $this->assertNull($updated->locked_until);
        $this->assertSame(2, $updated->session_version);
        $this->assertSame($admin->id, $updated->updated_by);
        $this->assertNotSame($rememberToken, $updated->remember_token);
        $this->assertNotEmpty($updated->remember_token);
        $this->assertFalse(Password::broker()->tokenExists($updated, $resetToken));
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session', 'user_id' => $unrelated->id]);
        $this->assertSame($unrelatedBefore, $unrelated->fresh()->getRawOriginal());
        $this->assertAuthenticatedAs($admin);
        $activation = AuditEvent::where('action', 'account.activated')->sole();
        $this->assertSame($admin->id, $activation->user_id);
        $this->assertSame((string) $target->id, $activation->entity_id);
        $this->assertSame('administrator', $activation->after_values['method']);
        $passwordEvent = AuditEvent::where('action', 'security.password_set_by_admin')->sole();
        $this->assertSame($admin->id, $passwordEvent->user_id);
        $this->assertSame((string) $target->id, $passwordEvent->entity_id);
        $auditJson = AuditEvent::all()->toJson();
        foreach (['Administrator-password-456', 'Original-password-123', $updated->password, $resetToken, $rememberToken] as $secret) {
            $this->assertStringNotContainsString($secret, $auditJson);
        }

        $this->post('/logout');
        $this->get($invitationUrl)->assertNotFound();
        $this->post('/reset-password', $this->passwordInput(['email' => $target->email, 'token' => $resetToken]))
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $target->email, 'password' => 'Administrator-password-456'])->assertRedirect('/');
        $this->assertAuthenticatedAs($target);
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_leaves_account_unchanged_and_never_flashes_secrets(array $overrides): void
    {
        $admin = $this->admin();
        $target = $this->user(['active' => false, 'invitation_token' => hash('sha256', 'pending-invitation')]);
        $before = $target->getRawOriginal();
        $resetToken = Password::broker()->createToken($target);

        $this->actingAs($admin)->from('/admin')->post(route('admin.users.activate', $target), $this->passwordInput($overrides))
            ->assertRedirect('/admin')->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertTrue(Password::broker()->tokenExists($target, $resetToken));
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.activated']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_set_by_admin']);
    }

    public static function invalidPasswords(): array
    {
        return [
            'missing password' => [['password' => null]],
            'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
            'not a string' => [['password' => ['Administrator-password-456']]],
            'missing confirmation' => [['password_confirmation' => null]],
            'different confirmation' => [['password_confirmation' => 'Different-password-789']],
        ];
    }

    public function test_activation_requires_an_administrator_with_completed_two_factor_enrollment(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $target = $this->user(['active' => false]);
        $before = $target->getRawOriginal();
        $url = route('admin.users.activate', $target);
        $this->post($url, $this->passwordInput())->assertRedirect(route('login'));
        $this->actingAs($this->user())->post($url, $this->passwordInput())->assertForbidden();
        $manager = $this->admin();
        $manager->update(['roles' => ['manager' => ['fr']]]);
        $this->actingAs($manager)->post($url, $this->passwordInput())->assertForbidden();
        $unenrolledAdmin = $this->user(['roles' => ['admin' => ['fr']]]);
        $this->actingAs($unenrolledAdmin)->post($url, $this->passwordInput())->assertRedirect(route('profile.edit'));

        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_set_by_admin']);
    }

    public function test_technical_accounts_and_inactive_organizations_cannot_be_activated(): void
    {
        $admin = $this->admin();
        $technical = $this->user(['is_technical' => true]);
        $technicalBefore = $technical->getRawOriginal();
        $target = $this->user(['active' => false]);
        $target->organization->update(['active' => false]);
        $before = $target->getRawOriginal();

        $this->actingAs($admin)->post(route('admin.users.activate', $technical), $this->passwordInput())->assertForbidden();
        $this->post(route('admin.users.activate', $target), $this->passwordInput())->assertSessionHasErrors('user');

        $this->assertSame($technicalBefore, $technical->fresh()->getRawOriginal());
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_set_by_admin']);
    }

    public function test_setting_password_preserves_existing_activation_and_two_factor_credentials(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $admin = $this->admin();
        $target = $this->admin();
        $target->forceFill(['invitation_accepted_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)])->save();
        $preserved = $target->only(['invitation_accepted_at', 'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);

        $this->actingAs($admin)->post(route('admin.users.activate', $target), $this->passwordInput())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals($preserved, $target->fresh()->only(array_keys($preserved)));
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.activated']);
        $this->assertDatabaseHas('audit_events', ['action' => 'security.password_set_by_admin', 'entity_id' => $target->id]);
        $this->post('/logout');
        $this->post('/login', ['email' => $target->email, 'password' => 'Administrator-password-456'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_administrator_can_create_an_active_account_with_password_and_no_invitation_email(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.store'), $this->newUserInput($admin, $this->passwordInput([
            'activate_without_email' => true, 'active' => false,
        ])))->assertRedirect()->assertSessionHasNoErrors();

        $created = User::where('email', 'ada@example.test')->sole();
        $this->assertSame('Ada Lovelace', $created->name);
        $this->assertTrue($created->active);
        $this->assertTrue($created->isLoginAllowed());
        $this->assertTrue(Hash::check('Administrator-password-456', $created->password));
        $this->assertNotNull($created->invitation_accepted_at);
        $this->assertNull($created->email_verified_at);
        $this->assertNull($created->invitation_token);
        $this->assertNull($created->invitation_expires_at);
        $this->assertSame($admin->id, $created->created_by);
        $this->assertSame($admin->id, $created->updated_by);
        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_events', ['action' => 'account.created', 'entity_id' => $created->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.activated', 'entity_id' => $created->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'security.password_set_by_admin', 'entity_id' => $created->id]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.invited', 'entity_id' => $created->id]);
        $this->assertStringNotContainsString('Administrator-password-456', AuditEvent::all()->toJson());
    }

    public function test_manual_creation_requires_a_valid_confirmed_password_and_does_not_flash_it(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->from('/admin')->post(route('admin.users.store'), $this->newUserInput($admin, [
            'activate_without_email' => true,
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertRedirect('/admin')->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.test']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.created']);
        Notification::assertNothingSent();
    }

    public function test_default_creation_keeps_invitation_workflow_and_rejects_an_unexpected_password(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.store'), $this->newUserInput($admin, $this->passwordInput()))
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.test']);
        Notification::assertNothingSent();

        $this->post(route('admin.users.store'), $this->newUserInput($admin))->assertRedirect()->assertSessionHasNoErrors();
        $created = User::where('email', 'ada@example.test')->sole();
        $this->assertFalse($created->isLoginAllowed());
        $this->assertNull($created->invitation_accepted_at);
        $this->assertNotNull($created->invitation_token);
        Notification::assertSentTo($created, AccountInvitation::class);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.activated']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_set_by_admin']);
    }

    public function test_manually_activated_privileged_account_still_requires_two_factor_enrollment(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $admin = $this->admin();
        $target = $this->user(['active' => false, 'roles' => ['admin' => ['fr']]]);
        $this->actingAs($admin)->post(route('admin.users.activate', $target), $this->passwordInput())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => $target->email, 'password' => 'Administrator-password-456'])->assertRedirect('/');
        $this->assertAuthenticatedAs($target);
        $this->get('/admin')->assertRedirect(route('profile.edit'));
        $this->assertNull($target->fresh()->two_factor_confirmed_at);
    }

    public function test_activation_attempts_are_rate_limited(): void
    {
        $target = $this->user(['active' => false]);
        $this->actingAs($this->admin());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.users.activate', $target), $this->passwordInput(['password' => 'short']))
                ->assertSessionHasErrors('password');
        }
        $this->post(route('admin.users.activate', $target), $this->passwordInput())->assertTooManyRequests();
        $this->assertFalse($target->fresh()->active);
        $this->assertTrue(Hash::check('Original-password-123', $target->fresh()->password));
    }

    public function test_setting_own_administrator_password_revokes_current_session(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.activate', $admin), $this->passwordInput())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Administrator-password-456', $admin->fresh()->password));
        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'security.password_set_by_admin', 'user_id' => $admin->id]);
    }

    public function test_administration_exposes_localized_creation_and_activation_password_forms(): void
    {
        $admin = $this->admin();
        $target = $this->user(['active' => false]);
        $this->actingAs($admin);

        foreach (['fr', 'de', 'it'] as $locale) {
            $admin->update(['locale' => $locale]);
            $this->get('/admin')->assertOk()
                ->assertSee(route('admin.users.activate', $target), false)
                ->assertSee('name="activate_without_email"', false)
                ->assertSee('name="password_confirmation"', false)
                ->assertSee('autocomplete="new-password"', false)
                ->assertSee('minlength="'.config('auth.password_min_length').'"', false)
                ->assertDontSee('ui.activate_without_email')
                ->assertDontSee('ui.activate_with_password')
                ->assertDontSee('ui.manual_activation_help');
        }
    }
}
