<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => 'Original-password-123', 'roles' => ['reader' => ['fr']],
            'locale' => 'fr', 'active' => true, 'is_technical' => false,
        ], $attributes))->refresh();
    }

    private function passwordInput(array $overrides = []): array
    {
        return array_replace([
            'current_password' => 'Original-password-123',
            'password' => 'Changed-password-456',
            'password_confirmation' => 'Changed-password-456',
        ], $overrides);
    }

    public function test_password_change_preserves_current_session_and_revokes_other_access(): void
    {
        $user = $this->user();
        $other = $this->user();
        $otherBefore = $other->getRawOriginal();
        $rememberToken = $user->remember_token;
        $resetToken = Password::broker()->createToken($user);
        foreach (['own-other-session' => $user->id, 'unrelated-session' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp,
            ]);
        }
        $started = now()->subHours(2)->timestamp;
        $this->actingAs($user)->withSession(['security' => [
            'started_at' => $started, 'last_seen_at' => now()->timestamp, 'version' => 1,
        ]]);
        $previousSessionId = session()->getId();

        $this->patch(route('profile.password.update'), $this->passwordInput([
            'user_id' => $other->id, 'email' => $other->email,
        ]))->assertRedirect(route('profile.edit'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('ui.password_changed'))
            ->assertSessionHas('security.started_at', $started)
            ->assertSessionHas('security.version', 2);

        $updated = $user->fresh();
        $this->assertTrue(Hash::check('Changed-password-456', $updated->password));
        $this->assertFalse(Hash::check('Original-password-123', $updated->password));
        $this->assertSame(2, $updated->session_version);
        $this->assertSame($user->id, $updated->updated_by);
        $this->assertNotSame($rememberToken, $updated->remember_token);
        $this->assertNotEmpty($updated->remember_token);
        $this->assertFalse(Password::broker()->tokenExists($updated, $resetToken));
        $this->assertNotSame($previousSessionId, session()->getId());
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('sessions', ['id' => 'own-other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session', 'user_id' => $other->id]);
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $event = AuditEvent::where('action', 'security.password_changed')->sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame((string) $user->id, $event->entity_id);
        $this->assertSame([], $event->before_values);
        $this->assertSame([], $event->after_values);
        $this->get('/profile')->assertOk();

        $this->withSession(['security.version' => 1])->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Original-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Changed-password-456'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_change_keeps_credentials_and_never_flashes_secrets(array $overrides, string $error): void
    {
        $user = $this->user();
        $before = $user->getRawOriginal();
        $resetToken = Password::broker()->createToken($user);
        $this->actingAs($user)->from('/profile')->patch('/profile/password', $this->passwordInput($overrides))
            ->assertRedirect('/profile')->assertSessionHasErrors($error)
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertTrue(Password::broker()->tokenExists($user, $resetToken));
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_changed']);
        $this->assertAuthenticatedAs($user);
    }

    public static function invalidPasswords(): array
    {
        return [
            'missing current password' => [['current_password' => null], 'current_password'],
            'incorrect current password' => [['current_password' => 'Incorrect-password-123'], 'current_password'],
            'current password must be a string' => [['current_password' => ['Original-password-123']], 'current_password'],
            'missing new password' => [['password' => null], 'password'],
            'new password too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'new password must be a string' => [['password' => ['Changed-password-456']], 'password'],
            'missing confirmation' => [['password_confirmation' => null], 'password'],
            'incorrect confirmation' => [['password_confirmation' => 'Another-password-789'], 'password'],
        ];
    }

    public function test_guest_cannot_change_a_password(): void
    {
        $user = $this->user();
        $hash = $user->password;
        $this->patch('/profile/password', $this->passwordInput(['user_id' => $user->id]))->assertRedirect(route('login'));
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_changed']);
    }

    #[DataProvider('unavailableSessions')]
    public function test_invalid_session_cannot_change_a_password(array $attributes, int $version): void
    {
        $user = $this->user($attributes);
        $before = $user->getRawOriginal();
        $this->actingAs($user)->withSession(['security' => [
            'started_at' => now()->timestamp, 'last_seen_at' => now()->timestamp, 'version' => $version,
        ]])->patch('/profile/password', $this->passwordInput())->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($before['password'], $user->fresh()->password);
        $this->assertSame($before['session_version'], $user->fresh()->session_version);
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_changed']);
    }

    public static function unavailableSessions(): array
    {
        return [
            'revoked' => [[], 0],
            'inactive account' => [['active' => false], 1],
            'technical account' => [['is_technical' => true], 1],
        ];
    }

    public function test_password_change_attempts_are_rate_limited(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->patch('/profile/password', $this->passwordInput(['current_password' => 'Wrong-password-123']))
                ->assertSessionHasErrors('current_password');
        }
        $this->patch('/profile/password', $this->passwordInput())->assertTooManyRequests();
        $this->assertTrue(Hash::check('Original-password-123', $user->fresh()->password));
        $this->assertDatabaseMissing('audit_events', ['action' => 'security.password_changed']);
    }

    public function test_password_change_preserves_enrolled_two_factor_credentials(): void
    {
        $user = $this->user([
            'roles' => ['admin' => ['fr']],
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['synthetic-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $before = $user->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        $this->actingAs($user)->patch('/profile/password', $this->passwordInput())->assertRedirect(route('profile.edit'));
        $this->assertEquals($before, $user->fresh()->only(array_keys($before)));
    }

    public function test_profile_password_form_is_localized_and_available_before_required_two_factor_enrollment(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $user = $this->user(['roles' => ['admin' => ['de', 'fr', 'it']]]);
        $this->actingAs($user);
        foreach (['fr', 'de', 'it'] as $locale) {
            $user->update(['locale' => $locale]);
            $response = $this->get('/profile')->assertOk()
                ->assertSee(route('profile.password.update'), false)
                ->assertSee('name="current_password"', false)
                ->assertSee('name="password_confirmation"', false)
                ->assertSee('autocomplete="new-password"', false);
            $response->assertDontSee('ui.change_password')->assertDontSee('ui.current_password');
        }
        $this->patch('/profile/password', $this->passwordInput())->assertRedirect(route('profile.edit'));
        $this->assertTrue(Hash::check('Changed-password-456', $user->fresh()->password));
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }
}
