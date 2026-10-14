<?php

namespace Tests\Feature;

use App\Models\Term;
use App\Models\User;
use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\FeedbackService;
use App\Services\BrowserExtension\RequestValidator;
use App\Services\BrowserExtension\SearchProjection;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BrowserExtensionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private string $bearer;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fortify.mfa_enabled' => false, 'browser-extension.enabled' => true,
            'browser-extension.force_https' => false, 'browser-extension.feedback_enabled' => true,
            'browser-extension.rate_limit_store' => 'array',
        ]);
        $this->reader = User::factory()->create(['roles' => ['reader'], 'active' => true]);
        $this->bearer = $this->token($this->reader);
        $this->term = Term::create([
            'type' => 'server', 'identity_hash' => hash('sha256', 'SYNTHETIC_KEY'), 'key' => ['SYNTHETIC_KEY'],
            'label' => 'SYNTHETIC_KEY', 'context' => '', 'source_text' => 'Reference', 'search_text' => 'Reference',
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => ['label' => ['fr' => 'Libellé validé']], 'review_needed' => [],
            'created_by' => $this->reader->id, 'updated_by' => $this->reader->id,
        ]);
    }

    private function token(User $user, array $abilities = ['extension:search', 'extension:feedback']): string
    {
        $created = $user->createToken('Synthetic test', $abilities, now()->addDay());
        $user->withAccessToken($created->accessToken);

        return $created->plainTextToken;
    }

    private function response(): array
    {
        return app(FeedbackService::class)->decorate($this->reader, [
            'contractVersion' => 2, 'query' => 'Synthetic inspected text', 'results' => [[
                'id' => 'cell2.'.rtrim(strtr(base64_encode(json_encode([$this->term->id, 'label', 'fr'])), '+/', '-_'), '='),
                'termId' => $this->term->id, 'type' => 'server', 'keyParts' => $this->term->key,
                'attribute' => 'label', 'targetLanguage' => 'fr',
            ]],
        ]);
    }

    private function payload(array $result): array
    {
        return ['contractVersion' => 2, 'resultId' => $result['id'], 'feedbackProof' => $result['feedbackProof'], 'verdict' => 'correct'];
    }

    public function test_signed_feedback_is_idempotent_private_and_has_no_workflow_effect(): void
    {
        $before = DB::table('terms')->where('id', $this->term->id)->first();
        $result = $this->response()['results'][0];
        $payload = $this->payload($result) + ['text' => 'PRIVATE_SENTINEL', 'key' => 'DISPLAY_ONLY', 'pageUrl' => 'https://example.org/private'];
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $payload)->assertOk()->assertExactJson(['ok' => true]);
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $payload)->assertOk();
        self::assertSame(1, DB::table('browser_extension_feedback')->count());
        self::assertEquals($before, DB::table('terms')->where('id', $this->term->id)->first());
        self::assertSame(0, DB::table('proposals')->count());
        self::assertSame(0, DB::table('publications')->count());
        self::assertSame(0, DB::table('campaign_events')->count());
        $stored = json_encode(DB::table('browser_extension_feedback')->first());
        self::assertStringNotContainsString('PRIVATE_SENTINEL', $stored);
        self::assertStringNotContainsString('example.org', $stored);
        self::assertStringNotContainsString($result['feedbackProof'], $stored);
    }

    public function test_modified_proof_identity_or_subject_is_not_accepted(): void
    {
        $payload = $this->payload($this->response()['results'][0]);
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', array_replace($payload, ['feedbackProof' => 'invalid.signature']))
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', array_replace($payload, ['resultId' => 'cell2.invalid']))->assertNotFound();
        $otherToken = $this->token($this->reader);
        $this->withToken($otherToken)->postJson('/api/browser-extension/feedback', $payload)->assertNotFound();
        $other = User::factory()->create(['roles' => ['reader'], 'active' => true]);
        $this->withToken($this->token($other))->postJson('/api/browser-extension/feedback', $payload)->assertNotFound();
        self::assertSame(0, DB::table('browser_extension_feedback')->count());
    }

    public function test_expired_or_changed_result_requires_new_search(): void
    {
        $payload = $this->payload($this->response()['results'][0]);
        $this->travel(16)->minutes();
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $payload)->assertStatus(409)->assertJsonPath('error.code', 'result_stale');
        $this->travelBack();
        $payload = $this->payload($this->response()['results'][0]);
        DB::table('terms')->where('id', $this->term->id)->increment('revision_no');
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $payload)->assertStatus(409);
        self::assertSame(0, DB::table('browser_extension_feedback')->count());
    }

    public function test_feedback_rechecks_revocation_and_account_changes_after_request_authentication(): void
    {
        $payload = $this->payload($this->response()['results'][0]);
        $this->reader->currentAccessToken()->forceFill(['revoked_at' => now()])->save();
        try {
            app(FeedbackService::class)->submit($this->reader, app(RequestValidator::class)->feedback($payload));
            self::fail('An in-flight revocation was ignored.');
        } catch (ApiProblem $error) {
            self::assertSame(401, $error->status);
        }
        $this->bearer = $this->token($this->reader);
        $payload = $this->payload($this->response()['results'][0]);
        DB::table('users')->where('id', $this->reader->id)->update(['active' => false]);
        try {
            app(FeedbackService::class)->submit($this->reader, app(RequestValidator::class)->feedback($payload));
            self::fail('An in-flight deactivation was ignored.');
        } catch (ApiProblem $error) {
            self::assertSame(401, $error->status);
        }
        self::assertSame(0, DB::table('browser_extension_feedback')->count());
    }

    public function test_search_only_or_disabled_feedback_cannot_write(): void
    {
        $payload = $this->payload($this->response()['results'][0]);
        $this->withToken($this->token($this->reader, ['extension:search']))->postJson('/api/browser-extension/feedback', $payload)->assertForbidden();
        self::assertFalse($this->response()['feedbackAllowed']);
        self::assertArrayNotHasKey('feedbackProof', $this->response()['results'][0]);
        config(['browser-extension.feedback_enabled' => false]);
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $payload)->assertForbidden();
        self::assertSame(0, DB::table('browser_extension_feedback')->count());
    }

    public function test_long_identity_is_not_truncated_for_signature(): void
    {
        DB::table('terms')->where('id', $this->term->id)->update(['key' => json_encode([str_repeat('LONG_KEY_', 1500)])]);
        $this->term->refresh();
        $result = $this->response()['results'][0];
        self::assertLessThan(4096, strlen($result['feedbackProof']));
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $this->payload($result))->assertOk();
    }

    public function test_purge_only_deletes_expired_feedback_and_preserves_audits(): void
    {
        $payload = $this->payload($this->response()['results'][0]);
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $payload)->assertOk();
        $auditCount = DB::table('audit_events')->count();
        $this->artisan('extension:prune-feedback')->assertSuccessful();
        self::assertSame(1, DB::table('browser_extension_feedback')->count());
        DB::table('browser_extension_feedback')->update(['created_at' => now()->subDays(91)]);
        $this->artisan('extension:prune-feedback')->assertSuccessful();
        self::assertSame(0, DB::table('browser_extension_feedback')->count());
        self::assertSame($auditCount, DB::table('audit_events')->count());
    }

    public function test_real_http_search_returns_projection_result_and_scoped_feedback_proof(): void
    {
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturn([
            'label' => ['reference' => 'Beispiel', 'reference_language' => 'de',
                'translations' => ['fr' => 'Libellé importé'], 'columns' => ['fr' => 'fr_CH']],
        ]);
        app(SearchProjection::class)->rebuild();
        $response = $this->withToken($this->bearer)->postJson('/api/browser-extension/search', [
            'contractVersion' => 2, 'text' => 'Libellé importé', 'textTruncated' => false, 'lang' => 'fr-CH', 'maxResults' => 1,
        ])->assertOk()->assertJsonPath('results.0.termId', $this->term->id)->assertJsonPath('results.0.valueOrigin', 'imported')
            ->assertJsonPath('results.0.currentTranslation', 'Libellé validé')->assertJsonPath('results.0.status', 'validated')
            ->assertJsonPath('feedbackAllowed', true)->assertHeader('X-Request-ID');
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', $this->payload($response->json('results.0')))->assertOk();
    }

    public function test_validation_and_server_failures_never_log_or_echo_inspected_values(): void
    {
        Log::spy();
        $response = $this->withToken($this->bearer)->postJson('/api/browser-extension/search', [
            'contractVersion' => 2, 'text' => 'PRIVATE_TEXT_SENTINEL', 'textTruncated' => false, 'lang' => 'fr',
            'pageTitle' => str_repeat('PRIVATE_TITLE_SENTINEL', 30),
        ])->assertStatus(422);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString('PRIVATE_TEXT_SENTINEL', $response->getContent());
        self::assertStringNotContainsString('PRIVATE_TITLE_SENTINEL', $response->getContent());
        $this->app->bind(RequestValidator::class, fn () => throw new \RuntimeException('PRIVATE_SERVER_SENTINEL '.$this->bearer));
        $response = $this->withToken($this->bearer)->postJson('/api/browser-extension/feedback', ['contractVersion' => 2])->assertStatus(500);
        self::assertStringNotContainsString('PRIVATE_SERVER_SENTINEL', $response->getContent());
        self::assertStringNotContainsString($this->bearer, $response->getContent());
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }
}
