<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->needsTwoFactor() && ! $request->user()->hasConfirmedTwoFactor()) {
            if (! $request->routeIs('profile.*', 'logout', 'two-factor.*', 'password.confirm', 'password.confirmation', 'password.confirm.store')) {
                return $request->expectsJson()
                    ? response()->json(['message' => __('Configure two-factor authentication before continuing.')], 403)
                    : redirect()->route('profile.edit')->with('warning', __('Configure two-factor authentication before continuing.'));
            }
        }

        return $next($request);
    }
}
