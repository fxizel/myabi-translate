<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        // Neither the account lookup nor the broker's per-account throttle is public.
        return $request->wantsJson()
            ? response()->json(['message' => __('passwords.sent')])
            : redirect()->route('password.request')->with('status', __('passwords.sent'));
    }
}
