<?php

namespace App\Models;

use App\Services\CommonScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ROLES = ['reader', 'translator', 'validator', 'manager', 'admin'];

    public const LANGUAGES = ['de', 'fr', 'it', 'en'];

    protected $attributes = [
        'active' => true, 'is_technical' => false, 'session_version' => 1,
        'locale' => 'de', 'notifications_enabled' => true,
    ];

    protected $fillable = [
        'name', 'first_name', 'last_name', 'email', 'password', 'organization_id',
        'roles', 'languages', 'active', 'is_technical', 'locale', 'notifications_enabled',
        'created_by', 'updated_by', 'invitation_token', 'invitation_expires_at', 'invitation_accepted_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'invitation_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime', 'password' => 'hashed', 'roles' => 'array', 'languages' => 'array',
            'active' => 'boolean', 'is_technical' => 'boolean', 'notifications_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime', 'locked_until' => 'datetime',
            'invitation_expires_at' => 'datetime', 'invitation_accepted_at' => 'datetime',
            'last_digest_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organization_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->organization();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    public function hasRole(string $role): bool
    {
        $roles = $this->roles ?? [];

        return array_key_exists($role, $roles) || in_array($role, $roles, true);
    }

    public function roleLanguages(string $role): array
    {
        $roles = $this->roles ?? [];
        $languages = $roles[$role] ?? $this->languages ?? [];

        return array_map(fn ($language) => strtolower(substr($language, 0, 2)), is_array($languages) ? $languages : []);
    }

    public function canAdmin(): bool
    {
        return $this->active && ! $this->is_technical && $this->hasRole('admin');
    }

    public function canManage(): bool
    {
        return $this->active && ! $this->is_technical && $this->hasRole('manager');
    }

    public function canValidate(?string $language = null): bool
    {
        if (! $this->active || $this->is_technical) {
            return false;
        }

        return $this->canManage() || ($this->hasRole('validator') && ($language === null || in_array(strtolower(substr($language, 0, 2)), $this->roleLanguages('validator'), true)));
    }

    public function canTranslate(?string $language = null, ?int $ownerId = null): bool
    {
        if (! $this->active || $this->is_technical) {
            return false;
        }
        if ($this->canValidate($language)) {
            return true;
        }

        return $this->hasRole('translator')
            && ($language === null || in_array(strtolower(substr($language, 0, 2)), $this->roleLanguages('translator'), true))
            && ($ownerId === (int) $this->organization_id || CommonScope::contains($ownerId));
    }

    public function canValidateAny(): bool
    {
        return $this->canValidate();
    }

    public function canSeeOrganisation(?int $ownerId): bool
    {
        if (! $this->active || $this->is_technical) {
            return false;
        }
        if ($ownerId === (int) $this->organization_id || $this->canManage() || $this->canValidate()) {
            return true;
        }

        return CommonScope::contains($ownerId);
    }

    public function needsTwoFactor(): bool
    {
        return config('fortify.mfa_enabled', false)
            && ($this->hasRole('validator') || $this->hasRole('manager') || $this->hasRole('admin'));
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isLoginAllowed(): bool
    {
        return $this->active && ! $this->is_technical
            && ($this->organization_id === null || ($this->organization?->active ?? false))
            && ($this->locked_until === null || $this->locked_until->isPast())
            && ($this->invitation_token === null || $this->invitation_accepted_at !== null);
    }
}
