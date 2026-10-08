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
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImportService;
use App\Services\InitialValidationService;
use App\Services\OperationLock;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tests\TestCase;

class InitialValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStorage;

    private User $manager;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = storage_path('framework/testing/initial-'.bin2hex(random_bytes(8)));
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
            fwrite($handle, Encoding::encode('"'.implode('";"', array_map(fn ($v) => str_replace('"', '""', $v), $row)).'"')."\r\n");
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

    private function step(BulkOperation $operation, int $batchSize = 300): bool
    {
        return app(OperationLock::class)->run(fn () => app(InitialValidationService::class)->process($operation, $batchSize), true);
    }

    public function test_more_than_500_proposals_are_processed_across_durable_idempotent_checkpoints(): void
    {
        $import = $this->import(501);
        $service = app(InitialValidationService::class);
        $preview = $service->preview($this->manager, $import, 'fr');
        $this->assertSame(501, $preview['counts']['eligible']);
        $operation = $service->enqueue($this->manager, $import, $preview);
        $this->assertFalse($this->step($operation));
        $this->assertSame(300, $operation->fresh()->counts['validated']);
        $this->assertSame('processing', $operation->fresh()->status);
        $this->assertTrue($this->step($operation));
        $this->assertSame(501, $operation->fresh()->counts['validated']);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame(1002, Revision::count());
        $this->assertTrue($this->step($operation));
        $this->assertSame(1002, Revision::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'validation.initial_completed']);
    }

    public function test_preview_excludes_invalid_divergent_self_obsolete_and_external_competing_proposals(): void
    {
        $import = $this->import(6);
        $proposals = Proposal::orderBy('id')->get();
        $proposals[0]->update(['value' => 'Missing variable']);
        $proposals[1]->update(['divergence' => true]);
        $proposals[2]->update(['author_id' => $this->manager->id]);
        $proposals[3]->term->update(['obsolete' => true]);
        // The competing candidate is not attached to this import.
        app(WorkflowService::class)->propose($this->manager, $proposals[4]->term, 'label', 'fr', 'Autre {name}');
        $service = app(InitialValidationService::class);
        $preview = $service->preview($this->manager, $import, 'fr');
        $this->assertSame(['total' => 6, 'eligible' => 1, 'excluded' => 5, 'reasons' => ['invalid' => 1, 'divergence' => 1, 'competing' => 1, 'self' => 1, 'obsolete' => 1]], $preview['counts']);
        $operation = $service->enqueue($this->manager, $import, $preview);
        for ($i = 0; $i < 6; $i++) {
            $this->step($operation, 1);
        }
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame(1, Proposal::where('status', 'validated')->count());
        $this->assertSame('pending', $proposals[4]->fresh()->status);
        $this->assertSame(5, $operation->fresh()->counts['excluded']);
    }

    public function test_failed_chunk_rolls_back_then_resumes_without_revalidating_completed_rows(): void
    {
        $import = $this->import(3);
        $service = app(InitialValidationService::class);
        $operation = $service->enqueue($this->manager, $import, $service->preview($this->manager, $import, 'fr'));
        $this->step($operation, 1);
        $workflow = Mockery::mock(WorkflowService::class);
        $workflow->shouldReceive('bulkUnderOperationLock')->once()->andThrow(new \RuntimeException('Simulated worker interruption'));
        $interrupted = new InitialValidationService($workflow, app(OperationLock::class), app(AuditService::class));
        app(OperationLock::class)->run(fn () => $interrupted->process($operation, 1), true);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame(1, $operation->fresh()->counts['validated']);
        $this->assertSame(1, Proposal::where('status', 'validated')->count());
        $service->retry($this->manager, $operation->fresh());
        $this->assertTrue($this->step($operation));
        $this->assertSame(3, $operation->fresh()->counts['validated']);
        $this->assertSame(6, Revision::count());
    }

    public function test_initial_validation_failure_logs_only_metadata_and_a_correlation_reference(): void
    {
        $import = $this->import(1);
        $service = app(InitialValidationService::class);
        $operation = $service->enqueue($this->manager, $import, $service->preview($this->manager, $import, 'fr'));
        $marker = 'SYNTHETIC_PRIVATE_VALUE_72';
        $workflow = Mockery::mock(WorkflowService::class);
        $workflow->shouldReceive('bulkUnderOperationLock')->once()->andThrow(new \RuntimeException($marker));
        $logPath = storage_path('logs/redaction-test.log');
        Log::swap(new \Illuminate\Log\Logger(new Logger('redaction', [new StreamHandler($logPath)])));
        $interrupted = new InitialValidationService($workflow, app(OperationLock::class), app(AuditService::class));

        $this->assertFalse(app(OperationLock::class)->run(fn () => $interrupted->process($operation), true));
        $stored = $operation->fresh()->error;
        $metadata = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ui.initial_failed', $metadata['myabi_error']);
        $this->assertStringNotContainsString($marker, $stored);
        $this->assertStringNotContainsString($marker, file_get_contents($logPath));
        $this->assertStringContainsString($metadata['reference'], file_get_contents($logPath));
        $this->assertSame(0, Proposal::where('status', 'validated')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'validation.initial_failed']);
    }

    public function test_stale_preview_or_catalogue_change_stops_unreviewed_validation(): void
    {
        $import = $this->import(2);
        $service = app(InitialValidationService::class);
        $preview = $service->preview($this->manager, $import, 'fr');
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
        try {
            $service->enqueue($this->manager, $import, $preview);
            $this->fail('Stale preview accepted');
        } catch (ValidationException) {
        }
        $operation = $service->enqueue($this->manager, $import, $service->preview($this->manager, $import, 'fr'));
        $this->step($operation, 1);
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
        $this->assertFalse($this->step($operation));
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame(1, $operation->fresh()->counts['validated']);
        $this->assertSame(1, Proposal::where('status', 'pending')->count());
    }

    public function test_http_confirmation_is_bound_to_the_preview_and_cannot_be_replayed(): void
    {
        $this->withoutExceptionHandling();
        $import = $this->import(2);
        $this->actingAs($this->manager)->withExceptionHandling()->get('/imports/'.$import->id.'/validation-preview?language=fr')->assertStatus(405);
        $this->assertDatabaseCount('bulk_operations', 0);
        $this->withoutExceptionHandling()->post('/imports/'.$import->id.'/validation-preview', ['language' => 'fr'])->assertRedirect('/imports/'.$import->id);
        $analysis = BulkOperation::where('type', 'initial_preview')->firstOrFail();
        $this->assertFalse($this->step($analysis, 1));
        $this->assertSame(1, $analysis->fresh()->counts['examined']);
        $this->assertSame(0, Proposal::where('status', 'validated')->count());
        $this->assertTrue($this->step($analysis, 1));
        $this->get('/imports/'.$import->id.'/validation-preview?language=fr&operation_id='.$analysis->id)->assertOk()->assertSee('name="preview_token"', false);
        $previews = session('initial_validation_previews');
        $token = array_key_first($previews);
        $this->post('/imports/'.$import->id.'/validate', ['preview_token' => $token, 'confirmed' => 1])->assertRedirect('/imports/'.$import->id);
        $this->assertDatabaseCount('bulk_operations', 2);
        $this->get('/imports/'.$import->id)->assertOk()->assertSee('Opérations de validation initiale');
        $this->withExceptionHandling()->post('/imports/'.$import->id.'/validate', ['preview_token' => $token, 'confirmed' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('bulk_operations', 2);
    }

    public function test_reimport_preview_includes_shared_proposals_and_warnings_require_review(): void
    {
        $first = $this->import(2);
        $second = $this->import(2);
        $proposal = Proposal::orderBy('id')->firstOrFail();
        $proposal->update(['value' => $proposal->term->attributesForDisplay()['label']['reference']]);
        $preview = app(InitialValidationService::class)->preview($this->manager, $second, 'fr');
        $this->assertSame(2, $preview['counts']['total']);
        $this->assertSame(1, $preview['counts']['eligible']);
        $this->assertSame(1, $preview['counts']['reasons']['invalid']);
        $this->assertSame($first->id, $proposal->import_id);
    }

    public function test_heavy_operation_prevents_queueing_a_preview_until_the_lock_is_released(): void
    {
        $import = $this->import(1);
        $service = app(InitialValidationService::class);
        app(OperationLock::class)->run(function () use ($import, $service) {
            try {
                $service->enqueuePreview($this->manager, $import, 'fr');
                $this->fail('A preview was queued during an exclusive operation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('operation', $exception->errors());
            }
            $this->assertDatabaseCount('bulk_operations', 0);
        }, true);
        $operation = $service->enqueuePreview($this->manager, $import, 'fr');
        $this->assertSame('queued', $operation->status);
        $this->assertDatabaseCount('bulk_operations', 1);
    }
}
