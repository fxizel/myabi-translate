<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAvailability
{
    public function run(callable $mutation, string $errorField = 'roles'): mixed
    {
        return DB::transaction(function () use ($mutation, $errorField) {
            // Account locks precede organizations, like profile/authentication writes
            // whose audit INSERT subsequently checks the organization foreign key.
            // Locking reads see committed changes under MariaDB REPEATABLE READ.
            User::orderBy('id')->lockForUpdate()->get(['id']);
            Organisation::orderBy('id')->lockForUpdate()->get(['id']);
            $hadAdministrator = $this->hasAdministrator();

            $result = $mutation();

            // The initial administrator has no invitation. Temporary login locks
            // and pending MFA enrollment do not remove durable administrative access.
            // A pre-existing incomplete installation can still be repaired.
            if ($hadAdministrator && ! $this->hasAdministrator()) {
                throw ValidationException::withMessages([$errorField => __('At least one active administrator must remain.')]);
            }

            return $result;
        });
    }

    private function hasAdministrator(): bool
    {
        $activeOrganizations = Organisation::where('active', true)->orderBy('id')
            ->lockForUpdate()->pluck('id')->all();

        return User::where('active', true)->where('is_technical', false)
            ->whereIn('organization_id', $activeOrganizations)
            ->where(fn ($query) => $query->whereNull('invitation_token')->orWhereNotNull('invitation_accepted_at'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'roles'])
            ->contains(fn (User $user) => $user->hasRole('admin'));
    }
}
