<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecureSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            // Re-read roles and revocation state on every request, including long-lived workers.
            $user->refresh();
            $session = $request->session();
            $now = now()->timestamp;
            $started = $session->get('security.started_at', $now);
            $lastSeen = $session->get('security.last_seen_at', $now);
            $version = $session->get('security.version', $user->session_version);
            $mfaEnabled = (bool) config('fortify.mfa_enabled', false);
            $requiresMfaLogin = $mfaEnabled && $session->get('security.mfa_enabled') === false
                && $user->hasConfirmedTwoFactor();
            $reason = ! $user->isLoginAllowed() ? 'account_unavailable'
                : ((int) $version !== (int) $user->session_version ? 'revoked'
                : ($requiresMfaLogin ? 'mfa_required'
                : ($now - $started >= 12 * 3600 ? 'absolute_timeout'
                : ($now - $lastSeen >= 60 * 60 ? 'idle_timeout' : null))));

            if ($reason !== null) {
                app(AuditService::class)->record('session.expired', $user, [], ['reason' => $reason]);
                Auth::logout();
                $session->invalidate();
                $session->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => __('Your session has expired. Please sign in again.')], 401)
                    : redirect()->route('login')->withErrors(['email' => __('Your session has expired. Please sign in again.')]);
            }
            $session->put('security.started_at', $started);
            $session->put('security.last_seen_at', $now);
            $session->put('security.version', $version);
            $session->put('security.mfa_enabled', $mfaEnabled);
            app()->setLocale(in_array($user->locale, ['de', 'fr', 'it'], true) ? $user->locale : 'de');
        }

        return $next($request);
    }
}
