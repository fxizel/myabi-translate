<?php

namespace App\Http\Controllers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BrowserExtensionTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.config('browser-extension.token_max_days')],
            'feedback' => ['sometimes', 'boolean'],
        ]);
        $issued = DB::transaction(function () use ($request, $data) {
            $user = $this->lockedUser($request);
            $active = $user->tokens()->whereNull('revoked_at')->where('expires_at', '>', now())
                ->where('session_version', $user->session_version)->get()
                ->filter(fn ($token) => $token->hasCurrentMfaProof($user))->count();
            if ($active >= config('browser-extension.token_max_count')) {
                throw ValidationException::withMessages(['name' => __('extension.limit_reached')]);
            }
            $abilities = ['extension:search'];
            if ($request->boolean('feedback') && config('browser-extension.feedback_enabled')) {
                $abilities[] = 'extension:feedback';
            }
            $issued = $user->createToken($data['name'], $abilities, now()->addDays((int) $data['expires_in_days']));
            $issued->accessToken->forceFill([
                'mfa_authenticated' => $request->session()->get('security.mfa_authenticated') === true,
                'mfa_fingerprint' => $request->session()->get('security.mfa_authenticated') === true ? PersonalAccessToken::mfaFingerprint($user) : null,
                'mfa_confirmed_at' => $request->session()->get('security.mfa_authenticated') === true ? $user->two_factor_confirmed_at : null,
            ])->save();
            app(AuditService::class)->record('extension.token_created', $issued->accessToken, [], [
                'expires_at' => $issued->accessToken->expires_at->toIso8601String(), 'abilities' => $abilities,
            ], $user);

            return $issued;
        });

        // The secret is rendered only in this response, never in session flash data.
        return response()->view('extension-token-created', ['plainTextToken' => $issued->plainTextToken])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function destroy(Request $request, PersonalAccessToken $token)
    {
        DB::transaction(function () use ($request, $token) {
            $user = $this->lockedUser($request);
            $token = $user->tokens()->whereKey($token->id)->lockForUpdate()->firstOrFail();
            if ($token->revoked_at === null) {
                $token->forceFill(['revoked_at' => now()])->save();
                app(AuditService::class)->record('extension.token_revoked', $token, [], [], $user);
            }
        });

        return redirect()->route('profile.edit')->with('status', __('extension.revoked'));
    }

    private function lockedUser(Request $request): User
    {
        $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->isLoginAllowed() && $user->canSeeCatalogue()
            && (int) $request->session()->get('security.version') === (int) $user->session_version, 403);
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);
        abort_unless($confirmed <= now()->timestamp
            && now()->timestamp - $confirmed < config('browser-extension.password_confirmation_seconds'), 423);

        if (PersonalAccessToken::requiresMfa($user)) {
            abort_unless($user->hasConfirmedTwoFactor()
                && $request->session()->get('security.mfa_authenticated') === true
                && $request->session()->get('security.mfa_fingerprint') === PersonalAccessToken::mfaFingerprint($user)
                && $request->session()->get('security.mfa_confirmed_at') === $user->two_factor_confirmed_at?->toIso8601String(), 403);
        }

        return $user;
    }
}
