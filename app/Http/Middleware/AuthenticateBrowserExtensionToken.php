<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\BrowserExtension\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrowserExtensionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        // Never accept a cookie, a session, or Sanctum's transient SPA token.
        if (! $bearer || strlen($bearer) > 1024) {
            throw new ApiProblem(401, 'unauthenticated');
        }
        $token = PersonalAccessToken::findToken($bearer);
        $user = $token?->tokenable;
        if (! $token || ! $user instanceof User || $token->revoked_at
            || ! $token->expires_at || $token->expires_at->lessThanOrEqualTo(now())
            || $token->expires_at->greaterThan($token->created_at->copy()->addDays(config('browser-extension.token_max_days')))) {
            throw new ApiProblem(401, 'unauthenticated');
        }
        $user->refresh();
        if ($token->session_version !== (int) $user->session_version || ! $user->isLoginAllowed() || ! $user->canSeeCatalogue()) {
            throw new ApiProblem(401, 'unauthenticated');
        }
        if (! $token->hasCurrentMfaProof($user)) {
            throw new ApiProblem(403, 'mfa_required');
        }
        $feedback = $request->is('api/browser-extension/feedback');
        $permission = $feedback ? 'extension:feedback' : 'extension:search';
        if (! in_array($permission, $token->abilities ?? [], true) || in_array('*', $token->abilities ?? [], true)) {
            throw new ApiProblem(403, 'permission_denied');
        }
        if ($feedback && ! config('browser-extension.feedback_enabled')) {
            throw new ApiProblem(403, 'feedback_not_allowed');
        }
        $user->withAccessToken($token);
        $previousGuard = Auth::getDefaultDriver();
        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('sanctum');
        $request->setUserResolver(fn () => $user);
        // Only persist technical last-use metadata, never the request body.
        try {
            $token->forceFill(['last_used_at' => now()])->save();

            return $next($request);
        } finally {
            Auth::shouldUse($previousGuard);
            Auth::guard('sanctum')->forgetUser();
        }
    }
}
