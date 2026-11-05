<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Models\TranslationCampaign;
use App\Models\User;
use App\Services\CatalogueSearch\Profile;
use App\Services\CatalogueSearch\Projection;
use App\Services\OperationLock;
use App\Services\SearchText;
use App\Services\SourceRecord;
use App\Services\WorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogueApproximateSearchTest extends TestCase
{
    use RefreshDatabase;

    private array $attributes = [];

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $this->reader = User::factory()->create(['roles' => ['reader'], 'locale' => 'fr']);
        $this->actingAs($this->reader);
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturnUsing(fn ($term) => $this->attributes[$term->id]);
    }

    public function test_typo_and_lexical_families_require_all_words_in_one_value(): void
    {
        $positive = $this->term(['label' => ['fr' => 'Ouverture du dossier']]);
        $this->term(['label' => ['fr' => 'Clôture du dossier']]);
        $this->term(['TEXT' => ['fr' => 'Ouvrir'], 'SHORTTEXT' => ['fr' => 'Dossier']], type: 'incident');
        $this->rebuild();
        $response = $this->find('ouvrir dossier')->assertOk();
        self::assertSame([$positive->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame('complete', $response->viewData('searchInfo')['status']);
        self::assertSame(1, $response->viewData('searchInfo')['approximateCount']);
        self::assertSame(3, $this->find('dosier')->assertOk()->viewData('terms')->total());
    }

    public function test_languages_origins_pending_only_and_strict_de_reference(): void
    {
        $reference = $this->term([], reference: 'Dossier', referenceLanguage: 'de');
        $current = $this->term(['label' => ['fr' => 'Dossier']]);
        $pending = $this->term();
        $this->proposal($pending, 'Dossier');
        $this->term([], reference: 'Dossier', referenceLanguage: null);
        $this->term([], reference: 'Dossier', referenceLanguage: 'fr');
        $this->term(['label' => ['it' => 'Dossier']]);
        $germanCurrent = $this->term(['label' => ['de' => 'Dossier']]);
        $germanPending = $this->term();
        $this->proposal($germanPending, 'Dossier', language: 'de');
        $rejected = $this->term();
        $this->proposal($rejected, 'Dossier', status: 'rejected');
        $this->rebuild();
        $result = $this->find('dosier')->assertOk()->viewData('terms');
        self::assertEqualsCanonicalizing([$reference->id, $current->id, $pending->id], $result->pluck('id')->all());
        self::assertSame(3, $result->total());
        self::assertEqualsCanonicalizing([$reference->id, $germanCurrent->id, $germanPending->id], $this->find('dosier', ['language' => 'de'])->assertOk()->viewData('terms')->pluck('id')->all());
    }

    public function test_secondary_and_non_default_proposal_get_an_excerpt_without_replacing_display(): void
    {
        $term = $this->term(['TEXT' => ['fr' => 'Valeur visible']], type: 'incident');
        $matched = $this->proposal($term, 'Ouverture du dossier', attribute: 'SHORTTEXT');
        $this->proposal($term, 'Proposition visible', attribute: 'TEXT');
        $this->rebuild();
        $response = $this->find('ouvrir dossier')->assertOk()->assertSee('Valeur visible')->assertSee('Proposition visible')->assertSee('Voir la correspondance')
            ->assertSee('match_proposal='.$matched->id, false)->assertSee('#proposal-'.$matched->id, false)->assertSee('<mark>Ouverture</mark>', false);
        self::assertSame($matched->id, $response->viewData('searchInfo')['matches'][$term->id]['proposal_id']);
    }

    public function test_quoted_short_and_disabled_search_keep_their_distinct_perimeters(): void
    {
        $phrase = $this->term(['label' => ['fr' => 'Ouvrir dossier']]);
        $this->term(['label' => ['fr' => 'Ouvrir un dossier']]);
        $this->term(['label' => ['it' => 'Ouvrir dossier']]);
        $this->rebuild();
        self::assertSame([$phrase->id], $this->find('"ouvrir dossier"')->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame(0, $this->find('"dosier"')->assertOk()->viewData('terms')->total());
        self::assertSame('exact_only', $this->find('do')->assertOk()->viewData('searchInfo')['status']);
        self::assertSame(3, $this->get('/terms?q=ouvrir')->assertOk()->viewData('terms')->total());
        self::assertNull($this->get('/terms?approximate=1&search_mode=prefix&q=key')->assertOk()->viewData('searchInfo'));
        $this->get('/terms?approximate=1&search_mode=regex&q=dossier')->assertForbidden();
    }

    public function test_usual_matches_precede_all_approximate_pages_and_terms_are_counted_once(): void
    {
        $usual = [];
        for ($i = 0; $i < 12; $i++) {
            $term = $this->term(['label' => ['fr' => 'Dosier']]);
            $this->proposal($term, 'Dossier');
            $usual[] = $term->id;
        }
        $close = $this->term(['label' => ['fr' => 'Dossier']]);
        $this->proposal($close, 'Dossier');
        $this->rebuild();
        $first = $this->find('dosier', ['per_page' => 10])->assertOk();
        $second = $this->find('dosier', ['per_page' => 10, 'page' => 2])->assertOk();
        self::assertSame(array_slice($usual, 0, 10), $first->viewData('terms')->pluck('id')->all());
        self::assertSame([...array_slice($usual, 10), $close->id], $second->viewData('terms')->pluck('id')->all());
        self::assertSame(13, $second->viewData('terms')->total());
        self::assertSame(['server' => 13], $second->viewData('typeCounts')->all());
        self::assertSame(12, $second->viewData('searchInfo')['usualCount']);
        self::assertSame(1, $second->viewData('searchInfo')['approximateCount']);
        $second->assertDontSee('data-sortable-table', false);
    }

    public function test_filters_apply_equally_to_usual_and_approximate_matches(): void
    {
        $one = $this->term(['label' => ['fr' => 'Dossier']]);
        $two = $this->term(['TEXT' => ['fr' => 'Dossier']], type: 'incident');
        DB::table('terms')->where('id', $two->id)->update(['context' => 'other', 'obsolete' => true]);
        $this->proposal($one, 'Dossier');
        $this->rebuild();
        self::assertSame([$one->id], $this->find('dosier', ['type' => 'server', 'state' => 'pending'])->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame([$two->id], $this->find('dosier', ['context' => 'other', 'state' => 'obsolete'])->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame(0, $this->find('dosier', ['type' => 'server', 'context' => 'other'])->assertOk()->viewData('terms')->total());
    }

    public function test_targeted_updates_rejections_and_rollback_are_transactional(): void
    {
        $term = $this->term();
        $proposal = $this->proposal($term, 'Dossier');
        $this->rebuild();
        DB::beginTransaction();
        DB::table('proposals')->where('id', $proposal->id)->update(['value' => 'Archive']);
        self::assertTrue(DB::table('catalogue_search_dirty')->exists());
        DB::rollBack();
        self::assertFalse(DB::table('catalogue_search_dirty')->exists());
        $manager = User::factory()->create(['roles' => ['manager']]);
        app(WorkflowService::class)->decide($manager, $proposal, 'reject', $proposal->lock_version, 'Not relevant');
        self::assertSame(0, $this->find('dosier')->assertOk()->viewData('terms')->total());
        self::assertTrue((bool) DB::table('catalogue_search_state')->value('ready'));
        self::assertFalse(DB::table('catalogue_search_values')->where('origin', 'proposal')->where('origin_id', $proposal->id)->exists());
    }

    public function test_failed_index_write_keeps_business_change_but_requires_explicit_repair(): void
    {
        $term = $this->term(['label' => ['fr' => 'Dossier']]);
        $this->rebuild();
        $projection = Mockery::mock(Projection::class, [app(Profile::class), app(SourceRecord::class), app(OperationLock::class)])->makePartial()->shouldAllowMockingProtectedMethods();
        $projection->shouldReceive('write')->andReturnUsing(function () {
            DB::table('catalogue_search_values')->delete();
            throw new \RuntimeException('Private sentinel');
        });
        $this->app->instance(Projection::class, $projection);
        DB::transaction(function () use ($term) {
            DB::table('terms')->where('id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Dossier nouveau']])]);
            app(SearchText::class)->sync($term->fresh());
        });
        self::assertNotSame(0, DB::table('catalogue_search_values')->count());
        self::assertStringContainsString('nouveau', DB::table('terms')->where('id', $term->id)->value('validated'));
        $response = $this->find('dossier')->assertOk()->assertDontSee('Private sentinel');
        self::assertSame('unavailable', $response->viewData('searchInfo')['status']);
        self::assertSame(1, $response->viewData('terms')->total());
        $this->app->forgetInstance(Projection::class);
        app(SearchText::class)->sync($term->fresh());
        self::assertFalse((bool) DB::table('catalogue_search_state')->value('ready'));
        $this->rebuild();
        self::assertSame('complete', $this->find('dosier')->assertOk()->viewData('searchInfo')['status']);
    }

    public function test_work_limits_discard_every_partial_approximate_result(): void
    {
        $usual = $this->term(['label' => ['fr' => 'Dosier']]);
        $this->term(['label' => ['fr' => 'Dossier']]);
        $this->rebuild();
        config(['catalogue-search.max_occurrences' => 0]);
        $response = $this->find('dosier')->assertOk();
        self::assertSame('timeout', $response->viewData('searchInfo')['status']);
        self::assertSame([$usual->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame(0, $response->viewData('searchInfo')['approximateCount']);
    }

    #[DataProvider('rebuildMutations')]
    public function test_interrupted_rebuild_resumes_only_a_compatible_generation(bool $mutate): void
    {
        $terms = collect(range(1, 3))->map(fn () => $this->term(['label' => ['fr' => 'Dossier']]));
        $this->rebuild();
        $active = (int) DB::table('catalogue_search_state')->value('active_generation');
        config(['catalogue-search.batch_size' => 2]);
        $projection = Mockery::mock(Projection::class, [app(Profile::class), app(SourceRecord::class), app(OperationLock::class)])->makePartial()->shouldAllowMockingProtectedMethods();
        $projection->shouldReceive('write')->once()->ordered()->passthru();
        $projection->shouldReceive('write')->once()->ordered()->andThrow(new \RuntimeException('Interrupted synthetic build'));
        try {
            $projection->rebuild();
            self::fail('The second batch must interrupt reconstruction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Interrupted synthetic build', $error->getMessage());
        }
        $state = DB::table('catalogue_search_state')->first();
        self::assertSame($active, (int) $state->active_generation);
        self::assertFalse((bool) $state->ready);
        self::assertSame($terms[1]->id, (int) $state->cursor);
        $retained = DB::table('catalogue_search_values')->where('generation', $state->build_generation)->orderBy('id')->pluck('id')->all();
        self::assertNotEmpty($retained);
        if ($mutate) {
            DB::table('terms')->where('id', $terms[0]->id)->update(['validated' => json_encode(['label' => ['fr' => 'Archive']])]);
        }
        $result = app(Projection::class)->rebuild();
        self::assertSame($active + ($mutate ? 2 : 1), $result['generation']);
        self::assertTrue((bool) DB::table('catalogue_search_state')->value('ready'));
        self::assertFalse(DB::table('catalogue_search_dirty')->exists());
        self::assertFalse(DB::table('catalogue_search_values')->where('generation', '<>', $result['generation'])->exists());
        if (! $mutate) {
            self::assertSame(count($retained), DB::table('catalogue_search_values')->whereIn('id', $retained)->count(), 'A compatible resume must keep completed batches.');
        }
        self::assertSame($mutate ? 2 : 3, $this->find('dosier')->assertOk()->viewData('terms')->total());
    }

    public static function rebuildMutations(): array
    {
        return ['compatible' => [false], 'changed catalogue' => [true]];
    }

    public function test_elapsed_budget_returns_the_prepared_page_without_partial_approximate_totals(): void
    {
        $usual = $this->term(['label' => ['fr' => 'Dosier']]);
        $this->term(['label' => ['fr' => 'Dossier']]);
        $this->rebuild();
        config(['catalogue-search.work_seconds' => 2]);
        $delayed = false;
        DB::listen(function ($event) use (&$delayed) {
            if (! $delayed && str_starts_with($event->sql, 'CREATE TEMPORARY TABLE cs_variants_')) {
                $delayed = true;
                usleep(2050000);
            }
        });
        $response = $this->find('dosier')->assertOk();
        self::assertTrue($delayed);
        self::assertSame('timeout', $response->viewData('searchInfo')['status']);
        self::assertSame([$usual->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame(1, $response->viewData('searchInfo')['usualCount']);
        self::assertSame(0, $response->viewData('searchInfo')['approximateCount']);
    }

    public function test_repeated_words_and_negative_words_are_not_discarded(): void
    {
        $this->term(['label' => ['fr' => 'Dossier avec erreur']]);
        $repeat = $this->term(['label' => ['fr' => 'Dossier dossier sans erreur']]);
        $this->rebuild();
        self::assertSame([$repeat->id], $this->find('dosier dosier sans erreur')->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame([$repeat->id], $this->find('dossier sans erreur')->assertOk()->viewData('terms')->pluck('id')->all());
    }

    public function test_group_campaign_and_version_filters_share_the_same_counted_set(): void
    {
        $one = $this->term(['label' => ['fr' => 'Dosier']]);
        $two = $this->term(['label' => ['fr' => 'Dossier']]);
        $three = $this->term(['label' => ['fr' => 'Dossier']]);
        $group = TermGroup::create(['title' => 'Synthetic group', 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        DB::table('terms')->whereIn('id', [$one->id, $two->id])->update(['term_group_id' => $group->id]);
        $campaign = TranslationCampaign::create(['title' => 'Synthetic campaign', 'objective' => 'translate', 'language' => 'fr',
            'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        foreach ([$two, $three] as $term) {
            DB::table('campaign_participations')->insert(['translation_campaign_id' => $campaign->id, 'term_id' => $term->id, 'attribute' => 'label', 'active' => true]);
        }
        $version = MyabiVersion::create(['number' => 'synthetic', 'released_at' => '2026-01-01', 'status' => 'current', 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        ImportBatch::create(['id' => 5000, 'type' => 'server', 'source_kind' => 'publisher', 'version_id' => $version->id, 'filename' => 'synthetic.csv', 'path' => 'unused.csv',
            'sha256' => str_repeat('a', 64), 'size' => 0, 'status' => 'applied', 'format_version' => 'synthetic', 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        DB::table('import_rows')->insert(['import_id' => 5000, 'record_number' => 1, 'offset' => 0, 'length' => 1, 'identity_hash' => str_repeat('b', 64), 'term_id' => $two->id, 'category' => 'new']);
        $this->rebuild();
        $result = $this->find('dosier', ['group_id' => $group->id, 'campaign_id' => $campaign->id, 'version_id' => $version->id])->assertOk();
        self::assertSame([$two->id], $result->viewData('terms')->pluck('id')->all());
        self::assertSame(['server' => 1], $result->viewData('typeCounts')->all());
        self::assertSame([$three->id], $this->find('dosier', ['group_id' => 'none'])->assertOk()->viewData('terms')->pluck('id')->all());
    }

    public function test_printf_variables_and_signed_numbers_keep_their_syntax(): void
    {
        $variable = $this->term(['label' => ['fr' => 'Dossier %s']]);
        $this->term(['label' => ['fr' => 'Dossier s']]);
        $negative = $this->term(['label' => ['fr' => 'Dossier -13']]);
        $this->term(['label' => ['fr' => 'Dossier 13']]);
        $this->rebuild();
        self::assertSame([$variable->id], $this->find('dosier %s')->assertOk()->viewData('terms')->pluck('id')->all());
        self::assertSame([$negative->id], $this->find('dosier -13')->assertOk()->viewData('terms')->pluck('id')->all());
    }

    public function test_unprepared_reference_outside_the_scope_does_not_break_the_ordinary_fallback(): void
    {
        $included = $this->term(['label' => ['fr' => 'Dossier']]);
        $excluded = $this->term(['label' => ['fr' => 'Archive']]);
        DB::table('terms')->where('id', $included->id)->update(['context' => 'included']);
        DB::table('terms')->where('id', $excluded->id)->update(['context' => 'excluded']);
        $this->rebuild();
        DB::table('terms')->where('id', $excluded->id)->update(['source_hash' => str_repeat('e', 64)]);
        $response = $this->find('dossier', ['context' => 'included'])->assertOk();
        self::assertSame('unavailable', $response->viewData('searchInfo')['status']);
        self::assertSame([$included->id], $response->viewData('terms')->pluck('id')->all());
        DB::table('terms')->where('id', $included->id)->update(['type' => 'incident']);
        $this->find('dossier', ['context' => 'included'])->assertRedirect()->assertSessionHasErrors('q');
    }

    public function test_unavailable_complete_ordinary_list_keeps_filters_without_a_redirect_or_false_zero(): void
    {
        $this->term(['label' => ['fr' => 'Dossier']]);
        $this->rebuild();
        $failed = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$failed) {
            if (str_contains($sql, 'COUNT(*) AS total')) {
                $failed = true;
                throw new QueryException(DB::connection()->getName(), $sql, [], new \PDOException('Private SQL sentinel'));
            }
        });
        $response = $this->find('dosier', ['context' => 'kept'])->assertStatus(503)->assertSee('data-search-error', false)
            ->assertSee('value="dosier"', false)->assertSee('value="kept"', false)->assertDontSee('Private SQL sentinel')
            ->assertDontSee('id="bulk-terms"', false)->assertDontSee('data-search-state', false);
        self::assertTrue($failed);
        self::assertTrue($response->viewData('searchError'));
    }

    public function test_front_queue_age_reduces_the_budget_and_a_client_header_cannot_override_it(): void
    {
        $term = $this->term(['label' => ['fr' => 'Dossier']]);
        $this->rebuild();
        $this->withServerVariables(['MYABI_REQUEST_STARTED_AT' => microtime(true) - 20])->find('dosier')->assertStatus(503)->assertSee('data-search-error', false);
        // The revised ceiling leaves time for a request queued for thirteen seconds.
        $this->withServerVariables(['MYABI_REQUEST_STARTED_AT' => microtime(true) - 13])->find('dosier')->assertOk();
        $response = $this->withServerVariables(['MYABI_REQUEST_STARTED_AT' => ''])->withHeaders(['MYABI_REQUEST_STARTED_AT' => (string) (microtime(true) - 20)])->find('dosier')->assertOk();
        self::assertSame('complete', $response->viewData('searchInfo')['status']);
        self::assertSame([$term->id], $response->viewData('terms')->pluck('id')->all());
    }

    public function test_expired_count_budget_does_not_start_a_page_query(): void
    {
        $this->term(['label' => ['fr' => 'Dossier']]);
        $this->rebuild();
        config(['catalogue-search.work_seconds' => 1]);
        $delayed = $pageRead = false;
        DB::listen(function ($event) use (&$delayed, &$pageRead) {
            if (! $delayed && str_contains($event->sql, 'COUNT(*) AS total')) {
                $delayed = true;
                usleep(1050000);
            }
            if (str_contains($event->sql, 'order by') && str_contains($event->sql, 'label')) {
                $pageRead = true;
            }
        });
        $this->find('dossier')->assertStatus(503)->assertSee('data-search-error', false);
        self::assertTrue($delayed);
        self::assertFalse($pageRead, 'Recompute the remaining budget before each statement, not once for count and page together.');
    }

    public function test_non_latest_primary_proposal_keeps_the_default_and_links_to_its_actual_page(): void
    {
        $term = $this->term(['label' => ['fr' => 'Valeur en vigueur']]);
        $matched = $this->proposal($term, 'Dossier');
        for ($i = 0; $i < 51; $i++) {
            $this->proposal($term, 'Proposition visible '.$i);
        }
        $this->rebuild();
        $response = $this->find('dosier')->assertOk()->assertSee('Proposition visible 50')->assertSee('Voir la correspondance')->assertSee('match_proposal='.$matched->id, false);
        self::assertSame('Proposition visible 50', $response->viewData('terms')->first()->pending_value);
        app(SourceRecord::class)->shouldReceive('row')->andReturn([]);
        $detail = $this->get('/terms/'.$term->id.'?match_proposal='.$matched->id)->assertOk()->assertSee('id="proposal-'.$matched->id.'"', false);
        self::assertSame(2, $detail->viewData('proposals')->currentPage());
        self::assertSame(1, $this->get('/terms/'.$term->id.'?match_proposal='.$matched->id.'&page=1')->assertOk()->viewData('proposals')->currentPage());
        $other = $this->term();
        $this->get('/terms/'.$other->id.'?match_proposal='.$matched->id)->assertNotFound();
    }

    public function test_order_preference_literal_wildcards_and_stale_reference_guard(): void
    {
        $ordered = $this->term(['label' => ['fr' => 'Date de création du dossier']]);
        $this->term(['label' => ['fr' => 'Dossier — date de création']]);
        $literal = $this->term(['label' => ['fr' => 'Valeur 50%_exacte']]);
        $this->term(['label' => ['fr' => 'Valeur 50XXexacte']]);
        $this->rebuild();
        self::assertSame($ordered->id, $this->find('date création dossier')->assertOk()->viewData('terms')->first()->id);
        self::assertSame([$literal->id], $this->find('"50%_exacte"')->assertOk()->viewData('terms')->pluck('id')->all());
        DB::table('terms')->where('id', $ordered->id)->update(['source_hash' => str_repeat('c', 64)]);
        $this->find('date')->assertRedirect()->assertSessionHasErrors('q');
    }

    public function test_large_scope_uses_the_full_word_candidates_and_phrase_intersection(): void
    {
        for ($i = 0; $i < 501; $i++) {
            $this->term();
        }
        $usual = $this->term(['label' => ['fr' => 'Dosier']]);
        $close = $this->term(['label' => ['fr' => 'Dossier']]);
        $phrase = $this->term(['label' => ['fr' => 'Ouvrir dossier']]);
        $family = $this->term(['label' => ['fr' => 'Ouverture du dossier']]);
        $this->rebuild();
        $response = $this->find('dosier')->assertOk();
        self::assertSame('complete', $response->viewData('searchInfo')['status']);
        self::assertSame($usual->id, $response->viewData('terms')->first()->id);
        self::assertEqualsCanonicalizing([$usual->id, $close->id, $phrase->id, $family->id], $response->viewData('terms')->pluck('id')->all());
        $response = $this->find('ouvrir dossier')->assertOk();
        self::assertSame([$phrase->id, $family->id], $response->viewData('terms')->pluck('id')->all());
        self::assertSame(1, $response->viewData('searchInfo')['usualCount']);
        self::assertSame(1, $response->viewData('searchInfo')['approximateCount']);
        $single = $this->find('dossire')->assertOk();
        self::assertSame('complete', $single->viewData('searchInfo')['status']);
        self::assertEqualsCanonicalizing([$close->id, $phrase->id, $family->id], $single->viewData('terms')->pluck('id')->all());
        config(['catalogue-search.max_spool_bytes' => 8]);
        $limited = $this->find('dosier')->assertOk();
        self::assertSame('timeout', $limited->viewData('searchInfo')['status']);
        self::assertSame([$usual->id], $limited->viewData('terms')->pluck('id')->all());
        $failedRead = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$failedRead) {
            if (! $failedRead && str_contains($sql, 'catalogue_search_words')) {
                $failedRead = true;
                throw new QueryException(DB::connection()->getName(), $sql, [], new \PDOException('Private vocabulary failure'));
            }
        });
        $fallback = $this->find('dossier')->assertOk()->assertDontSee('Private vocabulary failure');
        self::assertTrue($failedRead);
        self::assertSame('unavailable', $fallback->viewData('searchInfo')['status']);
        self::assertSame(3, $fallback->viewData('searchInfo')['usualCount']);
        self::assertSame(0, $fallback->viewData('searchInfo')['approximateCount']);
    }

    private function find(string $query, array $filters = [])
    {
        return $this->get('/terms?'.http_build_query(['q' => $query, 'language' => 'fr', 'approximate' => 1, 'sort' => 'key', ...$filters]));
    }

    private function rebuild(): void
    {
        app(Projection::class)->rebuild();
    }

    private function term(array $validated = [], string $type = 'server', string $reference = 'Referenz', ?string $referenceLanguage = 'de'): Term
    {
        $label = 'key-'.str_pad((string) (Term::count() + 1), 5, '0', STR_PAD_LEFT);
        $term = Term::create(['type' => $type, 'identity_hash' => hash('sha256', $label), 'key' => [$label], 'label' => $label, 'context' => '',
            'source_text' => $reference, 'search_text' => $reference.' '.implode(' ', array_merge(...array_values($validated ?: [[]]))),
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => hash('sha256', $reference), 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => $validated, 'review_needed' => [], 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        foreach (FormatRegistry::get($type)->attributeNames as $attribute) {
            $this->attributes[$term->id][$attribute] = ['reference' => $reference, 'reference_language' => $referenceLanguage, 'translations' => [], 'columns' => ['fr' => 'fr_CH']];
        }

        return $term;
    }

    private function proposal(Term $term, string $value, string $language = 'fr', string $status = 'pending', string $attribute = 'label'): Proposal
    {
        return Proposal::create(['term_id' => $term->id, 'attribute' => $attribute, 'language' => $language, 'value' => $value,
            'value_hash' => hash('sha256', $value), 'status' => $status, 'lock_version' => 1, 'author_id' => $this->reader->id,
            'anomalies' => [], 'edit_history' => []]);
    }
}
