<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Str;

class InvitationService
{
    public function send(User $user): void
    {
        $token = Str::random(64);
        $mutation = function () use ($user, $token) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->is_technical || $user->invitation_accepted_at !== null, 422, __('This account cannot be invited.'));
            $user->forceFill([
                'invitation_token' => hash('sha256', $token),
                'invitation_expires_at' => now()->addHours(72),
            ])->save();
            app(AuditService::class)->record('account.invited', $user, [], ['expires_at' => $user->invitation_expires_at->toIso8601String()]);

            return $user;
        };
        // Reinviting an initial administrator can make an otherwise connectable
        // account unavailable. Use the same lock order as role/organization edits.
        $user = app(AdminAvailability::class)->run($mutation, 'user');
        // Issuing/replacing the credential must remain audited even if SMTP fails.
        $user->notify(new AccountInvitation(route('invitation.show', ['token' => $token, 'email' => $user->email])));
    }
}
