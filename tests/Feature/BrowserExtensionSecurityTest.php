<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBrowserExtensionToken;
use App\Http\Middleware\ThrottleBrowserExtension;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class BrowserExtensionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fortify.mfa_enabled' => false, 'browser-extension.rate_limit_store' => 'database', 'browser-extension.force_https' => false]);
        Route::post('/api/browser-extension/security-probe', fn () => response()->json(['userId' => request()->user()->id]))
            ->middleware([AuthenticateBrowserExtensionToken::class, ThrottleBrowserExtension::class]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'roles' => ['reader' => ['fr']], 'locale' => 'fr', 'password' => 'Synthetic-password-123',
        ], $attributes))->refresh();
    }

    private function token(User $user, array $abilities = ['extension:search']): string
    {
        return $user->createToken('Synthetic extension', $abilities, now()->addDays(30))->plainTextToken;
    }

    private function probe(?string $token = null, array $headers = [])
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson('/api/browser-extension/security-probe', ['contractVersion' => 2], $headers);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user, 'web')->withSession([
            'security.version' => $user->session_version, 'auth.password_confirmed_at' => now()->timestamp,
        ]);
    }

    public function test_personal_token_is_hashed_and_bearer_authentication_does_not_need_a_cookie(): void
    {
        $user = $this->user();
        $bearer = $this->token($user);
        $token = PersonalAccessToken::sole();
        $this->assertNotSame($bearer, $token->token);
        $this->assertSame(hash('sha256', explode('|', $bearer, 2)[1]), $token->token);
        $this->assertArrayNotHasKey('token', $token->toArray());
        $response = $this->probe($bearer)->assertOk()->assertJsonPath('userId', $user->id)
            ->assertHeader('Cache-Control')->assertHeader('X-Request-ID');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertNotNull($token->fresh()->last_used_at);
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function test_a_session_cookie_cannot_replace_a_bearer_and_wildcard_tokens_are_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');
        $this->probe()->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->probe($this->token($user, ['*']))->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->probe($this->token($user, ['extension:feedback']))->assertForbidden();
    }

    #[DataProvider('unavailableAccounts')]
    public function test_bearer_refuses_current_unavailable_account_state(array $attributes): void
    {
        $user = $this->user();
        $bearer = $this->token($user);
        $user->forceFill($attributes)->save();
        $this->probe($bearer)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public static function unavailableAccounts(): array
    {
        return [
            'inactive' => [['active' => false]], 'technical' => [['is_technical' => true]],
            'locked' => [['locked_until' => '2099-01-01 00:00:00']],
            'unaccepted invitation' => [['invitation_token' => 'synthetic-invitation', 'invitation_accepted_at' => null]],
            'revocation generation' => [['session_version' => 2]],
        ];
    }

    public function test_organization_disabled_after_issuance_invalidates_bearer(): void
    {
        $organization = Organisation::create(['name' => 'Synthetic organization', 'code' => 'API_TEST', 'active' => true]);
        $user = $this->user(['organization_id' => $organization->id]);
        $bearer = $this->token($user);
        $organization->update(['active' => false]);
        $this->probe($bearer)->assertUnauthorized();
    }

    public function test_expiry_revocation_and_unlimited_lifetime_are_rejected(): void
    {
        $user = $this->user();
        foreach ([
            ['expires_at' => now()->subSecond()],
            ['revoked_at' => now()],
            ['expires_at' => null],
            ['expires_at' => now()->addDays(31)],
        ] as $attributes) {
            $bearer = $this->token($user);
            PersonalAccessToken::latest('id')->first()->forceFill($attributes)->save();
            $this->probe($bearer)->assertUnauthorized();
        }
    }

    public function test_creation_displays_secret_once_without_storing_it_in_session_or_audit(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $response = $this->post('/profile/extension-tokens', ['name' => 'Private-name-sentinel', 'expires_in_days' => 30, 'feedback' => 1])
            ->assertOk()->assertHeader('Cache-Control');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $bearer = $response->viewData('plainTextToken');
        $this->assertIsString($bearer);
        $this->assertStringNotContainsString($bearer, json_encode(session()->all()));
        $this->assertStringNotContainsString($bearer, json_encode(AuditEvent::all()->toArray()));
        $this->assertStringNotContainsString('Private-name-sentinel', json_encode(AuditEvent::all()->toArray()));
        $response = $this->get('/profile')->assertOk()->assertDontSee($bearer);
        $this->assertSame(['extension:search', 'extension:feedback'], PersonalAccessToken::sole()->abilities);
    }

    public function test_creation_requires_recent_password_and_obeys_five_token_and_thirty_day_bounds(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web')->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 30])
            ->assertRedirect(route('password.confirm'));
        $this->signIn($user);
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
            ->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 30])->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 31])->assertSessionHasErrors('expires_in_days');
        for ($i = 0; $i < 5; $i++) {
            $this->token($user);
        }
        $this->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 30])->assertSessionHasErrors('name');
        PersonalAccessToken::first()->forceFill(['revoked_at' => now()])->save();
        $this->post('/profile/extension-tokens', ['name' => 'Replacement', 'expires_in_days' => 1])->assertOk();
        $this->assertSame(6, PersonalAccessToken::count());
    }

    public function test_revocation_is_owned_audited_and_immediately_effective(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $bearer = $this->token($owner);
        $token = PersonalAccessToken::sole();
        $this->signIn($other);
        $this->delete(route('profile.extension-tokens.destroy', $token))->assertNotFound();
        $this->assertNull($token->fresh()->revoked_at);
        $this->signIn($owner);
        $this->delete(route('profile.extension-tokens.destroy', $token))->assertRedirect(route('profile.edit'));
        $this->probe($bearer)->assertUnauthorized();
        $this->assertDatabaseHas('audit_events', ['action' => 'extension.token_revoked', 'user_id' => $owner->id]);
    }

    public function test_password_change_invalidates_existing_tokens(): void
    {
        $user = $this->user();
        $bearer = $this->token($user);
        $this->signIn($user);
        $this->patch('/profile/password', [
            'current_password' => 'Synthetic-password-123', 'password' => 'Changed-password-456', 'password_confirmation' => 'Changed-password-456',
        ])->assertRedirect(route('profile.edit'));
        $this->probe($bearer)->assertUnauthorized();
    }

    public function test_mfa_policy_changes_and_enrollment_alone_do_not_grant_token_access(): void
    {
        $user = $this->user(['roles' => ['manager' => ['fr']]]);
        $bearer = $this->token($user);
        config(['fortify.mfa_enabled' => true]);
        $this->probe($bearer)->assertForbidden()->assertJsonPath('error.code', 'mfa_required');
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->signIn($user->fresh());
        $this->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 1])->assertForbidden();
    }

    public function test_real_mfa_login_issues_a_proof_invalidated_by_factor_reenrollment(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $user = $this->user(['roles' => ['manager' => ['fr']]]);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user = $user->fresh();
        $this->post('/login', ['email' => $user->email, 'password' => 'Synthetic-password-123'])->assertRedirect(route('two-factor.login'));
        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret))])->assertRedirect('/');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $response = $this->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 1])->assertOk();
        $bearer = $response->viewData('plainTextToken');
        $this->probe($bearer)->assertOk();
        // Re-enrollment can happen inside the same timestamp second.
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXA')])->save();
        $this->probe($bearer)->assertForbidden()->assertJsonPath('error.code', 'mfa_required');
    }

    public function test_shared_cumulative_user_quota_cannot_be_bypassed_by_multiple_tokens(): void
    {
        config(['browser-extension.limits.search_user' => 2, 'browser-extension.limits.search_token' => 2]);
        $user = $this->user();
        $first = $this->token($user);
        $second = $this->token($user);
        $this->probe($first)->assertOk();
        $this->probe($second)->assertOk();
        $this->probe($first)->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_ip_quota_applies_before_authentication_and_errors_never_echo_request_values(): void
    {
        config(['browser-extension.limits.ip' => 1]);
        $this->postJson('/api/browser-extension/security-probe', ['text' => 'business-sentinel'], ['Authorization' => 'Bearer token-sentinel'])
            ->assertUnauthorized()->assertDontSee('business-sentinel')->assertDontSee('token-sentinel');
        $this->probe()->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_transport_refuses_oversized_malformed_json_and_cleartext_production_calls(): void
    {
        $this->postJson('/api/browser-extension/security-probe', ['text' => str_repeat('x', 65536)])
            ->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');
        $this->call('POST', '/api/browser-extension/security-probe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{broken-json')
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_request')->assertDontSee('broken-json');
        $this->post('/api/browser-extension/security-probe', ['text' => 'Synthetic'])->assertStatus(415);
        config(['browser-extension.force_https' => true]);
        $this->postJson('http://example.test/api/browser-extension/security-probe', ['contractVersion' => 2])
            ->assertStatus(400)->assertJsonPath('error.code', 'https_required');
    }

    public function test_preflight_uses_only_configured_origins_without_cookies_and_actual_bearer_remains_required(): void
    {
        $origin = 'chrome-extension://synthetic-extension-id';
        config(['browser-extension.allowed_origins' => [$origin]]);
        $this->call('OPTIONS', '/api/browser-extension/search', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,accept',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->probe(null, ['Origin' => $origin])->assertUnauthorized()->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After, X-Request-ID');
        $this->probe(null, ['Origin' => 'https://foreign.example.org'])->assertForbidden();
    }

    public function test_failed_audit_rolls_back_token_creation(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->post('/profile/extension-tokens', ['name' => 'Synthetic', 'expires_in_days' => 1])->assertServerError();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_read_token_does_not_authorize_the_web_write_routes(): void
    {
        $bearer = $this->token($this->user());
        $this->postJson('/terms/999/proposals', [
            'attribute' => 'label', 'language' => 'fr', 'value' => 'Synthetic',
        ], ['Authorization' => 'Bearer '.$bearer])->assertUnauthorized();
        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_only_the_requested_scope_grants_feedback_access(): void
    {
        $bearer = $this->token($this->user());
        $this->postJson('/api/browser-extension/feedback', [
            'contractVersion' => 2, 'resultId' => 'cell2.synthetic', 'feedbackProof' => 'synthetic', 'verdict' => 'correct',
        ], ['Authorization' => 'Bearer '.$bearer])->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        config(['browser-extension.feedback_enabled' => false]);
        $bearer = $this->token($this->user(), ['extension:search', 'extension:feedback']);
        $this->postJson('/api/browser-extension/feedback', [
            'contractVersion' => 2, 'resultId' => 'cell2.synthetic', 'feedbackProof' => 'synthetic', 'verdict' => 'correct',
        ], ['Authorization' => 'Bearer '.$bearer])->assertForbidden()->assertJsonPath('error.code', 'feedback_not_allowed');
    }

    public function test_https_and_client_ip_are_evaluated_after_the_trusted_proxy(): void
    {
        config(['browser-extension.force_https' => true, 'browser-extension.limits.ip' => 1]);
        TrustProxies::at(['127.0.0.1']);
        try {
            $bearer = $this->token($this->user());
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
            foreach (['203.0.113.10', '203.0.113.11'] as $ip) {
                $this->postJson('http://example.test/api/browser-extension/security-probe', ['contractVersion' => 2], [
                    'Authorization' => 'Bearer '.$bearer, 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => $ip,
                ])->assertOk();
            }
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
                ->postJson('http://example.test/api/browser-extension/security-probe', ['contractVersion' => 2], [
                    'Authorization' => 'Bearer '.$bearer, 'X-Forwarded-Proto' => 'https',
                ])->assertStatus(400)->assertJsonPath('error.code', 'https_required');
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_response_budget_fails_explicitly_without_returning_a_partial_response(): void
    {
        config(['browser-extension.max_response_bytes' => 1]);
        $this->probe($this->token($this->user()))->assertStatus(503)->assertJsonPath('error.code', 'response_too_large');
    }
}
