<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->session()->get('locale', 'de');
        app()->setLocale(in_array($locale, ['de', 'fr', 'it'], true) ? $locale : 'de');

        $response = $next($request);

        // Logout and session expiry regenerate the session; retain only the UI preference.
        $locale = $request->user()?->locale ?? app()->getLocale();
        $request->session()->put('locale', in_array($locale, ['de', 'fr', 'it'], true) ? $locale : 'de');

        return $response;
    }
}
