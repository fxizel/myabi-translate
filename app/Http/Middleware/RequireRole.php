<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $allowed = $user && match ($role) {
            'admin' => $user->canAdmin(),
            'manager' => $user->canManage(),
            'validator' => $user->canValidate(),
            'translator' => $user->canTranslate(),
            'audit' => $user->canManage() || $user->canAdmin(),
            default => false,
        };
        abort_unless($allowed, 403);

        return $next($request);
    }
}
