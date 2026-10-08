<?php

namespace Tests\Feature;

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\BulkOperation;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FilteredValidationService;
use App\Services\ImportService;
use App\Services\OperationLock;
use App\Services\ProposalFilter;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class FilteredValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStorage;

    private User $manager;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = storage_path('framework/testing/filtered-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($this->isolatedStorage);
        foreach (['app/private', 'framework/views', 'logs'] as $directory) {
            File::makeDirectory($this->isolatedStorage.'/'.$directory, 0700, true);
        }
        config(['view.compiled' => $this->isolatedStorage.'/framework/views', 'referentiel.storage_quota' => 1024 * 1024 * 1024]);
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Police VD', 'active' => true]);
        $this->manager = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['manager' => ['de', 'fr', 'it', 'en']], 'locale' => 'fr']);
        User::factory()->create(['name' => 'Import', 'email' => 'import@referentiel.invalid', 'is_technical' => true, 'active' => false]);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $this->actingAs($this->manager);
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage)) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    private function import(int $rows): ImportBatch
    {
        $format = FormatRegistry::get('server');
        $file = $this->isolatedStorage.'/app/private/source.csv';
        $handle = fopen($file, 'wb');
        $write = function (array $row) use ($handle) {
            fwrite($handle, Encoding::encode('"'.implode('";"', array_map(fn ($value) => str_replace('"', '""', $value), $row)).'"')."\r\n");
        };
        $write($format->headers);
        for ($i = 1; $i <= $rows; $i++) {
            $write(array_map(fn ($column) => ['Name' => 'Key.'.$i, 'de_CH' => 'Wert {name} '.$i, 'fr_CH' => 'Valeur {name} '.$i][$column] ?? '', $format->headers));
        }
        fclose($handle);
        $service = app(ImportService::class);
        $import = $service->receive($file, 'source.csv', ['type' => 'server', 'version_id' => $this->version->id, 'source_kind' => 'publisher', 'organization_id' => null], $this->manager);
        $service->analyze($import);
        $service->apply($import->fresh());

        return $import->fresh();
    }

    private function listing(array $filters = []): TestResponse
    {
        return $this->get('/validation?'.http_build_query(['language' => 'fr', ...$filters]))->assertOk();
    }

    private function token(array $filters = []): string
    {
        $token = $this->listing($filters)->viewData('filteredSnapshot');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }

    private function enqueue(string $token, array $extra = []): BulkOperation
    {
        $this->post('/proposals/validate-filtered', ['snapshot_token' => $token, 'confirmed' => 1, ...$extra])->assertRedirect()->assertSessionHasNoErrors();

        return BulkOperation::where('type', 'filtered_validation')->latest('id')->firstOrFail();
    }

    private function step(BulkOperation $operation, int $batchSize = 300): bool
    {
        return app(OperationLock::class)->run(fn () => app(FilteredValidationService::class)->process($operation, $batchSize), true);
    }

    public function test_all_501_matching_proposals_are_queued_from_a_25_row_page_and_resumed_without_duplicate_decisions(): void
    {
        $import = $this->import(502);
        Term::query()->update(['context' => 'selected']);
        $outside = Proposal::latest('id')->firstOrFail();
        $outside->term->update(['context' => 'outside']);
        $italian = app(WorkflowService::class)->propose($this->manager, Proposal::firstOrFail()->term, 'label', 'it', 'Valore {name}');
        $response = $this->listing(['context' => 'selected', 'import_id' => $import->id, 'per_page' => 25]);
        $this->assertSame(501, $response->viewData('proposalTotal'));
        $this->assertCount(25, $response->viewData('proposals'));
        $response->assertSee('name="snapshot_token"', false)->assertSee('/proposals/validate-filtered', false);
        $operation = $this->enqueue($response->viewData('filteredSnapshot'));
        $this->assertSame('queued', $operation->status);
        $this->assertSame(0, Proposal::where('status', 'validated')->count());
        $this->assertFalse($this->step($operation));
        $this->assertSame('processing', $operation->fresh()->status);
        $this->assertSame(300, $operation->fresh()->counts['validated']);
        $this->assertTrue($this->step($operation));
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame(501, $operation->fresh()->counts['validated']);
        $this->assertSame(501, Proposal::where('status', 'validated')->count());
        $this->assertSame('pending', $outside->fresh()->status);
        $this->assertSame('pending', $italian->fresh()->status);
        $this->assertSame(1003, Revision::count());
        $this->assertSame(501, DB::table('audit_events')->where('action', 'proposal.validate')->count());
        $this->assertTrue($this->step($operation));
        $this->assertSame(1003, Revision::count());
        $this->assertSame(501, DB::table('audit_events')->where('action', 'proposal.validate')->count());
    }

    public function test_filter_snapshot_uses_queue_filters_and_ignores_changes_submitted_with_confirmation(): void
    {
        $import = $this->import(5);
        $proposals = Proposal::orderBy('id')->get();
        foreach ($proposals as $proposal) {
            $proposal->term->update(['context' => 'target', 'organization_id' => $this->manager->organization_id]);
            $proposal->update(['created_at' => now()->subDays(31)]);
        }
        $proposals[1]->term->update(['context' => 'other']);
        $proposals[2]->term->update(['organization_id' => null]);
        $proposals[3]->update(['created_at' => now()->subDays(5)]);
        $proposals[4]->update(['status' => 'rejected']);
        $filters = ['type' => 'server', 'import_id' => $import->id, 'version_id' => $this->version->id, 'context' => 'target', 'scope' => 'own', 'organization_id' => $this->manager->organization_id, 'q' => 'Key.', 'overdue' => 1];
        $response = $this->listing($filters);
        $this->assertSame(1, $response->viewData('proposalTotal'));
        $this->assertSame([$proposals[0]->id], $response->viewData('proposals')->pluck('id')->all());
        $operation = $this->enqueue($response->viewData('filteredSnapshot'), ['language' => 'it', 'context' => 'other', 'scope' => 'common']);
        $this->assertTrue($this->step($operation));
        $this->assertSame('validated', $proposals[0]->fresh()->status);
        foreach ([1, 2, 3] as $index) {
            $this->assertSame('pending', $proposals[$index]->fresh()->status);
        }
        $this->assertSame('rejected', $proposals[4]->fresh()->status);
        $this->assertSame(1, $operation->fresh()->counts['validated']);
    }

    public function test_excluded_proposals_are_counted_without_preventing_other_matching_decisions(): void
    {
        $import = $this->import(4);
        $proposals = Proposal::orderBy('id')->get();
        $proposals[0]->update(['value' => 'Missing variable']);
        $proposals[1]->update(['author_id' => $this->manager->id]);
        $competitor = $proposals[2]->replicate();
        $competitor->fill(['import_id' => null, 'value' => 'Alternative {name}', 'value_hash' => hash('sha256', 'Alternative {name}')])->save();
        $operation = $this->enqueue($this->token(['import_id' => $import->id]));
        $this->assertTrue($this->step($operation));
        $counts = $operation->fresh()->counts;
        $this->assertSame(1, $counts['validated']);
        $this->assertSame(3, $counts['excluded']);
        foreach (['invalid', 'self', 'competing'] as $reason) {
            $this->assertSame(1, $counts['reasons'][$reason]);
        }
        $this->assertSame('validated', $proposals[3]->fresh()->status);
        foreach ([$proposals[0], $proposals[1], $proposals[2], $competitor] as $excluded) {
            $this->assertSame('pending', $excluded->fresh()->status);
        }
    }

    public function test_confirmation_is_required_and_forged_or_other_users_snapshots_are_rejected(): void
    {
        $this->import(1);
        $token = $this->token();
        $this->post('/proposals/validate-filtered', ['snapshot_token' => $token])->assertSessionHasErrors('confirmed');
        $this->post('/proposals/validate-filtered', ['snapshot_token' => 'forged-token', 'confirmed' => 1])->assertSessionHasErrors();
        $other = User::factory()->create(['organization_id' => $this->manager->organization_id, 'roles' => ['manager' => ['fr']]]);
        $this->actingAs($other)->post('/proposals/validate-filtered', ['snapshot_token' => $token, 'confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('bulk_operations', 0);
        $this->assertSame(0, Proposal::where('status', 'validated')->count());
    }

    public function test_expired_and_stale_catalogue_snapshots_are_rejected(): void
    {
        $this->import(1);
        $token = $this->token();
        $this->travel(31)->minutes();
        $this->post('/proposals/validate-filtered', ['snapshot_token' => $token, 'confirmed' => 1])->assertSessionHasErrors();
        $this->travelBack();
        $token = $this->token();
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
        $this->post('/proposals/validate-filtered', ['snapshot_token' => $token, 'confirmed' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('bulk_operations', 0);
    }

    public function test_overdue_cutoff_is_frozen_when_the_filter_is_confirmed(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->import(2);
        $proposals = Proposal::orderBy('id')->get();
        $proposals[0]->update(['created_at' => now()->subDays(31)]);
        $proposals[1]->update(['created_at' => now()->subDays(30)->addMinutes(1)]);
        $token = $this->token(['overdue' => 1]);
        $this->travel(2)->minutes();
        $operation = $this->enqueue($token);
        $this->assertTrue($this->step($operation));
        $this->assertSame('validated', $proposals[0]->fresh()->status);
        $this->assertSame('pending', $proposals[1]->fresh()->status);
        $this->assertSame(1, $operation->fresh()->counts['validated']);
        $this->travelBack();
    }

    public function test_validator_cannot_override_four_eyes_but_a_manager_can_explicitly_do_so(): void
    {
        $this->import(1);
        $validator = User::factory()->create(['organization_id' => $this->manager->organization_id, 'roles' => ['validator' => ['fr']], 'locale' => 'fr']);
        $proposal = Proposal::firstOrFail();
        $proposal->update(['author_id' => $validator->id]);
        $this->actingAs($validator);
        $token = $this->token();
        $this->post('/proposals/validate-filtered', ['snapshot_token' => $token, 'confirmed' => 1, 'override' => 1])->assertForbidden();
        $operation = $this->enqueue($token);
        $this->assertTrue($this->step($operation));
        $this->assertSame(0, $operation->fresh()->counts['validated']);
        $this->assertSame(1, $operation->fresh()->counts['reasons']['self']);
        $this->assertSame('pending', $proposal->fresh()->status);

        $proposal->update(['author_id' => $this->manager->id]);
        $this->actingAs($this->manager);
        $operation = $this->enqueue($this->token(), ['override' => 1]);
        $this->assertTrue($this->step($operation));
        $this->assertSame('validated', $proposal->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'validation.four_eyes_override']);
    }

    public function test_worker_checks_current_permissions_and_dispatches_filtered_operations(): void
    {
        $this->import(2);
        $validator = User::factory()->create(['organization_id' => $this->manager->organization_id, 'roles' => ['validator' => ['fr']], 'locale' => 'fr']);
        $this->actingAs($validator);
        $operation = $this->enqueue($this->token());
        $validator->update(['roles' => ['reader' => ['fr']]]);
        $this->assertFalse($this->step($operation));
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame(0, Proposal::where('status', 'validated')->count());

        $this->actingAs($this->manager);
        $operation = $this->enqueue($this->token());
        $this->artisan('referentiel:work', ['--once' => true])->assertSuccessful();
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame(2, $operation->fresh()->counts['validated']);
    }

    public function test_catalogue_changes_stop_a_partially_completed_operation(): void
    {
        $this->import(3);
        $operation = $this->enqueue($this->token());
        $this->assertFalse($this->step($operation, 1));
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
        $this->assertFalse($this->step($operation, 1));
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame(1, $operation->fresh()->counts['validated']);
        $this->assertSame(2, Proposal::where('status', 'pending')->count());
        $this->assertSame(4, Revision::count());
    }

    public function test_failed_checkpoint_can_be_retried_without_revalidating_committed_rows(): void
    {
        $this->import(3);
        $operation = $this->enqueue($this->token());
        $this->assertFalse($this->step($operation, 1));
        $workflow = Mockery::mock(WorkflowService::class);
        $workflow->shouldReceive('bulkUnderOperationLock')->once()->andThrow(new \RuntimeException('Simulated worker interruption'));
        $interrupted = new FilteredValidationService(app(ProposalFilter::class), $workflow, app(OperationLock::class), app(AuditService::class));
        $this->assertFalse(app(OperationLock::class)->run(fn () => $interrupted->process($operation, 1), true));
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame(1, $operation->fresh()->counts['validated']);
        $this->assertSame(1, Proposal::where('status', 'validated')->count());
        app(FilteredValidationService::class)->retry($this->manager, $operation->fresh());
        $this->assertTrue($this->step($operation));
        $this->assertSame(3, $operation->fresh()->counts['validated']);
        $this->assertSame(6, Revision::count());
        $this->assertSame(3, DB::table('audit_events')->where('action', 'proposal.validate')->count());
    }

    public function test_replaying_a_completed_confirmation_is_idempotent_and_cannot_change_its_override(): void
    {
        $this->import(1);
        $token = $this->token();
        $operation = $this->enqueue($token);
        $this->assertTrue($this->step($operation));
        $repeated = $this->enqueue($token);
        $this->assertSame($operation->id, $repeated->id);
        $this->assertSame('completed', $repeated->status);
        $this->assertTrue($this->step($repeated));

        $this->post('/proposals/validate-filtered', ['snapshot_token' => $token, 'confirmed' => 1, 'override' => 1])->assertSessionHasErrors();
        $this->assertFalse($operation->fresh()->override);
        $this->assertDatabaseCount('bulk_operations', 1);
        $this->assertSame(2, Revision::count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'proposal.validate')->count());
    }
}
