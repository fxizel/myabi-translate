<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

/** Extension preflights are handled by the bearer API's restricted transport. */
class BrowserExtensionCors extends HandleCors
{
    public function handle($request, Closure $next)
    {
        if ($request instanceof Request && $request->is('api/browser-extension', 'api/browser-extension/*')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
