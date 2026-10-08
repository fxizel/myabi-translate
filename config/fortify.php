<?php

use Laravel\Fortify\Features;

return [
    'mfa_enabled' => (bool) env('MFA_ENABLED', false),
    'guard' => 'web', 'passwords' => 'users', 'username' => 'email', 'email' => 'email',
    'lowercase_usernames' => true, 'home' => '/', 'prefix' => '', 'domain' => null,
    'middleware' => ['web'], 'auth_middleware' => 'auth', 'views' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'features' => [Features::resetPasswords(), Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])],
];
