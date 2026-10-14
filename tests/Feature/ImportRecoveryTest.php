<?php

namespace Tests\Feature;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Controllers\ImportController;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImportService;
use App\Services\StorageBudget;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ImportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $privateStorage;

    private User $manager;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'referentiel-import-recovery-test-'.bin2hex(random_bytes(8));
        mkdir($this->privateStorage.'/app/private', 0700, true);
        $this->app->useStoragePath($this->privateStorage);
        config(['referentiel.storage_quota' => 2_000_000_000, 'referentiel.external_storage_used' => 0, 'referentiel.batch_size' => 300]);
        $organization = Organisation::create(['name' => 'Test organization', 'code' => 'VD', 'active' => true]);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'Long-test-password-123',
            'roles' => ['manager'], 'languages' => ['fr'], 'organization_id' => $organization->id, 'active' => true]);
        User::create(['name' => 'Technical import', 'email' => 'technical@example.test', 'password' => 'Long-technical-password-123',
            'roles' => [], 'languages' => [], 'organization_id' => null, 'active' => false, 'is_technical' => true]);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateStorage) && str_starts_with(basename($this->privateStorage), 'referentiel-import-recovery-test-')) {
            File::deleteDirectory($this->privateStorage);
        }
        parent::tearDown();
    }

    public function test_analysis_resumes_from_the_last_committed_300_record_checkpoint(): void
    {
        $import = $this->receive(620);
        try {
            $this->interruptingService('analyzing')->analyze($import);
            self::fail('The injected interruption must fire.');
        } catch (RuntimeException $error) {
            self::assertSame('Injected processing interruption', $error->getMessage());
        }
        $import->refresh();
        self::assertSame(300, $import->row_count);
        self::assertSame(300, DB::table('import_rows')->where('import_id', $import->id)->count());
        self::assertSame(0, Term::count());
        $next = (new CsvReader)->readAt($import->originalPath(), $import->analysis_offset, 301);
        self::assertSame('key-0301', $next->values[0]);

        app(ImportService::class)->analyze($import);
        $import->refresh();
        self::assertSame('analyzed', $import->status);
        self::assertSame(620, $import->row_count);
        self::assertSame(620, $import->report['new']);
        self::assertSame(620, DB::table('import_rows')->where('import_id', $import->id)->count());
        self::assertSame(620, DB::table('import_rows')->where('import_id', $import->id)->distinct()->count('record_number'));
        self::assertSame(filesize($import->originalPath()), $import->analysis_offset);
        self::assertSame(0, Term::count(), 'Analysis alone cannot change the visible catalogue.');
    }

    public function test_shared_group_import_keeps_original_and_progress_accessible_to_both_runtime_users(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix import permissions require a POSIX filesystem.');
        }
        config(['referentiel.private_shared_group' => true]);
        $previousUmask = umask(0077);
        try {
            $import = $this->receive(1);
            app(ImportService::class)->analyze($import);
            self::assertSame('analyzed', $import->fresh()->status);
            foreach ([$import->originalPath(), storage_path('app/private/progress/'.$import->id.'.json')] as $file) {
                clearstatcache(true, $file);
                self::assertSame(0660, fileperms($file) & 0777);
                self::assertSame(0770, fileperms(dirname($file)) & 0777);
            }
            self::assertSame($import->sha256, hash_file('sha256', $import->originalPath()));
        } finally {
            umask($previousUmask);
        }
    }

    public function test_application_failure_after_a_chunk_rolls_back_every_business_write_and_can_restart(): void
    {
        $import = $this->receive(620);
        app(ImportService::class)->analyze($import);
        $generation = DB::table('catalogue_state')->where('id', 1)->value('generation');
        try {
            $this->interruptingService('applying')->apply($import->fresh());
            self::fail('The injected interruption must fire.');
        } catch (RuntimeException $error) {
            self::assertSame('Injected processing interruption', $error->getMessage());
        }
        self::assertSame(0, Term::count());
        self::assertSame(0, Proposal::count());
        self::assertSame(0, Revision::count());
        self::assertSame(0, DB::table('import_rows')->where('import_id', $import->id)->whereNotNull('term_id')->count());
        self::assertSame($generation, DB::table('catalogue_state')->where('id', 1)->value('generation'));
        $progress = json_decode(file_get_contents(storage_path('app/private/progress/'.$import->id.'.json')), true);
        self::assertTrue($progress['provisional']);
        self::assertSame(300, $progress['rows']);

        app(ImportService::class)->apply($import->fresh());
        self::assertSame('applied', $import->fresh()->status);
        self::assertSame(620, Term::count());
        self::assertSame(620, Revision::count());
        self::assertSame(620, Proposal::where('status', 'pending')->count());
        self::assertSame(620, DB::table('import_rows')->where('import_id', $import->id)->whereNotNull('term_id')->count());
        self::assertDatabaseCount('audit_events', 3);
        $progress = json_decode(file_get_contents(storage_path('app/private/progress/'.$import->id.'.json')), true);
        self::assertFalse($progress['provisional']);
        self::assertSame('applied', $progress['stage']);
    }

    public function test_stale_analysis_is_recomputed_before_any_application(): void
    {
        $import = $this->receive(5);
        app(ImportService::class)->analyze($import);
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
        app(ImportService::class)->apply($import->fresh());
        self::assertSame('queued', $import->fresh()->status);
        self::assertSame(0, Term::count());
        app(ImportService::class)->analyze($import->fresh());
        self::assertSame(5, DB::table('import_rows')->where('import_id', $import->id)->count());
        self::assertSame(5, $import->fresh()->report['new']);
        app(ImportService::class)->apply($import->fresh());
        self::assertSame(5, Term::count());
        self::assertSame(5, Proposal::count());
    }

    public function test_prefix_identity_survives_creation_of_a_matching_organization(): void
    {
        $this->analyzeAndApply($this->receive(1, 'form'));
        $term = Term::firstOrFail();
        $term->update(['validated' => ['label' => ['fr' => 'Valeur validée {}']]]);
        $hash = $term->raw_identity_hash;
        Organisation::create(['name' => 'Aargau', 'code' => 'AG', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'form'));
        self::assertSame(1, Term::count());
        self::assertSame($hash, $term->fresh()->raw_identity_hash);
        self::assertSame('Valeur validée {}', $term->fresh()->validated['label']['fr']);
        self::assertSame([$hash], DB::table('import_rows')->distinct()->pluck('raw_identity_hash')->all());
    }

    public function test_prefix_identity_reuses_term_from_another_source(): void
    {
        Organisation::create(['name' => 'Aargau', 'code' => 'AG', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'form'));
        $term = Term::firstOrFail();
        $this->analyzeAndApply($this->receive(1, 'form', [], 'organization', $this->manager->organization_id));
        self::assertSame(1, Term::count());
        self::assertSame($term->id, DB::table('import_rows')->latest('id')->value('term_id'));
        self::assertSame(1, DB::table('import_rows')->distinct()->count('term_id'));
        self::assertSame(1, Revision::count());
    }

    public function test_all_sources_reuse_one_common_identity(): void
    {
        $this->analyzeAndApply($this->receive(1));
        $term = Term::firstOrFail();
        $fribourg = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        foreach ([
            ['organization', $fribourg->id],
            ['organization', $this->manager->organization_id],
            ['publisher', null],
        ] as [$sourceKind, $organizationId]) {
            $import = $this->receive(1, 'mric', [], $sourceKind, $organizationId);
            $this->analyzeAndApply($import);
            self::assertSame($term->id, DB::table('import_rows')->where('import_id', $import->id)->value('term_id'));
        }
        self::assertSame(1, Term::count());
        self::assertSame(1, Proposal::count());
        self::assertSame(3, DB::table('proposal_imports')->count());
    }

    public function test_upload_and_application_order_cannot_select_a_translation_variant(): void
    {
        $this->analyzeAndApply($this->receive(1));
        $term = Term::firstOrFail();
        $latest = $this->receive(1);
        $uploadedLater = $this->receive(1);
        $this->analyzeAndApply($uploadedLater);
        $this->analyzeAndApply($latest);
        self::assertLessThan($uploadedLater->id, $latest->id);
        self::assertGreaterThan($uploadedLater->fresh()->catalogue_generation, $latest->fresh()->catalogue_generation);
        $this->analyzeAndApply($this->receive(1, 'mric', [], 'organization', $this->manager->organization_id));
        $reimport = $this->receive(1);
        $this->analyzeAndApply($reimport);
        self::assertSame($term->id, DB::table('import_rows')->where('import_id', $reimport->id)->value('term_id'));
        self::assertSame(1, Term::count());
        self::assertSame(1, $reimport->fresh()->report['unchanged']);
    }

    public function test_reference_change_invalidates_pending_decision_version_and_rechecks_anomalies(): void
    {
        $this->analyzeAndApply($this->receive(1));
        $proposal = Proposal::firstOrFail();
        self::assertSame(1, $proposal->lock_version);
        self::assertSame([], $proposal->anomalies);
        $this->analyzeAndApply($this->receive(1, 'mric', ['de_CH' => 'Neue Quelle {other}']));
        self::assertSame(2, $proposal->fresh()->lock_version);
        self::assertNotEmpty($proposal->fresh()->anomalies);
        try {
            app(WorkflowService::class)->decide($this->manager, $proposal, 'validate', 1);
            self::fail('An approval from a page with an old reference must be refused.');
        } catch (ValidationException) {
            self::assertSame('pending', $proposal->fresh()->status);
            self::assertSame([], Term::first()->validated);
        }
    }

    public function test_status_prefixes_are_technical_key_components_and_do_not_create_owners(): void
    {
        Organisation::create(['name' => 'Homonymous organization', 'code' => 'PHV', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'form', ['Form Template Name' => 'PHV_Form']));
        $term = Term::where('type', 'form')->firstOrFail();
        $this->analyzeAndApply($this->receive(1, 'form', ['Form Template Name' => 'PHV_Form'], 'organization', $this->manager->organization_id));
        self::assertSame(1, Term::where('type', 'form')->count());
        self::assertSame($term->id, DB::table('import_rows')->latest('id')->value('term_id'));
        self::assertSame('PHV_Form', $term->key[1]);
        self::assertArrayNotHasKey('organization_id', $term->getAttributes());
        self::assertArrayNotHasKey('scope_unconfirmed', $term->getAttributes());
        $this->analyzeAndApply($this->receive(1, 'workflow', ['Workflow' => 'Old-Workflow']));
        self::assertSame('Old-Workflow', Term::where('type', 'workflow')->firstOrFail()->key[0]);
    }

    public function test_final_progress_failure_cannot_downgrade_an_already_committed_import(): void
    {
        $import = $this->receive(300);
        app(ImportService::class)->analyze($import);
        $this->interruptingService('applied')->apply($import->fresh());
        self::assertSame('applied', $import->fresh()->status);
        self::assertSame(300, Term::count());
        self::assertSame(300, Revision::count());
        self::assertSame(1, DB::table('audit_events')->where('action', 'import.applied')->count());
        // A lost/stale progress file cannot override the committed DB state.
        file_put_contents(storage_path('app/private/progress/'.$import->id.'.json'), '{"stage":"applying","rows":0,"provisional":true}');
        $response = app(ImportController::class)->progress($import->fresh())->getData(true);
        self::assertSame('applied', $response['stage']);
        self::assertSame(300, $response['rows']);
        self::assertFalse($response['provisional']);
    }

    private function analyzeAndApply(ImportBatch $import): void
    {
        app(ImportService::class)->analyze($import);
        app(ImportService::class)->apply($import->fresh());
    }

    private function interruptingService(string $stage): ImportService
    {
        return new class(app(StorageBudget::class), app(AuditService::class), $stage) extends ImportService
        {
            public function __construct(StorageBudget $budget, AuditService $audit, private string $interruptStage)
            {
                parent::__construct($budget, $audit);
            }

            public function progress(ImportBatch $import, string $stage, int $rows): void
            {
                parent::progress($import, $stage, $rows);
                if ($stage === $this->interruptStage && $rows >= 300) {
                    throw new RuntimeException('Injected processing interruption');
                }
            }
        };
    }

    private function receive(int $count, string $type = 'mric', array $overrides = [], string $sourceKind = 'publisher', ?int $organizationId = null): ImportBatch
    {
        $format = FormatRegistry::get($type);
        $path = $this->privateStorage.'/source.csv';
        $stream = fopen($path, 'wb');
        $write = function (array $row) use ($stream): void {
            fwrite($stream, implode(';', array_map(fn ($value) => '"'.str_replace('"', '""', Encoding::encode($value)).'"', $row))."\r\n");
        };
        $write($format->headers);
        for ($index = 1; $index <= $count; $index++) {
            $row = array_fill_keys($format->headers, '');
            foreach ($format->identityColumns as $column) {
                $row[$column] = 'key-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            }
            if ($format->layout === 'pairs') {
                $row[$type === 'form' ? 'Form Template Name' : 'Workflow'] = $type === 'form' ? 'AG_Form' : 'AG_Workflow';
                $row['Default Language'] = 'de_CH';
                $row['Default Label'] = "Quelle {$index} {}";
                $row['Language 1'] = 'fr_CH';
                $row['Translation 1'] = "Texte {$index} {}";
            } else {
                $row['de_CH'] = "Quelle {$index} {}";
                $row['fr_CH'] = "Texte {$index} {}";
            }
            $row = array_replace($row, $overrides);
            $write(array_values($row));
        }
        fclose($stream);

        return app(ImportService::class)->receive($path, $format->filename, ['version_id' => $this->version->id, 'type' => $type,
            'organization_id' => $organizationId, 'source_kind' => $sourceKind], $this->manager);
    }
}
