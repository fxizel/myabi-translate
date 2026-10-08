<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class GlobalMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fortify.mfa_enabled' => false]);
    }

    private function user(string $role = 'reader', array $attributes = []): User
    {
        $organization = Organisation::create(['name' => 'Police test', 'code' => 'P-'.bin2hex(random_bytes(4)), 'active' => true]);

        return User::factory()->create(array_merge([
            'organization_id' => $organization->id, 'roles' => [$role => ['fr']],
            'password' => 'Long-password-123', 'locale' => 'fr', 'active' => true, 'is_technical' => false,
        ], $attributes))->refresh();
    }

    private function enroll(User $user, bool $confirmed = true): string
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => $confirmed ? now() : null])->save();

        return Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    }

    public function test_mfa_is_disabled_when_the_server_setting_is_absent(): void
    {
        $environment = Env::getRepository();
        $previous = $environment->get('MFA_ENABLED');
        $environment->clear('MFA_ENABLED');
        try {
            $configuration = require config_path('fortify.php');
            $this->assertFalse($configuration['mfa_enabled']);
        } finally {
            if ($previous !== null) {
                $environment->set('MFA_ENABLED', $previous);
            }
        }
    }

    #[DataProvider('rolesAndEnrollment')]
    public function test_all_roles_can_sign_in_without_a_challenge_when_mfa_is_disabled(string $role, string $url, bool $enrolled): void
    {
        $user = $this->user($role);
        if ($enrolled) {
            $this->enroll($user);
        }
        $credentials = $user->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])
            ->assertRedirect('/')->assertSessionMissing('login.id');
        $this->assertAuthenticatedAs($user);
        $this->get($url)->assertOk();
        $this->assertEquals($credentials, $user->fresh()->only(array_keys($credentials)));
    }

    public static function rolesAndEnrollment(): array
    {
        $cases = [];
        foreach (['reader' => '/terms', 'translator' => '/terms', 'validator' => '/validation', 'manager' => '/imports', 'admin' => '/admin'] as $role => $url) {
            $cases[$role.' without enrollment'] = [$role, $url, false];
            $cases[$role.' with confirmed enrollment'] = [$role, $url, true];
        }

        return $cases;
    }

    public function test_disabling_mfa_does_not_bypass_password_or_role_checks(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'Incorrect-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect('/');
        $this->get('/admin')->assertForbidden();
        $this->get('/imports')->assertForbidden();
        $this->get('/validation')->assertForbidden();
    }

    #[DataProvider('unavailableAccounts')]
    public function test_disabling_mfa_does_not_allow_unavailable_accounts_to_sign_in(array $attributes, bool $inactiveOrganization): void
    {
        $user = $this->user('admin', $attributes);
        $this->enroll($user);
        if ($inactiveOrganization) {
            $user->organization->update(['active' => false]);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function unavailableAccounts(): array
    {
        return [
            'inactive' => [['active' => false], false],
            'technical' => [['is_technical' => true], false],
            'unaccepted invitation' => [['invitation_token' => 'synthetic-pending-token', 'invitation_accepted_at' => null], false],
            'inactive organization' => [[], true],
        ];
    }

    #[DataProvider('expiredSessions')]
    public function test_session_expiry_and_revocation_still_apply_when_mfa_is_disabled(int $startedAgo, int $lastSeenAgo, int $version): void
    {
        $user = $this->user('admin');
        $this->enroll($user);
        $this->actingAs($user)->withSession(['security' => [
            'started_at' => now()->timestamp - $startedAgo, 'last_seen_at' => now()->timestamp - $lastSeenAgo,
            'version' => $version, 'mfa_enabled' => false,
        ]])->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public static function expiredSessions(): array
    {
        return [
            'absolute timeout' => [43200, 0, 1],
            'idle timeout' => [3600, 3600, 1],
            'revoked' => [0, 0, 0],
        ];
    }

    #[DataProvider('enrollmentStates')]
    public function test_disabled_profile_hides_mfa_secrets_and_controls_in_every_language(bool $confirmed): void
    {
        $user = $this->user('reader');
        $secret = $this->enroll($user, $confirmed);
        $codes = $user->recoveryCodes();
        $credentials = $user->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        $this->actingAs($user);

        foreach (['fr', 'de', 'it'] as $locale) {
            $user->update(['locale' => $locale]);
            $response = $this->withSession(['two_factor_recovery_codes' => $codes])->get('/profile')->assertOk()
                ->assertSee(__('ui.two_factor_server_disabled', [], $locale))
                ->assertDontSee('ui.two_factor_server_disabled')
                ->assertDontSee($secret)->assertDontSee('otpauth://')
                ->assertDontSee('name="code"', false)
                ->assertDontSee(route('profile.two-factor.enable'), false)
                ->assertDontSee(route('profile.two-factor.confirm'), false)
                ->assertDontSee(route('profile.two-factor.disable'), false)
                ->assertSee(route('profile.password.update'), false);
            foreach ($codes as $code) {
                $response->assertDontSee($code);
            }
        }

        $this->assertEquals($credentials, $user->fresh()->only(array_keys($credentials)));
    }

    public static function enrollmentStates(): array
    {
        return ['pending enrollment' => [false], 'confirmed enrollment' => [true]];
    }

    public function test_disabled_mfa_endpoints_cannot_change_saved_credentials(): void
    {
        $user = $this->user();
        $secret = $this->enroll($user, false);
        $before = $user->getRawOriginal();
        $this->actingAs($user);
        $this->post('/profile/two-factor', ['password' => 'Long-password-123'])->assertForbidden();
        $this->post('/profile/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertForbidden();
        $this->delete('/profile/two-factor', ['password' => 'Long-password-123'])->assertForbidden();

        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[DataProvider('challengeMethods')]
    public function test_disabling_mfa_clears_pending_challenges_without_authenticating(string $method): void
    {
        config(['fortify.mfa_enabled' => true]);
        $user = $this->user('admin');
        $secret = $this->enroll($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        config(['fortify.mfa_enabled' => false]);
        $this->call($method, '/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('login'))
            ->assertSessionMissing('login.id')->assertSessionMissing('login.remember')
            ->assertSessionMissing('login.version')->assertSessionMissing('login.started_at');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public static function challengeMethods(): array
    {
        return ['challenge page' => ['GET'], 'challenge submission' => ['POST']];
    }

    public function test_reenabling_mfa_requires_a_fresh_factor_for_a_session_started_while_disabled(): void
    {
        $user = $this->user('admin');
        $secret = $this->enroll($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect('/');
        $this->get('/admin')->assertOk();

        config(['fortify.mfa_enabled' => true]);
        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk();
    }

    public function test_existing_session_that_visits_the_disabled_server_must_reauthenticate_when_reenabled(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $user = $this->user('admin');
        $secret = $this->enroll($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect(route('two-factor.login'));
        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/');
        $this->get('/admin')->assertOk();
        config(['fortify.mfa_enabled' => false]);
        $this->get('/profile')->assertOk();
        config(['fortify.mfa_enabled' => true]);
        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_reenabling_mfa_requires_enrollment_for_an_unenrolled_privileged_account(): void
    {
        $user = $this->user('admin');
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect('/');
        $this->get('/admin')->assertOk();
        config(['fortify.mfa_enabled' => true]);
        $this->get('/admin')->assertRedirect(route('profile.edit'));
        $this->get('/profile')->assertOk()->assertSee(route('profile.two-factor.enable'), false);
        $this->assertAuthenticatedAs($user);
    }

    public function test_saved_recovery_codes_work_again_after_reenabling_mfa(): void
    {
        $user = $this->user();
        $this->enroll($user);
        $code = $user->recoveryCodes()[0];
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect('/');
        $this->post('/logout');

        config(['fortify.mfa_enabled' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Long-password-123'])->assertRedirect(route('two-factor.login'));
        $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($code, $user->fresh()->recoveryCodes());
    }
}
