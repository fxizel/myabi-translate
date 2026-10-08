<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\AuditService;
use App\Services\InvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fortify.mfa_enabled' => true]);
    }

    private function user(array $roles = ['reader' => ['de', 'fr', 'it']], array $attributes = []): User
    {
        $organization = Organisation::create(['name' => 'Police test', 'code' => 'P-'.bin2hex(random_bytes(4)), 'active' => true]);

        return User::factory()->create(array_merge([
            'organization_id' => $organization->id, 'roles' => $roles, 'password' => 'Long-password-123',
            'active' => true, 'is_technical' => false,
        ], $attributes));
    }

    private function confirmTotp(User $user): string
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    }

    public function test_local_login_logs_success_and_ignores_persistent_sign_in(): void
    {
        $user = $this->user();
        $response = $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'Long-password-123', 'remember' => true])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'auth.login_succeeded']);
        $this->assertNotNull(session('security.started_at'));
        $response->assertCookieMissing(auth()->guard()->getRecallerName());
    }

    public function test_repeated_invalid_credentials_temporarily_lock_account_and_are_audited(): void
    {
        $user = $this->user();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->assertGuest();
        $this->assertTrue($user->fresh()->locked_until->isFuture());
        $this->assertSame(5, AuditEvent::where('action', 'auth.login_failed')->count());
        $this->travel(2)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertSessionHasErrors('email');
        $this->travel(15)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_disabled_and_technical_accounts_cannot_sign_in(): void
    {
        foreach ([['active' => false], ['is_technical' => true]] as $attributes) {
            $user = $this->user(attributes: $attributes);
            $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_privileged_user_must_enroll_totp_before_using_application(): void
    {
        $user = $this->user(['admin' => ['de', 'fr', 'it']]);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('profile.edit'));
        $this->post('/profile/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/profile/two-factor', ['password' => 'Long-password-123'])->assertRedirect(route('profile.edit'));
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post('/profile/two-factor/confirm', ['code' => $code])->assertRedirect(route('profile.edit'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertCount(8, session('two_factor_recovery_codes'));
        $this->delete('/profile/two-factor', ['password' => 'Long-password-123'])->assertForbidden();
        $this->delete('/user/two-factor-authentication')->assertNotFound();
    }

    public function test_totp_challenge_requires_valid_factor_and_a_revoked_challenge_cannot_login(): void
    {
        $user = $this->user(['validator' => ['fr']]);
        $secret = $this->confirmTotp($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors();
        $user->increment('session_version');
        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect(route('two-factor.login'));
        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invitation_expires_at_72_hours_and_is_single_use(): void
    {
        Notification::fake();
        $user = $this->user();
        app(InvitationService::class)->send($user);
        $url = null;
        Notification::assertSentTo($user, AccountInvitation::class, function ($notification) use (&$url) {
            $url = $notification->url;

            return true;
        });
        $this->assertEqualsWithDelta(72, now()->diffInHours($user->fresh()->invitation_expires_at), 0.01);
        $this->post($url, ['email' => $user->email, 'password' => 'short12', 'password_confirmation' => 'short12'])->assertSessionHasErrors('password');
        $this->post($url, ['email' => $user->email, 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'])->assertRedirect(route('profile.edit'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->get($url)->assertNotFound();
        $other = $this->user();
        app(InvitationService::class)->send($other);
        Notification::assertSentTo($other, AccountInvitation::class, function ($notification) use (&$url) {
            $url = $notification->url;

            return true;
        });
        $this->travel(72)->hours();
        $this->get($url)->assertNotFound();
    }

    public function test_password_reset_has_minimum_length_is_single_use_and_revokes_sessions(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $input = ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'];
        $this->post('/reset-password', $input)->assertSessionHasErrors('password');
        $input['password'] = $input['password_confirmation'] = 'Changed-password-123';
        $this->post('/reset-password', $input)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('Changed-password-123', $user->fresh()->password));
        $this->assertSame(2, $user->fresh()->session_version);
        $this->post('/reset-password', $input)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_reissued_invitation_remains_audited_when_delivery_fails(): void
    {
        $user = $this->user();
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('Synthetic delivery failure'));
        try {
            app(InvitationService::class)->send($user);
            $this->fail('The synthetic delivery failure must propagate.');
        } catch (TransportException) {
            $this->assertNotNull($user->fresh()->invitation_token);
            $this->assertDatabaseHas('audit_events', ['entity_id' => $user->id, 'action' => 'account.invited']);
        }
    }

    public function test_sessions_expire_after_idle_hour_absolute_twelve_hours_or_revocation(): void
    {
        foreach ([
            ['started' => 100, 'last' => 3600, 'version' => 1],
            ['started' => 43200, 'last' => 0, 'version' => 1],
            ['started' => 0, 'last' => 0, 'version' => 0],
        ] as $case) {
            $user = $this->user();
            $this->actingAs($user)->withSession(['security' => [
                'started_at' => now()->timestamp - $case['started'], 'last_seen_at' => now()->timestamp - $case['last'],
                'version' => $case['version'],
            ]])->get('/profile')->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_role_languages_and_organization_boundaries_are_independent(): void
    {
        $own = Organisation::create(['name' => 'Own', 'code' => 'OWN']);
        $other = Organisation::create(['name' => 'Other', 'code' => 'OTHER']);
        $root = Organisation::create(['name' => 'ARGE-ABI', 'code' => 'ARGE', 'is_root' => true]);
        $user = $this->user(['translator' => ['it'], 'validator' => ['fr']], ['organization_id' => $own->id]);
        $this->assertTrue($user->canValidate('fr'));
        $this->assertFalse($user->canValidate('it'));
        $this->assertTrue($user->canTranslate('fr', $other->id));
        $this->assertFalse($user->canTranslate('it', $other->id));
        $this->assertTrue($user->canTranslate('it', $own->id));
        $this->assertTrue($user->canTranslate('it', $root->id));
        $this->assertFalse($user->canTranslate('de', null));
        $admin = $this->user(['admin' => ['de', 'fr', 'it']]);
        $this->assertFalse($admin->canManage());
        $this->assertFalse($admin->canValidate('fr'));
        $this->assertFalse($admin->canSeeOrganisation($other->id));
    }

    public function test_guest_can_choose_locale_but_revoked_session_cannot_mutate_profile(): void
    {
        $this->post('/locale', ['locale' => 'it'])->assertRedirect()->assertSessionHas('locale', 'it');
        $user = $this->user(attributes: ['locale' => 'fr']);
        $this->actingAs($user)->withSession(['security' => [
            'started_at' => now()->timestamp, 'last_seen_at' => now()->timestamp, 'version' => 0,
        ]])->post('/locale', ['locale' => 'de'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame('fr', $user->fresh()->locale);
        $this->assertDatabaseMissing('audit_events', ['user_id' => $user->id, 'action' => 'profile.locale_changed']);
    }

    public function test_role_changes_take_effect_on_the_next_request_and_readers_cannot_admin(): void
    {
        $admin = $this->user(['admin' => ['de', 'fr', 'it']]);
        $this->confirmTotp($admin);
        $this->actingAs($admin);
        User::whereKey($admin->id)->update(['roles' => ['reader' => ['de', 'fr', 'it']]]);
        $this->post('/admin/organisations', ['name' => 'Denied', 'code' => 'DENIED'])->assertForbidden();
        $this->get('/audit')->assertForbidden();
    }

    public function test_administrator_creates_nominative_account_and_a_single_use_invitation(): void
    {
        Notification::fake();
        $admin = $this->user(['admin' => ['de', 'fr', 'it']]);
        $this->confirmTotp($admin);
        $this->actingAs($admin)->post('/admin/users', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ADA@example.test',
            'organization_id' => $admin->organization_id, 'roles' => ['translator' => ['fr']],
        ])->assertRedirect();
        $invited = User::where('email', 'ada@example.test')->firstOrFail();
        $this->assertSame('Ada Lovelace', $invited->name);
        $this->assertFalse($invited->isLoginAllowed());
        Notification::assertSentTo($invited, AccountInvitation::class);
        $this->assertDatabaseHas('audit_events', ['entity_id' => $invited->id, 'action' => 'account.created']);
    }

    public function test_audit_redacts_secrets_and_database_rejects_rewrites(): void
    {
        $event = app(AuditService::class)->record('test.created', 'test', [], ['password' => 'secret', 'nested' => ['token' => 'secret']]);
        $this->assertSame('[redacted]', $event->after_values['password']);
        $this->assertSame('[redacted]', $event->after_values['nested']['token']);
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'forged']);
    }

    public function test_database_rejects_audit_deletion(): void
    {
        $event = app(AuditService::class)->record('test.created', 'test');
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_organization_changes_invalidate_import_analysis_and_are_blocked_by_heavy_work(): void
    {
        $testStorage = storage_path('framework/testing/auth-organizations-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($testStorage);
        mkdir($testStorage.'/app/private', 0700, true);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($testStorage));
        $admin = $this->user(['admin' => ['de', 'fr', 'it']]);
        $this->confirmTotp($admin);
        $generation = (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
        $this->actingAs($admin)->post('/admin/organisations', ['name' => 'Police Vaud', 'code' => 'VD'])->assertRedirect()->assertSessionHasNoErrors();
        $organization = Organisation::where('code', 'VD')->firstOrFail();
        $this->assertSame($generation + 1, (int) DB::table('catalogue_state')->where('id', 1)->value('generation'));
        $this->patch('/admin/organisations/'.$organization->id, ['name' => 'Police VD', 'code' => 'VD', 'active' => true])->assertRedirect();
        $this->assertSame($generation + 2, (int) DB::table('catalogue_state')->where('id', 1)->value('generation'));
        $path = storage_path('app/private/operations.lock');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $lock = fopen($path, 'c+');
        flock($lock, LOCK_EX);
        try {
            $this->post('/admin/organisations', ['name' => 'Police Genève', 'code' => 'GE'])->assertSessionHasErrors('operation');
            $this->patch('/admin/organisations/'.$organization->id, ['name' => 'Blocked', 'code' => 'VD'])->assertSessionHasErrors('operation');
            $this->assertDatabaseMissing('organizations', ['code' => 'GE']);
            $this->assertSame('Police VD', $organization->fresh()->name);
            $this->assertSame($generation + 2, (int) DB::table('catalogue_state')->where('id', 1)->value('generation'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
