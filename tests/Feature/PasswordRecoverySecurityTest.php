<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://translations.example.test']);
        Notification::fake();
    }

    public function test_reset_link_uses_the_configured_origin_even_when_the_request_host_is_untrusted(): void
    {
        $user = User::factory()->create([
            'email' => 'recovery@example.test',
            'two_factor_secret' => encrypt('SYNTHETIC-TOTP-SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        $secret = $user->two_factor_secret;
        $token = null;
        $this->postJson('https://untrusted.example.test/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, &$token) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://translations.example.test/reset-password/', $url);
            $this->assertStringNotContainsString('untrusted.example.test', $url);
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);
        $this->postJson('https://translations.example.test/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Synthetic-new-password-2026', 'password_confirmation' => 'Synthetic-new-password-2026',
        ])->assertOk();
        $this->assertTrue(Hash::check('Synthetic-new-password-2026', $user->fresh()->password));
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_production_rejects_unknown_web_hosts_without_breaking_the_internal_health_probe(): void
    {
        $this->app->instance('env', 'production');
        $user = User::factory()->create(['email' => 'recovery@example.test']);
        $this->postJson('https://untrusted.example.test/forgot-password', ['email' => $user->email])->assertBadRequest();
        $this->get('https://child.translations.example.test/login')->assertBadRequest();
        Notification::assertNothingSent();
        $this->get('https://translations.example.test/login')->assertOk();
        $this->get('http://127.0.0.1/up')->assertOk();
        // Prove the admitted route still enforces CSRF outside the testing environment.
        $this->postJson('https://translations.example.test/forgot-password', ['email' => $user->email])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-csrf'])->postJson('https://translations.example.test/forgot-password', [
            '_token' => 'synthetic-csrf', 'email' => $user->email,
        ])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_json_recovery_responses_hide_account_existence_and_broker_throttling(): void
    {
        $user = User::factory()->create(['email' => 'recovery@example.test']);
        $inactive = User::factory()->create(['email' => 'inactive@example.test', 'active' => false]);
        $expected = null;
        foreach ([$user->email, 'absent@example.test', $user->email, $inactive->email] as $email) {
            $response = $this->postJson('/forgot-password', compact('email'))->assertOk();
            $expected ??= $response->json();
            $this->assertSame($expected, $response->json());
            $response->assertExactJson(['message' => __('passwords.sent')]);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'absent@example.test']);
    }

    public function test_html_recovery_uses_the_same_redirect_and_flash_without_an_error_bag(): void
    {
        $user = User::factory()->create(['email' => 'recovery@example.test']);
        foreach ([$user->email, 'absent@example.test', $user->email] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', compact('email'))
                ->assertRedirect(route('password.request'))->assertSessionHasNoErrors()
                ->assertSessionHas('status', __('passwords.sent'));
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_reset_with_an_invalid_token_does_not_disclose_whether_the_account_exists(): void
    {
        $user = User::factory()->create(['email' => 'recovery@example.test']);
        $input = ['token' => 'synthetic-invalid-token', 'password' => 'Synthetic-new-password-2026', 'password_confirmation' => 'Synthetic-new-password-2026'];
        $known = $this->postJson('/reset-password', $input + ['email' => $user->email])->assertUnprocessable();
        $unknown = $this->postJson('/reset-password', $input + ['email' => 'absent@example.test'])->assertUnprocessable();
        $this->assertSame($known->json(), $unknown->json());
        foreach ([$user->email, 'absent@example.test'] as $email) {
            $this->from('/reset-password/synthetic-invalid-token')->post('/reset-password', $input + compact('email'))
                ->assertSessionHasErrors(['email' => __('passwords.token')]);
        }
    }

    public function test_malformed_reset_credentials_are_rejected_before_account_lookup(): void
    {
        $user = User::factory()->create(['email' => 'recovery@example.test']);
        $input = ['token' => ['synthetic-invalid-token'], 'password' => 'Synthetic-new-password-2026'];
        $known = $this->postJson('/reset-password', $input + ['email' => $user->email])->assertUnprocessable();
        $unknown = $this->postJson('/reset-password', $input + ['email' => 'absent@example.test'])->assertUnprocessable();
        $this->assertSame($known->json(), $unknown->json());
        $known->assertJsonValidationErrors('token');
    }

    public function test_invalid_email_and_ip_rate_limits_remain_enforced(): void
    {
        $this->postJson('/forgot-password', ['email' => 'invalid'])->assertUnprocessable();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/forgot-password', ['email' => 'absent@example.test'])->assertOk();
        }
        $this->postJson('/forgot-password', ['email' => 'absent@example.test'])->assertTooManyRequests();
        Notification::assertNothingSent();
    }
}
