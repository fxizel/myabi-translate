<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
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
        $this->proposal('other-scope', term: ['organization_id' => $this->validator->organization_id]);
        $this->proposal('other-search', term: ['search_text' => 'unrelated synthetic reference']);
        $query = [
            'per_page' => 'all', 'page' => 2, 'language' => 'fr', 'type' => 'server',
            'context' => 'test', 'scope' => 'common', 'import_id' => 7, 'q' => 'match%',
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
            'type' => 'server', 'organization_id' => null, 'identity_hash' => hash('sha256', $label), 'key' => [$label],
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
