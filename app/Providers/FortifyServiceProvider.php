<?php

namespace App\Providers;

use App\Actions\Fortify\RedirectIfMfaEnabled;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        $this->app->scoped(RedirectsIfTwoFactorAuthenticatable::class, RedirectIfMfaEnabled::class);
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', compact('request')));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(function (Request $request) {
            // Persistent sign-in would bypass the mandatory twelve-hour session limit.
            $request->merge(['remember' => false]);
            $user = User::where('email', Str::lower(trim($request->string('email')->toString())))->first();
            if (! $user || ! $user->isLoginAllowed()) {
                return null;
            }
            if (! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }
            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => $request->string('password')->toString()])->save();
            }

            return $user;
        });

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower($request->string('email')->toString()).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by('2fa|'.$request->session()->get('login.id').'|'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        Event::listen(Login::class, function (Login $event) {
            if (! $event->user instanceof User) {
                return;
            }
            try {
                $user = DB::transaction(function () use ($event) {
                    $user = User::whereKey($event->user->id)->lockForUpdate()->firstOrFail();
                    abort_unless($user->isLoginAllowed()
                        && (int) $user->session_version === (int) $event->user->session_version, 403);
                    $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
                    app(AuditService::class)->record('auth.login_succeeded', $user, [], [], $user);

                    return $user;
                });
            } catch (\Throwable $error) {
                // SessionGuard stores the identity before dispatching Login. A
                // rejected or unaudited login must not leave that identity usable.
                $guard = auth()->guard($event->guard);
                $recaller = $guard->getRecallerName();
                request()->cookies->remove($recaller);
                $guard->getCookieJar()->unqueue($recaller);
                $guard->getCookieJar()->queue($guard->getCookieJar()->forget($recaller));
                $guard->forgetUser();
                if (request()->hasSession()) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                }
                throw $error;
            }
            if (request()->hasSession()) {
                request()->session()->put([
                    'security.started_at' => now()->timestamp, 'security.last_seen_at' => now()->timestamp,
                    'security.version' => $user->session_version,
                    'security.mfa_enabled' => (bool) config('fortify.mfa_enabled', false),
                ]);
                request()->session()->forget(['login.id', 'login.remember', 'login.version', 'login.started_at']);
            }
        });
        Event::listen(Failed::class, function (Failed $event) {
            $email = Str::lower(trim($event->credentials['email'] ?? ''));
            $user = $event->user instanceof User ? $event->user : User::where('email', $email)->first();
            $this->failedAttempt($user, 'auth.login_failed', ['email' => $email]);
        });
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof User) {
                app(AuditService::class)->record('auth.logout', $event->user, [], [], $event->user);
            }
        });
        Event::listen(TwoFactorAuthenticationChallenged::class, function ($event) {
            request()->session()->put(['login.version' => $event->user->session_version, 'login.started_at' => now()->timestamp]);
        });
        Event::listen(TwoFactorAuthenticationFailed::class, function ($event) {
            $this->failedAttempt($event->user, 'auth.two_factor_failed');
        });
    }

    private function failedAttempt(?User $user, string $action, array $details = []): void
    {
        if (! $user || $user->is_technical) {
            app(AuditService::class)->record($action, $user ?? 'user', [], $details, $user);

            return;
        }
        DB::transaction(function () use ($user, $action, $details) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $user->locked_until?->isFuture()) {
                $attempts = $user->locked_until ? 1 : $user->failed_login_attempts + 1;
                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null,
                ])->save();
            }
            app(AuditService::class)->record($action, $user, [], $details, $user);
        });
    }
}
