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
            $canonicalHost = parse_url(config('app.url'), PHP_URL_HOST);
            $host = $request->getHost();

            abort_unless(is_string($canonicalHost) && (
                strcasecmp($host, $canonicalHost) === 0 || in_array($host, config('app.host_aliases', []), true)
            ), 400);
        }

        return $next($request);
    }
}
