<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

class PasswordResetController extends NewPasswordController
{
    public function store(Request $request): Responsable
    {
        // Validate types before the broker can take different paths for known/unknown accounts.
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string'],
            'password_confirmation' => ['nullable', 'string'],
        ]);

        return parent::store($request);
    }
}
