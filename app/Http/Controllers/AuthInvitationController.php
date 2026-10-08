<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AuthInvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $user = $this->invitedUser($request, $token);

        return view('auth.invitation', compact('user', 'token'));
    }

    public function accept(Request $request, string $token)
    {
        $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'confirmed', Password::min(config('auth.password_min_length'))]]);
        $user = DB::transaction(function () use ($request, $token) {
            $user = $this->invitedUser($request, $token, true);
            $user->forceFill([
                'password' => $request->string('password')->toString(),
                'email_verified_at' => now(), 'invitation_accepted_at' => now(),
                'invitation_token' => null, 'invitation_expires_at' => null,
                'updated_by' => $user->id,
            ])->save();
            app(AuditService::class)->record('account.activated', $user, [], ['active' => true], $user);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('status', __(config('fortify.mfa_enabled', false)
            ? 'Account activated. Configure your second factor.' : 'ui.account_activated'));
    }

    private function invitedUser(Request $request, string $token, bool $lock = false): User
    {
        $query = User::where('email', strtolower((string) $request->input('email')))
            ->where('invitation_token', hash('sha256', $token))
            ->where('invitation_expires_at', '>', now())->whereNull('invitation_accepted_at')
            ->where('active', true)->where('is_technical', false);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
