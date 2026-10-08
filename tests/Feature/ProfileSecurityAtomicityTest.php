<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProfileSecurityAtomicityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('mutations')]
    public function test_profile_and_authentication_changes_roll_back_when_audit_fails(string $operation, string $event): void
    {
        config(['fortify.mfa_enabled' => str_starts_with($operation, 'mfa-')]);
        $user = User::factory()->create([
            'password' => 'Original-password-123', 'roles' => ['reader' => ['fr']], 'locale' => 'fr',
            'failed_login_attempts' => 3,
        ]);
        $method = 'POST';
        $payload = ['password' => 'Original-password-123'];
        $url = '/profile/two-factor';
        if (in_array($operation, ['mfa-confirm', 'mfa-disable'])) {
            app(EnableTwoFactorAuthentication::class)($user);
            if ($operation === 'mfa-confirm') {
                $url .= '/confirm';
                $payload = ['code' => app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret))];
            } else {
                $user->forceFill(['two_factor_confirmed_at' => now()])->save();
                $method = 'DELETE';
            }
        } elseif ($operation === 'profile') {
            $method = 'PATCH';
            $url = '/profile';
            $payload = ['locale' => 'it', 'notifications_enabled' => false];
        } elseif ($operation === 'locale') {
            $url = '/locale';
            $payload = ['locale' => 'it'];
        } elseif ($operation === 'password') {
            $method = 'PATCH';
            $url = '/profile/password';
            $payload = ['current_password' => 'Original-password-123', 'password' => 'Changed-password-456', 'password_confirmation' => 'Changed-password-456'];
        } elseif (str_starts_with($operation, 'login-')) {
            $url = '/login';
            $payload = ['email' => $user->email, 'password' => $operation === 'login-success' ? 'Original-password-123' : 'Wrong-password-456'];
        }
        $before = $user->fresh()->getRawOriginal();
        $resetToken = Password::broker()->createToken($user);
        DB::table('sessions')->insert(['id' => 'synthetic-existing-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->mock(AuditService::class)->shouldReceive('record')->once()
            ->withArgs(fn ($action) => $action === $event)->andThrow(new \RuntimeException('Synthetic audit failure'));
        if (! str_starts_with($operation, 'login-')) {
            $this->actingAs($user);
        }
        $this->withoutExceptionHandling();
        try {
            $this->call($method, $url, $payload);
            $this->fail('The audit failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertTrue(Password::broker()->tokenExists($user->fresh(), $resetToken));
        $this->assertDatabaseHas('sessions', ['id' => 'synthetic-existing-session']);
        $this->assertDatabaseMissing('audit_events', ['action' => $event]);
        $this->assertNull(session('two_factor_recovery_codes'));
        if ($operation === 'login-success') {
            auth()->forgetGuards();
            $this->assertGuest();
            $this->withExceptionHandling()->get('/profile')->assertRedirect(route('login'));
        }
    }

    public static function mutations(): array
    {
        return [
            ['profile', 'profile.updated'], ['locale', 'profile.locale_changed'],
            ['password', 'security.password_changed'], ['mfa-enable', 'security.two_factor_enrollment_started'],
            ['mfa-confirm', 'security.two_factor_confirmed'], ['mfa-disable', 'security.two_factor_disabled'],
            ['login-success', 'auth.login_succeeded'], ['login-failure', 'auth.login_failed'],
        ];
    }

    public function test_login_cannot_revalidate_credentials_revoked_after_password_verification(): void
    {
        $user = User::factory()->create(['roles' => ['reader' => ['fr']]]);
        $this->get('/login');
        $snapshot = $user->fresh();
        $user->increment('session_version');
        try {
            auth()->login($snapshot);
            $this->fail('A login based on obsolete credentials must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        auth()->forgetGuards();
        $this->assertGuest();
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.login_succeeded']);
    }

    public function test_failed_login_audit_clears_an_incoming_and_queued_remember_cookie(): void
    {
        $user = User::factory()->create(['roles' => ['reader' => ['fr']]]);
        $this->get('/login');
        $guard = auth()->guard();
        $recaller = $guard->getRecallerName();
        request()->cookies->set($recaller, $user->id.'|'.$user->remember_token.'|'.$user->password);
        $this->mock(AuditService::class)->shouldReceive('record')->once()
            ->withArgs(fn ($action) => $action === 'auth.login_succeeded')
            ->andThrow(new \RuntimeException('Synthetic audit failure'));
        try {
            $guard->login($user, true);
            $this->fail('An unaudited login must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertFalse(request()->cookies->has($recaller));
        $cookie = Cookie::queued($recaller);
        $this->assertNotNull($cookie);
        $this->assertLessThan(time(), $cookie->getExpiresTime());
        $this->assertEmpty($cookie->getValue());
        auth()->forgetGuards();
        $this->assertGuest();
        $this->get('/profile')->assertRedirect(route('login'));
    }
}
