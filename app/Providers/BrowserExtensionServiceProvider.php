<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class BrowserExtensionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && request()->hasSession()) {
                // Enrollment alone is not proof of a successful MFA sign-in.
                request()->session()->put([
                    'security.mfa_authenticated' => request()->routeIs('two-factor.login.store'),
                    'security.mfa_fingerprint' => request()->routeIs('two-factor.login.store') ? PersonalAccessToken::mfaFingerprint($event->user) : null,
                    'security.mfa_confirmed_at' => request()->routeIs('two-factor.login.store')
                        ? $event->user->two_factor_confirmed_at?->toIso8601String() : null,
                ]);
            }
        });
    }
}
