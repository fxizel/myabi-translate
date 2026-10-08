<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Organisation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\CompletePasswordReset;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminCredentialConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        $organization = Organisation::create(['name' => 'Test organization', 'code' => 'C-'.bin2hex(random_bytes(4)), 'active' => true]);

        return User::factory()->create(array_merge([
            'organization_id' => $organization->id, 'roles' => ['reader' => ['fr']],
            'password' => 'Original-password-123', 'active' => true, 'is_technical' => false,
            'invitation_accepted_at' => now(),
        ], $attributes));
    }

    private function activateAsAdministrator(User $user): void
    {
        $administrator = $this->user(['roles' => ['admin' => ['fr']]]);
        app(EnableTwoFactorAuthentication::class)($administrator);
        $administrator->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->actingAs($administrator)->post(route('admin.users.activate', $user), [
            'password' => 'Administrator-password-456',
            'password_confirmation' => 'Administrator-password-456',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function resetInput(User $user, string $token): array
    {
        return [
            'email' => $user->email, 'token' => $token,
            'password' => 'Recovery-password-789',
            'password_confirmation' => 'Recovery-password-789',
        ];
    }

    public function test_reset_callback_loaded_before_administrative_activation_cannot_replace_its_password(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $snapshot = $user->fresh();
        $this->assertTrue(Password::broker()->tokenExists($snapshot, $token));

        $this->activateAsAdministrator($user);
        $version = $user->fresh()->session_version;

        try {
            app(ResetUserPassword::class)->reset($snapshot, $this->resetInput($snapshot, $token));
            $this->fail('A reset callback validated before activation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertTrue(Hash::check('Administrator-password-456', $user->fresh()->password));
        $this->assertSame($version, $user->fresh()->session_version);
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_reset']);
    }

    public function test_reset_callback_rechecks_token_after_acquiring_account_lock(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $snapshot = $user->fresh();
        $this->assertTrue(Password::broker()->tokenExists($snapshot, $token));
        Password::broker()->deleteToken($user);

        try {
            app(ResetUserPassword::class)->reset($snapshot, $this->resetInput($snapshot, $token));
            $this->fail('A token revoked after broker validation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertTrue(Hash::check('Original-password-123', $user->fresh()->password));
        $this->assertSame($snapshot->session_version, $user->fresh()->session_version);
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_reset']);
    }

    public function test_recovery_after_activation_consumes_token_and_updates_the_fortify_instance(): void
    {
        $user = $this->user();
        $this->activateAsAdministrator($user);
        $snapshot = $user->fresh();
        $version = $snapshot->session_version;
        $token = Password::broker()->createToken($snapshot);

        app(ResetUserPassword::class)->reset($snapshot, $this->resetInput($snapshot, $token));

        $this->assertSame($version + 1, $snapshot->session_version);
        $this->assertTrue(Hash::check('Recovery-password-789', $snapshot->password));
        $this->assertFalse(Password::broker()->tokenExists($snapshot, $token));
        app(CompletePasswordReset::class)(auth()->guard(), $snapshot);
        $this->assertSame($snapshot->password, $snapshot->fresh()->password);
        $this->assertSame($version + 1, $snapshot->fresh()->session_version);
        $this->assertDatabaseHas('audit_events', ['entity_id' => $user->id, 'action' => 'security.password_reset']);
    }

    public function test_invitation_loaded_before_administrative_activation_cannot_restore_a_token(): void
    {
        Notification::fake();
        $user = $this->user(['invitation_accepted_at' => null, 'invitation_token' => hash('sha256', 'pending-test-token')]);
        $snapshot = $user->fresh();
        $this->activateAsAdministrator($user);

        try {
            app(InvitationService::class)->send($snapshot);
            $this->fail('An invitation request loaded before activation must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertNull($user->fresh()->invitation_token);
        $this->assertNotNull($user->fresh()->invitation_accepted_at);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.invited']);
        Notification::assertNothingSent();
    }
}
