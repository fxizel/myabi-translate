<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

class RedirectIfMfaEnabled extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        if (! config('fortify.mfa_enabled', false)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
