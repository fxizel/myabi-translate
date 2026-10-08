<?php

namespace App\Actions\Fortify;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => ['required', 'string', 'confirmed', Password::min(config('auth.password_min_length'))]])->validate();
        $updated = DB::transaction(function () use ($user, $input) {
            $locked = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $broker = PasswordBroker::broker(config('fortify.passwords'));
            // The broker checked this token before entering the callback. An administrator
            // may have changed credentials while this request waited for the account lock.
            if ((int) $locked->session_version !== (int) $user->session_version
                || ! is_string($input['token'] ?? null)
                || ! $broker->tokenExists($locked, $input['token'])) {
                throw ValidationException::withMessages(['email' => __('passwords.token')]);
            }
            abort_unless($locked->active && ! $locked->is_technical && $locked->invitation_token === null, 403);
            $locked->forceFill([
                'password' => $input['password'], 'session_version' => $locked->session_version + 1,
                'failed_login_attempts' => 0, 'locked_until' => null, 'updated_by' => $locked->id,
            ])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $broker->deleteToken($locked);
            app(AuditService::class)->record('security.password_reset', $locked, [], [], $locked);

            return $locked;
        });
        // Fortify continues with this same instance when rotating the remember token.
        $user->setRawAttributes($updated->getAttributes(), true);
        $user->setRelations($updated->getRelations());
    }
}
