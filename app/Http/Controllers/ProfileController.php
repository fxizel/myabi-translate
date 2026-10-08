<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        $actions = AuditEvent::where('user_id', $user->id)->latest('id')->paginate(20)->withQueryString();
        // Secrets are only presented during enrollment, and never in audit data.
        $qrCode = config('fortify.mfa_enabled', false) && $user->two_factor_secret && ! $user->two_factor_confirmed_at ? $user->twoFactorQrCodeSvg() : null;
        $recoveryCodes = $request->session()->pull('two_factor_recovery_codes', []);
        if (! config('fortify.mfa_enabled', false)) {
            $recoveryCodes = [];
        }

        return view('profile', compact('user', 'actions', 'qrCode', 'recoveryCodes'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(['de', 'fr', 'it'])],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ]);
        $data['notifications_enabled'] = $request->boolean('notifications_enabled');
        $user = DB::transaction(function () use ($request, $data) {
            $user = $this->lockedUser($request);
            $before = $user->only(['locale', 'notifications_enabled']);
            $user->fill($data)->forceFill(['updated_by' => $user->id])->save();
            app(AuditService::class)->record('profile.updated', $user, $before, $data);

            return $user;
        });
        $request->user()->setRawAttributes($user->getAttributes(), true);
        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);

        return back()->with('status', __('Profile saved.'));
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(config('auth.password_min_length'))],
        ]);

        $user = DB::transaction(function () use ($request, $data) {
            $user = $this->lockedUser($request);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
            }

            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
                'session_version' => $user->session_version + 1,
                'updated_by' => $user->id,
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            PasswordBroker::broker()->deleteToken($user);
            app(AuditService::class)->record('security.password_changed', $user, [], [], $user);

            return $user;
        });

        // Keep this session valid without extending its absolute lifetime.
        $request->session()->regenerate(true);
        $request->session()->put('security.version', $user->session_version);

        return redirect()->route('profile.edit')->with('status', __('ui.password_changed'));
    }

    public function locale(Request $request)
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['de', 'fr', 'it'])]]);
        $user = $request->user();
        if ($user) {
            $updated = DB::transaction(function () use ($request, $data) {
                $user = $this->lockedUser($request);
                $before = ['locale' => $user->locale];
                $user->fill($data)->forceFill(['updated_by' => $user->id])->save();
                app(AuditService::class)->record('profile.locale_changed', $user, $before, $data);

                return $user;
            });
            $user->setRawAttributes($updated->getAttributes(), true);
        }
        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);

        return back();
    }

    public function enableTwoFactor(Request $request, EnableTwoFactorAuthentication $enable)
    {
        abort_unless(config('fortify.mfa_enabled', false), 403, __('ui.two_factor_server_disabled'));
        $request->validate(['password' => ['required', 'string']]);
        DB::transaction(function () use ($request, $enable) {
            $user = $this->lockedUser($request, true);
            $enable($user);
            app(AuditService::class)->record('security.two_factor_enrollment_started', $user);
        });

        return redirect()->route('profile.edit');
    }

    public function confirmTwoFactor(Request $request, ConfirmTwoFactorAuthentication $confirm)
    {
        abort_unless(config('fortify.mfa_enabled', false), 403, __('ui.two_factor_server_disabled'));
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = DB::transaction(function () use ($request, $confirm) {
            $user = $this->lockedUser($request);
            $confirm($user, $request->string('code')->toString());
            app(AuditService::class)->record('security.two_factor_confirmed', $user);

            return $user;
        });
        $request->session()->put('two_factor_recovery_codes', $user->recoveryCodes());

        return redirect()->route('profile.edit')->with('status', __('Two-factor authentication enabled. Store your recovery codes securely.'));
    }

    public function disableTwoFactor(Request $request, DisableTwoFactorAuthentication $disable)
    {
        abort_unless(config('fortify.mfa_enabled', false), 403, __('ui.two_factor_server_disabled'));
        $request->validate(['password' => ['required', 'string']]);
        DB::transaction(function () use ($request, $disable) {
            $user = $this->lockedUser($request, true);
            abort_if($user->needsTwoFactor(), 403, __('Two-factor authentication is required for your roles.'));
            $disable($user);
            app(AuditService::class)->record('security.two_factor_disabled', $user);
        });

        return redirect()->route('profile.edit')->with('status', __('Two-factor authentication disabled.'));
    }

    private function lockedUser(Request $request, bool $checkPassword = false): User
    {
        $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->isLoginAllowed()
            && (int) $request->session()->get('security.version') === (int) $user->session_version, 403);
        if ($checkPassword && ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['password' => __('validation.current_password')]);
        }

        return $user;
    }
}
