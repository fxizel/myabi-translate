<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $guarded = [];

    protected $hidden = ['token', 'mfa_fingerprint'];

    protected function casts(): array
    {
        return [
            'abilities' => 'json', 'last_used_at' => 'datetime', 'expires_at' => 'datetime',
            'revoked_at' => 'datetime', 'mfa_confirmed_at' => 'datetime',
            'mfa_authenticated' => 'boolean', 'session_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalAccessToken $token) {
            $token->session_version ??= $token->tokenable?->session_version ?? 0;
        });
    }

    public static function requiresMfa(User $user): bool
    {
        return (bool) config('fortify.mfa_enabled', false)
            && ($user->needsTwoFactor() || $user->hasConfirmedTwoFactor());
    }

    public static function mfaFingerprint(User $user): ?string
    {
        return $user->hasConfirmedTwoFactor() ? hash('sha256', $user->two_factor_secret."\0".$user->two_factor_confirmed_at->toIso8601String()) : null;
    }

    public function hasCurrentMfaProof(User $user): bool
    {
        return ! self::requiresMfa($user) || ($user->hasConfirmedTwoFactor()
            && $this->mfa_authenticated
            && is_string($this->mfa_fingerprint)
            && hash_equals(self::mfaFingerprint($user), $this->mfa_fingerprint)
            && $this->mfa_confirmed_at?->equalTo($user->two_factor_confirmed_at));
    }
}
