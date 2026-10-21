<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')) {
            $host = parse_url(config('app.url'), PHP_URL_HOST);
            abort_unless(is_string($host) && strcasecmp($request->getHost(), $host) === 0, 400);
        }

        return $next($request);
    }
}
