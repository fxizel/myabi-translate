<?php

namespace App\Http\Middleware;

use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\RateLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleBrowserExtension
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $limits = $request->is('api/browser-extension/feedback')
            ? ['extension:feedback-user:'.$user->id => config('browser-extension.limits.feedback_user')]
            : [
                'extension:search-user:'.$user->id => config('browser-extension.limits.search_user'),
                'extension:search-token:'.$token->id => config('browser-extension.limits.search_token'),
            ];
        $retry = app(RateLimits::class)->consume($limits, 'user:'.$user->id);
        if ($retry) {
            throw new ApiProblem(429, 'rate_limited', [], ['Retry-After' => (string) $retry]);
        }

        return $next($request);
    }
}
