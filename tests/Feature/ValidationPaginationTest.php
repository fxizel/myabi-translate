<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Models\TranslationCampaign;
use App\Models\User;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Vaud', 'active' => true]);
        $this->validator = User::factory()->create([
            'organization_id' => $organization->id, 'roles' => ['validator' => ['fr']], 'locale' => 'fr',
        ]);
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturn([
            'label' => ['reference' => 'Synthetic reference', 'translations' => [], 'columns' => ['fr' => 'fr_CH']],
        ]);
        $this->actingAs($this->validator);
    }

    public function test_all_results_load_in_bounded_batches_with_filters_and_stable_order(): void
    {
        $expected = $this->proposals(205);
        $this->proposal('other-language', proposal: ['language' => 'it']);
        $this->proposal('already-validated', proposal: ['status' => 'validated']);
        $this->proposal('already-rejected', proposal: ['status' => 'rejected']);
        $this->proposal('other-import', proposal: ['import_id' => 8]);
        $this->proposal('other-type', term: ['type' => 'mric']);
        $this->proposal('other-context', term: ['context' => 'other']);
        $this->proposal('other-origin', proposal: ['organization_id' => null]);
        $this->proposal('other-search', term: ['search_text' => 'unrelated synthetic reference']);
        $query = [
            'per_page' => 'all', 'page' => 2, 'language' => 'fr', 'type' => 'server',
            'context' => 'test', 'organization_id' => $this->validator->organization_id, 'import_id' => 7, 'q' => 'match%',
        ];

        $response = $this->get('/validation?'.http_build_query($query))->assertOk();
        self::assertTrue($response->viewData('showAll'));
        self::assertSame(205, $response->viewData('proposalTotal'));
        self::assertSame(array_slice($expected, 0, 100), $this->selectionIds($response->getContent()));
        $seen = $this->selectionIds($response->getContent());
        $next = $response->viewData('proposals')->nextPageUrl();

        foreach ([100, 5] as $batchSize) {
            self::assertNotNull($next);
            parse_str(parse_url($next, PHP_URL_QUERY), $nextQuery);
            foreach (array_diff_key($query, ['page' => true]) as $key => $value) {
                self::assertSame((string) $value, $nextQuery[$key]);
            }
            $batch = $this->getJson($next)->assertOk()->assertJsonStructure(['html', 'next_url']);
            $html = $batch->json('html');
            self::assertIsString($html);
            self::assertStringNotContainsString('<!DOCTYPE', $html);
            $ids = $this->selectionIds($html);
            self::assertCount($batchSize, $ids);
            $seen = [...$seen, ...$ids];
            $next = $batch->json('next_url');
        }

        self::assertNull($next);
        self::assertSame($expected, $seen);
    }

    public function test_deciding_an_earlier_proposal_does_not_skip_remaining_results(): void
    {
        $expected = $this->proposals(105);
        $first = $this->get('/validation?per_page=all&language=fr')->assertOk();
        $next = $first->viewData('proposals')->nextPageUrl();
        self::assertNotNull($next);
        Proposal::whereKey(array_slice($expected, 0, 10))->update(['status' => 'validated']);

        $remaining = $this->getJson($next)->assertOk()->assertJsonPath('next_url', null);

        self::assertSame(array_slice($expected, 100), $this->selectionIds($remaining->json('html')));
    }

    public function test_all_results_handles_an_empty_filtered_list(): void
    {
        $this->proposal('different-language', proposal: ['language' => 'it']);

        $response = $this->get('/validation?per_page=all&language=fr&page=2')->assertOk();

        self::assertTrue($response->viewData('showAll'));
        self::assertSame(0, $response->viewData('proposalTotal'));
        self::assertSame([], $this->selectionIds($response->getContent()));
        self::assertNull($response->viewData('proposals')->nextPageUrl());
        $this->getJson('/validation?per_page=all&language=fr')->assertOk()->assertJsonPath('next_url', null);
    }

    #[DataProvider('pageSizes')]
    public function test_numbered_pages_preserve_the_selected_page_size(?int $pageSize): void
    {
        $expected = $this->proposals(105);
        $query = ['language' => 'fr', 'page' => 2];
        if ($pageSize !== null) {
            $query['per_page'] = $pageSize;
        }

        $response = $this->get('/validation?'.http_build_query($query))->assertOk();
        $proposals = $response->viewData('proposals');
        $size = $pageSize ?? 25;

        self::assertFalse($response->viewData('showAll'));
        self::assertSame(105, $proposals->total());
        self::assertSame($size, $proposals->perPage());
        self::assertSame(2, $proposals->currentPage());
        self::assertSame(array_slice($expected, $size, $size), $this->selectionIds($response->getContent()));
    }

    public static function pageSizes(): array
    {
        return ['default' => [null], '25' => [25], '50' => [50], '100' => [100]];
    }

    public function test_fragments_do_not_expose_languages_outside_validator_permissions(): void
    {
        $this->proposal('french', proposal: ['language' => 'fr']);
        $this->proposal('italian', proposal: ['language' => 'it']);

        $response = $this->getJson('/validation?per_page=all&language=it')->assertOk()->assertJsonPath('next_url', null);

        self::assertSame([], $this->selectionIds($response->json('html')));
    }

    public function test_fragments_require_an_authenticated_validator(): void
    {
        $this->validator->update(['roles' => ['reader']]);
        $this->actingAs($this->validator->fresh());
        $this->getJson('/validation?per_page=all&language=fr')->assertForbidden();

        auth()->logout();
        $this->getJson('/validation?per_page=all&language=fr')->assertUnauthorized();
    }

    public function test_group_and_campaign_filters_intersect_active_terms_and_preserve_language_rights(): void
    {
        $group = $this->group();
        $campaign = $this->campaign();
        $matching = $this->proposal('matching', term: ['term_group_id' => $group->id]);
        $inactive = $this->proposal('inactive', term: ['term_group_id' => $group->id]);
        $outside = $this->proposal('outside', term: ['term_group_id' => $group->id]);
        $ungrouped = $this->proposal('ungrouped');
        $italian = $this->proposal('italian', proposal: ['language' => 'it'], term: ['term_group_id' => $group->id]);
        foreach ([$matching, $inactive, $ungrouped, $italian] as $proposal) {
            $campaign->participations()->create(['term_id' => $proposal->term_id, 'attribute' => 'label', 'active' => $proposal->id !== $inactive->id]);
        }

        $url = '/validation?language=fr&campaign_id='.$campaign->id;
        $campaignOnly = $this->get($url)->assertOk();
        self::assertSame([$matching->id, $ungrouped->id], $campaignOnly->viewData('proposals')->pluck('id')->all());
        $combined = $this->get($url.'&group_id='.$group->id.'&type=server&context=test')->assertOk();
        self::assertSame(1, $combined->viewData('proposalTotal'));
        self::assertSame([$matching->id], $combined->viewData('proposals')->pluck('id')->all());
        $combined->assertSee('name="group_id"', false)->assertSee('name="campaign_id"', false);
        self::assertTrue($combined->viewData('groups')->contains('id', $group->id));
        self::assertTrue($combined->viewData('filterCampaigns')->contains('id', $campaign->id));
        $without = $this->get($url.'&group_id=none')->assertOk();
        self::assertSame([$ungrouped->id], $without->viewData('proposals')->pluck('id')->all());
        $groupOnly = $this->get('/validation?group_id='.$group->id)->assertOk();
        self::assertSame([$matching->id, $inactive->id, $outside->id], $groupOnly->viewData('proposals')->pluck('id')->all());
        $forbiddenLanguage = $this->getJson('/validation?per_page=all&language=it&campaign_id='.$campaign->id.'&group_id='.$group->id)
            ->assertOk()->assertJsonPath('next_url', null);
        self::assertSame([], $this->selectionIds($forbiddenLanguage->json('html')));
    }

    public function test_scope_filters_survive_numbered_and_cursor_pagination(): void
    {
        $group = $this->group();
        $campaign = $this->campaign();
        $expected = [];
        for ($index = 0; $index < 105; $index++) {
            $proposal = $this->proposal('scope-'.$index, term: ['term_group_id' => $group->id]);
            $expected[] = $proposal->id;
            $campaign->participations()->create(['term_id' => $proposal->term_id, 'attribute' => 'label']);
        }
        $this->proposal('outside-campaign', term: ['term_group_id' => $group->id]);
        $query = ['language' => 'fr', 'group_id' => $group->id, 'campaign_id' => $campaign->id];
        $numbered = $this->get('/validation?'.http_build_query($query + ['per_page' => 25, 'page' => 2]))->assertOk();
        self::assertSame(105, $numbered->viewData('proposalTotal'));
        self::assertSame(array_slice($expected, 25, 25), $this->selectionIds($numbered->getContent()));
        parse_str(parse_url($numbered->viewData('proposals')->previousPageUrl(), PHP_URL_QUERY), $previous);
        self::assertSame((string) $group->id, $previous['group_id']);
        self::assertSame((string) $campaign->id, $previous['campaign_id']);

        $all = $this->get('/validation?'.http_build_query($query + ['per_page' => 'all']))->assertOk();
        self::assertSame(105, $all->viewData('proposalTotal'));
        self::assertSame(array_slice($expected, 0, 100), $this->selectionIds($all->getContent()));
        $next = $all->viewData('proposals')->nextPageUrl();
        self::assertNotNull($next);
        $remaining = $this->getJson($next)->assertOk()->assertJsonPath('next_url', null);
        self::assertSame(array_slice($expected, 100), $this->selectionIds($remaining->json('html')));
    }

    public function test_scope_filters_reject_invalid_parameters(): void
    {
        foreach (['invalid', '0', '-1'] as $group) {
            $this->getJson('/validation?group_id='.$group)->assertUnprocessable()->assertJsonValidationErrors('group_id');
        }
        foreach (['invalid', '999999'] as $campaign) {
            $this->getJson('/validation?campaign_id='.$campaign)->assertUnprocessable()->assertJsonValidationErrors('campaign_id');
        }
    }

    private function group(): TermGroup
    {
        return TermGroup::create(['title' => 'Synthetic group', 'created_by' => $this->validator->id, 'updated_by' => $this->validator->id]);
    }

    private function campaign(): TranslationCampaign
    {
        return TranslationCampaign::create(['title' => 'Synthetic campaign', 'objective' => 'translation', 'language' => 'fr',
            'created_by' => $this->validator->id, 'updated_by' => $this->validator->id]);
    }

    private function selectionIds(string $html): array
    {
        preg_match_all('/name="selection\[(\d+)\]"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private function proposals(int $count): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = $this->proposal('row-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT))->id;
        }

        return $ids;
    }

    private function proposal(string $label, array $proposal = [], array $term = []): Proposal
    {
        $source = Term::create(array_merge([
            'type' => 'server', 'identity_hash' => hash('sha256', $label), 'key' => [$label],
            'label' => $label, 'context' => 'test', 'source_text' => 'Synthetic reference', 'search_text' => 'match% '.$label,
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64), 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => [], 'review_needed' => [],
            'created_by' => $this->validator->id, 'updated_by' => $this->validator->id,
        ], $term));

        return Proposal::create(array_merge([
            'term_id' => $source->id, 'attribute' => 'label', 'language' => 'fr', 'value' => 'Synthetic proposal '.$label,
            'value_hash' => hash('sha256', $label), 'status' => 'pending', 'author_id' => $this->validator->id,
            'import_id' => 7, 'organization_id' => $this->validator->organization_id, 'anomalies' => [], 'edit_history' => [],
            'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
        ], $proposal));
    }
}
