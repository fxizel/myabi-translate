<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidTwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::find($request->session()->get('login.id'));
        if (! config('fortify.mfa_enabled', false) || ! $user || ! $user->isLoginAllowed() || ! $user->hasConfirmedTwoFactor()
            || $request->session()->get('login.version') !== $user->session_version
            || now()->timestamp - $request->session()->get('login.started_at', 0) >= 600) {
            $request->session()->forget(['login.id', 'login.remember', 'login.version', 'login.started_at']);

            return redirect()->route('login')->withErrors(['email' => __('Please sign in again.')]);
        }

        return $next($request);
    }
}
