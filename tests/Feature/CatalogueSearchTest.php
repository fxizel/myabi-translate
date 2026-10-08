<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogueSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Vaud', 'active' => true]);
        $this->reader = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['reader'], 'languages' => ['fr']]);
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturn(['label' => ['reference' => 'Reference', 'translations' => [], 'columns' => ['fr' => 'fr_CH']]]);
        $this->actingAs($this->reader);
    }

    public function test_pagination_reuses_type_totals_and_has_stable_ties(): void
    {
        $ids = [];
        for ($i = 0; $i < 31; $i++) {
            $ids[] = $this->term('key-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i < 20 ? 'server' : 'mric')->id;
        }
        DB::enableQueryLog();
        $first = $this->get('/terms?per_page=10&sort=updated')->assertOk();
        $terms = $first->viewData('terms');
        self::assertSame(31, $terms->total());
        self::assertSame(['mric' => 11, 'server' => 20], $first->viewData('typeCounts')->all());
        self::assertSame(array_slice($ids, 0, 10), $terms->pluck('id')->all());
        $aggregateQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'sum(case when type'));
        self::assertCount(1, $aggregateQueries);
        self::assertEmpty(array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'count(*) as aggregate') && str_contains($query['query'], 'terms')));
        $second = $this->get('/terms?per_page=10&sort=updated&page=2')->assertOk()->viewData('terms');
        self::assertSame(array_slice($ids, 10, 10), $second->pluck('id')->all());
        self::assertSame(31, $second->total());
    }

    public function test_counts_and_pages_apply_the_same_scope_and_prefix_filters(): void
    {
        $common = $this->term('Code Common');
        $own = $this->term('Code Own', organizationId: $this->reader->organization_id);
        $other = Organisation::create(['code' => 'FR', 'name' => 'Fribourg', 'active' => true]);
        $this->term('Code Private', organizationId: $other->id);
        $this->term('Other Code');
        $response = $this->get('/terms?q=code&search_mode=prefix&sort=key')->assertOk();
        self::assertSame([$common->id, $own->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame(2, $response->viewData('terms')->total());
        self::assertSame(['server' => 2], $response->viewData('typeCounts')->all());
        $empty = $this->get('/terms?q=absent')->assertOk();
        self::assertSame(0, $empty->viewData('terms')->total());
        self::assertSame([], $empty->viewData('typeCounts')->all());
    }

    public function test_literal_like_symbols_are_not_query_wildcards(): void
    {
        $literal = $this->term('prefix%_literal');
        $this->term('prefixXYZliteral');
        $response = $this->get('/terms?'.http_build_query(['q' => 'prefix%_', 'search_mode' => 'prefix']))->assertOk();
        self::assertSame([$literal->id], $response->viewData('terms')->pluck('id')->all());
    }

    public function test_projection_tracks_raw_sql_writes_and_transaction_rollback(): void
    {
        $term = $this->term('Original');
        self::assertSame('Original Reference', DB::table('term_search')->where('term_id', $term->id)->value('search_text'));
        DB::table('terms')->where('id', $term->id)->update(['search_text' => 'A manual translation and comment']);
        $found = $this->get('/terms?q=manual')->assertOk()->viewData('terms');
        self::assertSame([$term->id], $found->pluck('id')->all());
        DB::beginTransaction();
        DB::table('terms')->where('id', $term->id)->update(['search_text' => 'Uncommitted draft']);
        self::assertSame('Uncommitted draft', DB::table('term_search')->where('term_id', $term->id)->value('search_text'));
        DB::rollBack();
        self::assertSame('A manual translation and comment', DB::table('term_search')->where('term_id', $term->id)->value('search_text'));
        $term->delete();
        self::assertFalse(DB::table('term_search')->where('term_id', $term->id)->exists());
    }

    public function test_quoted_exact_phrase_preserves_word_order_and_literal_symbols(): void
    {
        $phrase = $this->term('A literal 50%_value phrase');
        $this->term('A literal 50percent value phrase');
        $response = $this->get('/terms?'.http_build_query(['q' => '"literal 50%_value"']))->assertOk();
        self::assertSame([$phrase->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame(0, $this->get('/terms?'.http_build_query(['q' => '"phrase literal"']))->assertOk()->viewData('terms')->total());
    }

    public function test_pending_counts_terms_once_and_preserves_language_scope_and_decision_changes(): void
    {
        $common = $this->term('Common pending');
        $own = $this->term('Own pending', organizationId: $this->reader->organization_id);
        $foreign = Organisation::create(['code' => 'FR', 'name' => 'Fribourg', 'active' => true]);
        $private = $this->term('Private pending', organizationId: $foreign->id);
        $first = $this->proposal($common, 'fr');
        $duplicate = $this->proposal($common, 'fr');
        $italian = $this->proposal($own, 'it');
        $this->proposal($private, 'fr');
        $response = $this->get('/terms?state=pending&language=fr')->assertOk();
        self::assertSame([$common->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame(['server' => 1], $response->viewData('typeCounts')->all());
        self::assertSame([$own->id], $this->get('/terms?state=pending&language=it')->assertOk()->viewData('terms')->pluck('id')->all());
        $first->update(['status' => 'validated']);
        self::assertSame(1, $this->get('/terms?state=pending&language=fr')->assertOk()->viewData('terms')->total());
        $duplicate->update(['status' => 'rejected']);
        self::assertSame(0, $this->get('/terms?state=pending&language=fr')->assertOk()->viewData('terms')->total());
        $italian->update(['language' => 'fr']);
        self::assertSame([$own->id], $this->get('/terms?state=pending&language=fr&scope=own')->assertOk()->viewData('terms')->pluck('id')->all());
        $this->reader->update(['roles' => ['manager']]);
        $this->actingAs($this->reader->fresh());
        $managed = $this->get('/terms?state=pending&language=fr')->assertOk()->viewData('terms');
        self::assertSame([$own->id, $private->id], $managed->pluck('id')->all());
        self::assertSame(2, $managed->total());
    }

    private function proposal(Term $term, string $language): Proposal
    {
        return Proposal::create(['term_id' => $term->id, 'attribute' => 'label', 'language' => $language,
            'value' => 'Synthetic pending', 'value_hash' => hash('sha256', 'Synthetic pending'), 'status' => 'pending',
            'author_id' => $this->reader->id, 'organization_id' => $this->reader->organization_id, 'anomalies' => [], 'edit_history' => []]);
    }

    private function term(string $label, string $type = 'server', ?int $organizationId = null): Term
    {
        return Term::create(['type' => $type, 'organization_id' => $organizationId, 'identity_hash' => hash('sha256', $type.$label), 'key' => [$label],
            'label' => $label, 'context' => '', 'source_text' => 'Reference', 'search_text' => $label.' Reference',
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64), 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => [], 'review_needed' => [],
            'created_by' => $this->reader->id, 'updated_by' => $this->reader->id, 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
    }
}
