<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Publication;
use App\Models\Term;
use App\Models\TermState;
use App\Models\User;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogueStateTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Vaud', 'active' => true]);
        $this->reader = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['reader'], 'languages' => ['fr']]);
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturnUsing(function (Term $term) {
            return array_fill_keys(FormatRegistry::get($term->type)->attributeNames, ['reference' => 'Reference', 'translations' => [], 'columns' => ['fr' => 'fr_CH']]);
        });
        $this->actingAs($this->reader);
    }

    public function test_each_format_tracks_all_attributes_and_distinguishes_empty_zero_and_null(): void
    {
        foreach (FormatRegistry::all() as $format) {
            $validated = array_fill_keys($format->attributeNames, ['fr' => '0', 'de' => '', 'it' => null]);
            $term = $this->term($format->code, $validated, [$format->attributeNames[0] => ['fr' => true, 'en' => false]]);
            $state = TermState::findOrFail($term->id);
            self::assertSame(12, $state->missing_languages, $format->code);
            self::assertSame(3, $state->validated_languages, $format->code);
            self::assertSame(2, $state->review_languages, $format->code);
            self::assertSame(0, $state->published_languages, $format->code);
            self::assertTrue(TermState::matchingState('validated', 'fr')->whereKey($term->id)->exists());
            self::assertFalse(TermState::matchingState('missing', 'de')->whereKey($term->id)->exists());
        }
    }

    public function test_partial_multi_attribute_validation_can_match_missing_and_validated_filters(): void
    {
        $attributes = FormatRegistry::get('law')->attributeNames;
        $term = $this->term('law', [$attributes[1] => ['fr' => 'Traduction secondaire']]);
        foreach (['missing', 'validated'] as $state) {
            $response = $this->get('/terms?state='.$state.'&language=fr&type=law')->assertOk();
            self::assertSame([$term->id], $response->viewData('terms')->pluck('id')->all());
            self::assertSame(['law' => 1], $response->viewData('typeCounts')->all());
        }
        $term->update(['validated' => array_fill_keys($attributes, ['fr' => 'Traduction'])]);
        self::assertSame(0, $this->get('/terms?state=missing&language=fr&type=law')->assertOk()->viewData('terms')->total());
    }

    public function test_raw_sql_changes_obsolescence_and_rollback_keep_projection_coherent(): void
    {
        $term = $this->term('server');
        self::assertSame(15, TermState::findOrFail($term->id)->missing_languages);
        DB::table('terms')->where('id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Valeur']]), 'review_needed' => json_encode(['label' => ['fr' => true]])]);
        self::assertSame(2, TermState::findOrFail($term->id)->validated_languages);
        self::assertSame(2, TermState::findOrFail($term->id)->review_languages);
        DB::beginTransaction();
        DB::table('terms')->where('id', $term->id)->update(['obsolete' => true]);
        $obsolete = TermState::findOrFail($term->id);
        self::assertSame(0, $obsolete->validated_languages);
        self::assertSame(0, $obsolete->review_languages);
        self::assertSame(0, $obsolete->missing_languages);
        self::assertTrue($obsolete->obsolete);
        DB::rollBack();
        self::assertSame(2, TermState::findOrFail($term->id)->review_languages);
        $term->update(['review_needed' => ['label' => ['fr' => false, 'it' => 'true']]]);
        self::assertSame(0, TermState::findOrFail($term->id)->review_languages);
        $term->delete();
        self::assertFalse(TermState::whereKey($term->id)->exists());
    }

    public function test_published_filter_requires_current_revision_language_available_publication_and_version(): void
    {
        $version = $this->version('2026.2');
        $otherVersion = $this->version('2026.3');
        $publication = Publication::create(['version_id' => $version->id, 'number' => '2026.2-01', 'status' => 'published',
            'types' => ['server'], 'recipients' => [$this->reader->organization_id], 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        $term = $this->term('server', ['label' => ['fr' => 'Publiée']]);
        // Publication uses bulk SQL markers; Eloquent events are not involved.
        DB::table('terms')->where('id', $term->id)->update(['last_publication_id' => $publication->id, 'last_published_revision' => 1]);
        self::assertSame(2, TermState::findOrFail($term->id)->published_languages);
        self::assertSame(1, TermState::matchingState('published', 'fr', $version->id)->count());
        self::assertSame(0, TermState::matchingState('published', 'it', $version->id)->count());
        self::assertSame(0, TermState::matchingState('published', 'fr', $otherVersion->id)->count());
        self::assertSame([$term->id], $this->get('/terms?state=published&language=fr')->assertOk()->viewData('terms')->pluck('id')->all());
        $publication->update(['status' => 'withdrawn']);
        self::assertSame(0, $this->get('/terms?state=published&language=fr')->assertOk()->viewData('terms')->total());
        $publication->update(['status' => 'published']);
        $term->update(['revision_no' => 2]);
        self::assertSame(0, TermState::findOrFail($term->id)->published_languages);
        self::assertSame(0, TermState::matchingState('published', 'fr')->count());
    }

    public function test_projection_backfill_and_state_routes_preserve_scope_and_language_filters(): void
    {
        $common = $this->term('server', ['label' => ['fr' => 'FR']], ['label' => ['fr' => true]]);
        $own = $this->term('server', ['label' => ['it' => 'IT']], ['label' => ['it' => true]], $this->reader->organization_id);
        $foreign = Organisation::create(['code' => 'FR', 'name' => 'Fribourg', 'active' => true]);
        $this->term('server', ['label' => ['fr' => 'Private']], ['label' => ['fr' => true]], $foreign->id);
        DB::table('term_states')->delete();
        $migration = require database_path('migrations/2026_09_21_200000_create_compact_term_states.php');
        $migration->up();
        self::assertSame(3, TermState::count());
        self::assertSame([$common->id], $this->get('/terms?state=review&language=fr')->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame([$own->id], $this->get('/terms?state=validated&language=it&scope=own')->assertOk()->viewData('terms')->pluck('id')->all());
        $common->update(['obsolete' => true]);
        self::assertSame([$common->id], $this->get('/terms?state=obsolete')->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame(0, $this->get('/terms?state=review&language=fr')->assertOk()->viewData('terms')->total());
    }

    public function test_dashboard_review_count_respects_scope_and_ignores_obsolete_or_false_flags(): void
    {
        $this->term('server', [], ['label' => ['fr' => true]]);
        $this->term('server', [], ['label' => ['fr' => false]]);
        $this->term('server', [], ['label' => ['fr' => true]])->update(['obsolete' => true]);
        $foreign = Organisation::create(['code' => 'FR', 'name' => 'Fribourg', 'active' => true]);
        $this->term('server', [], ['label' => ['fr' => true]], $foreign->id);
        self::assertSame(1, $this->get('/')->assertOk()->viewData('stats')['review']);
        $this->reader->update(['roles' => ['manager' => ['fr']]]);
        self::assertSame(2, $this->get('/')->assertOk()->viewData('stats')['review']);
    }

    private function version(string $number): MyabiVersion
    {
        return MyabiVersion::create(['number' => $number, 'released_at' => '2026-09-01', 'status' => 'preparation', 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
    }

    private function term(string $type, array $validated = [], array $review = [], ?int $organizationId = null): Term
    {
        $label = 'Synthetic '.bin2hex(random_bytes(5));

        return Term::create(['type' => $type, 'organization_id' => $organizationId, 'identity_hash' => hash('sha256', $type.$label), 'key' => [$label],
            'label' => $label, 'context' => '', 'source_text' => 'Reference', 'search_text' => $label.' Reference',
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64), 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => $validated, 'review_needed' => $review, 'revision_no' => 1,
            'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
    }
}
