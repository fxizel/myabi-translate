<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\ProposalFilter;
use App\Services\SourceRecord;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProposalProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Organisation $origin;

    private Organisation $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $this->origin = Organisation::create(['code' => 'QA', 'name' => 'Synthetic origin', 'active' => true]);
        $this->other = Organisation::create(['code' => 'QB', 'name' => 'Synthetic other origin', 'active' => true]);
        $this->actor = User::factory()->create(['organization_id' => $this->origin->id, 'roles' => ['validator' => ['fr']], 'locale' => 'fr']);
        $source = $this->mock(SourceRecord::class);
        $source->shouldReceive('attributes')->andReturn(['label' => ['reference' => 'Synthetic reference', 'translations' => [], 'columns' => ['fr' => 'fr_CH']]]);
        $source->shouldReceive('row')->andReturn(['Name' => 'Synthetic key', 'de_CH' => 'Synthetic reference']);
        $this->actingAs($this->actor);
    }

    public function test_filters_cover_historical_origin_and_every_import_without_duplicate_counts(): void
    {
        $main = $this->import($this->origin);
        $additional = $this->import($this->other);
        $sameOrganization = $this->import($this->other);
        $proposal = $this->proposal($this->term('Shared key'), $main);
        $proposal->imports()->attach([$main->id, $additional->id, $sameOrganization->id]);
        $historical = $this->proposal($this->term('Manual key'));
        $this->actor->update(['organization_id' => $this->other->id]);
        $filter = app(ProposalFilter::class);

        foreach ([$main, $additional, $sameOrganization] as $import) {
            self::assertSame([$proposal->id], $filter->query($this->actor, ['language' => 'fr', 'import_id' => $import->id])->pluck('id')->all());
        }
        self::assertSame(1, $filter->query($this->actor, ['language' => 'fr', 'organization_id' => $this->other->id])->count());
        self::assertSame([$proposal->id, $historical->id], $filter->query($this->actor, ['language' => 'fr', 'organization_id' => $this->origin->id])->orderBy('id')->pluck('id')->all());
        $this->get('/validation?organization_id='.$this->other->id)->assertOk()->assertViewHas('proposalTotal', 1)
            ->assertSee('Organisation d’origine')->assertSee('Synthetic origin')->assertSee('#'.$additional->id);
    }

    public function test_correction_preserves_origin_and_displays_imports_as_historical_sources(): void
    {
        $main = $this->import($this->origin);
        $proposal = $this->proposal($this->term('Corrected key'), $main);
        $proposal->imports()->attach($main->id);
        $this->actor->update(['organization_id' => $this->other->id]);
        $corrected = app(WorkflowService::class)->edit($this->actor, $proposal, 'Synthetic correction', $proposal->lock_version);

        self::assertSame($this->origin->id, $corrected->organization_id);
        self::assertSame($this->origin->id, $corrected->edit_history[0]['organization_id']);
        self::assertContains($main->id, $corrected->edit_history[0]['import_ids']);
        $this->get('/validation')->assertOk()->assertSee('Synthetic correction')->assertSee('Synthetic origin')
            ->assertSee('Ces imports documentent les valeurs d’origine');
        self::assertSame(1, app(ProposalFilter::class)->query($this->actor, ['language' => 'fr', 'organization_id' => $this->origin->id])->count());
    }

    public function test_legacy_links_redirect_and_revision_lineages_remain_distinct(): void
    {
        $current = $this->term('Common key');
        $legacy = $this->term('Legacy key');
        $legacy->update(['consolidated_into_id' => $current->id]);
        DB::table('term_consolidations')->insert(['legacy_term_id' => $legacy->id, 'common_term_id' => $current->id, 'metadata' => '{}', 'created_at' => now()]);
        foreach ([$current, $legacy] as $term) {
            Revision::create(['term_id' => $term->id, 'number' => 1, 'version_id' => 1, 'source_import_id' => 1,
                'source_offset' => 0, 'source_length' => 1, 'validated' => ['label' => ['fr' => $term->label]], 'metadata' => ['key' => $term->key],
                'origin' => 'import', 'origin_id' => 1, 'created_by' => $this->actor->id, 'created_at' => now()]);
        }
        $this->get('/terms/'.$legacy->id.'?language=fr')->assertRedirect(route('terms.show', ['term' => $current->id, 'language' => 'fr', 'lineage' => $legacy->id]));
        $page = $this->get('/terms/'.$current->id.'?lineage='.$legacy->id.'&from=1&to=1')->assertOk();
        self::assertSame($legacy->id, $page->viewData('revisions')->first()->term_id);
        self::assertSame('Legacy key', data_get($page->viewData('comparison'), 'from.validated.label.fr'));
        $page->assertSee('Historique conservé sans renumérotation')->assertSee('lineage='.$legacy->id.'&restore_revision=1', false);
        $revisionId = $legacy->revisions()->firstOrFail()->id;
        $this->get('/terms/'.$current->id.'?lineage='.$legacy->id.'&restore_revision=1')->assertOk()
            ->assertSee('name="restored_revision_id" value="'.$revisionId.'"', false)->assertSee('Legacy key');
        $this->get('/terms/'.$current->id.'?lineage=999')->assertNotFound();
        $this->post('/terms/'.$current->id.'/scope', ['lock_version' => 1])->assertNotFound();
    }

    private function term(string $key): Term
    {
        return Term::create(['type' => 'server', 'identity_hash' => hash('sha256', $key), 'key' => [$key], 'label' => $key, 'context' => '',
            'source_text' => 'Synthetic reference', 'search_text' => $key, 'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1, 'validated' => [], 'review_needed' => [],
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id])->fresh();
    }

    private function proposal(Term $term, ?ImportBatch $import = null): Proposal
    {
        return Proposal::create(['term_id' => $term->id, 'attribute' => 'label', 'language' => 'fr', 'value' => 'Synthetic candidate',
            'value_hash' => hash('sha256', 'Synthetic candidate'), 'status' => 'pending', 'author_id' => $this->actor->id,
            'organization_id' => $this->origin->id, 'import_id' => $import?->id, 'anomalies' => [], 'edit_history' => []])->fresh();
    }

    private function import(Organisation $organization): ImportBatch
    {
        $version = MyabiVersion::firstOrCreate(['number' => 'synthetic-2026'], ['released_at' => '2026-10-01', 'status' => 'current', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);

        return ImportBatch::create(['type' => 'server', 'version_id' => $version->id, 'format_version' => '1', 'organization_id' => $organization->id,
            'source_kind' => 'organization', 'filename' => 'synthetic.csv', 'path' => 'private/synthetic.csv', 'sha256' => str_repeat('c', 64),
            'size' => 1, 'status' => 'applied', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
    }
}
