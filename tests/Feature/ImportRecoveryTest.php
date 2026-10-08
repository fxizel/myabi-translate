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
use App\Services\OperationLock;
use App\Services\PublicationService;
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

    public function test_confirmed_common_scope_survives_creation_of_a_matching_canton_organization(): void
    {
        $import = $this->receive(1, 'form');
        $this->analyzeAndApply($import);
        $term = Term::firstOrFail();
        self::assertTrue($term->scope_unconfirmed);
        $term->update(['scope_unconfirmed' => false, 'scope_overridden' => true, 'validated' => ['label' => ['fr' => 'Valeur validée {}']]]);
        $hash = $term->raw_identity_hash;
        Organisation::create(['name' => 'Aargau', 'code' => 'AG', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'form'));

        self::assertSame(1, Term::count());
        self::assertNull($term->fresh()->organization_id);
        self::assertSame($hash, $term->fresh()->raw_identity_hash);
        self::assertSame('Valeur validée {}', $term->fresh()->validated['label']['fr']);
        self::assertSame([$hash], DB::table('import_rows')->distinct()->pluck('raw_identity_hash')->all());
    }

    public function test_confirmed_common_scope_survives_first_import_from_another_source(): void
    {
        Organisation::create(['name' => 'Aargau', 'code' => 'AG', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'form'));
        $term = Term::firstOrFail();
        self::assertNotNull($term->organization_id);
        $this->setScope($term, null);
        $this->analyzeAndApply($this->receive(1, 'form', [], 'organization', $this->manager->organization_id));

        self::assertSame(1, Term::count());
        self::assertNull($term->fresh()->organization_id);
        self::assertSame(1, DB::table('import_rows')->distinct()->count('term_id'));
    }

    public function test_private_variants_are_not_merged_across_sources_and_each_source_keeps_its_choice(): void
    {
        $this->analyzeAndApply($this->receive(1));
        $vaud = Term::firstOrFail();
        $this->setScope($vaud, $this->manager->organization_id);
        $fribourg = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'mric', [], 'organization', $fribourg->id));
        $other = Term::whereKeyNot($vaud->id)->firstOrFail();
        $this->setScope($other, $fribourg->id);

        $vaudImport = $this->receive(1, 'mric', [], 'organization', $this->manager->organization_id);
        $this->analyzeAndApply($vaudImport);
        $fribourgImport = $this->receive(1, 'mric', [], 'organization', $fribourg->id);
        $this->analyzeAndApply($fribourgImport);
        $publisherImport = $this->receive(1);
        $this->analyzeAndApply($publisherImport);
        self::assertSame(2, Term::count());
        self::assertSame($vaud->id, DB::table('import_rows')->where('import_id', $vaudImport->id)->value('term_id'));
        self::assertSame($other->id, DB::table('import_rows')->where('import_id', $fribourgImport->id)->value('term_id'));
        self::assertSame($vaud->id, DB::table('import_rows')->where('import_id', $publisherImport->id)->value('term_id'));
        self::assertSame($this->manager->organization_id, $vaud->fresh()->organization_id);
        self::assertSame($fribourg->id, $other->fresh()->organization_id);
    }

    public function test_raw_identity_backfill_preserves_exact_keys_for_applied_and_staged_rows(): void
    {
        $applied = $this->receive(2);
        $this->analyzeAndApply($applied);
        $staged = $this->receive(3);
        app(ImportService::class)->analyze($staged);
        $expectedTerms = Term::pluck('raw_identity_hash', 'id')->all();
        $expectedRows = DB::table('import_rows')->pluck('raw_identity_hash', 'id')->all();
        $sourceHash = hash_file('sha256', $applied->originalPath());
        DB::table('terms')->update(['raw_identity_hash' => null]);
        DB::table('import_rows')->update(['raw_identity_hash' => null]);
        app(AuditService::class)->record('term.scope', Term::first(), [], [], $this->manager);

        $migration = require database_path('migrations/2026_09_21_120000_add_stable_raw_identity.php');
        $migration->up();
        self::assertSame($expectedTerms, Term::pluck('raw_identity_hash', 'id')->all());
        self::assertSame($expectedRows, DB::table('import_rows')->pluck('raw_identity_hash', 'id')->all());
        self::assertTrue(Term::first()->scope_overridden);
        self::assertSame($sourceHash, hash_file('sha256', $applied->originalPath()));
    }

    public function test_provenance_uses_latest_applied_generation_for_each_source_not_upload_order(): void
    {
        $this->analyzeAndApply($this->receive(1));
        $first = Term::firstOrFail();
        $this->setScope($first, $this->manager->organization_id);
        $latest = $this->receive(1);
        $uploadedLater = $this->receive(1);
        $this->analyzeAndApply($uploadedLater);
        $this->analyzeAndApply($latest);
        self::assertLessThan($uploadedLater->id, $latest->id);
        self::assertGreaterThan($uploadedLater->fresh()->catalogue_generation, $latest->fresh()->catalogue_generation);

        // Historical sources can retain different private variants of one exact key.
        $fribourg = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $chosen = $first->replicate();
        $chosen->organization_id = $fribourg->id;
        $chosen->identity_hash = hash('sha256', json_encode([$chosen->type, $fribourg->id, $chosen->key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $chosen->save();
        DB::table('import_rows')->where('import_id', $latest->id)->update(['term_id' => $chosen->id]);

        // A newer application from another source must not replace that provenance.
        $this->analyzeAndApply($this->receive(1, 'mric', [], 'organization', $this->manager->organization_id));
        $reimport = $this->receive(1);
        $this->analyzeAndApply($reimport);
        self::assertSame($chosen->id, DB::table('import_rows')->where('import_id', $reimport->id)->value('term_id'));
        self::assertSame(2, Term::count());
        self::assertSame($fribourg->id, $chosen->fresh()->organization_id);
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

    public function test_status_prefixes_require_confirmation_and_reimports_keep_explicit_scope(): void
    {
        Organisation::create(['name' => 'Homonymous organization', 'code' => 'PHV', 'active' => true]);
        $this->analyzeAndApply($this->receive(1, 'form', ['Form Template Name' => 'PHV_Form']));
        $term = Term::where('type', 'form')->firstOrFail();
        self::assertNull($term->organization_id);
        self::assertTrue($term->scope_unconfirmed);
        $publication = app(PublicationService::class)->enqueue($this->manager, ['version_id' => $this->version->id,
            'types' => ['form'], 'recipients' => [$this->manager->organization_id]]);
        try {
            app(OperationLock::class)->run(fn () => app(PublicationService::class)->build($publication), true);
            self::fail('An unresolved status prefix must block publication.');
        } catch (ValidationException $error) {
            self::assertContains(__('ui.publication_scope_unconfirmed'), array_merge(...array_values($error->errors())));
        }
        $this->setScope($term, null);
        $this->analyzeAndApply($this->receive(1, 'form', ['Form Template Name' => 'PHV_Form'], 'organization', $this->manager->organization_id));
        self::assertSame(1, Term::where('type', 'form')->count());
        self::assertFalse($term->fresh()->scope_unconfirmed);
        self::assertTrue($term->fresh()->scope_overridden);
        self::assertNull($term->fresh()->organization_id);

        $this->analyzeAndApply($this->receive(1, 'workflow', ['Workflow' => 'Old-Workflow']));
        $workflow = Term::where('type', 'workflow')->firstOrFail();
        self::assertTrue($workflow->scope_unconfirmed);
        self::assertNull($workflow->organization_id);
    }

    public function test_unresolved_prefix_backfill_preserves_explicit_choices_identities_and_revisions(): void
    {
        $this->analyzeAndApply($this->receive(3, 'form', ['Form Template Name' => 'wip Form']));
        $terms = Term::orderBy('id')->get();
        $this->setScope($terms[1], null);
        $this->setScope($terms[2], $this->manager->organization_id);
        $this->analyzeAndApply($this->receive(1, 'workflow', ['Workflow' => 'OLD_Workflow']));
        // Simulate records loaded by the former inference rule.
        DB::table('terms')->update(['scope_unconfirmed' => false]);
        $before = Term::orderBy('id')->get()->keyBy('id');
        $generation = (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
        $migration = require database_path('migrations/2026_09_21_220000_require_unresolved_prefix_scope_confirmation.php');
        $migration->up();
        self::assertSame(2, Term::where('scope_unconfirmed', true)->count());
        foreach (Term::orderBy('id')->get() as $term) {
            $original = $before[$term->id];
            self::assertSame($original->key, $term->key);
            self::assertSame($original->identity_hash, $term->identity_hash);
            self::assertSame($original->raw_identity_hash, $term->raw_identity_hash);
            self::assertSame($original->organization_id, $term->organization_id);
            self::assertSame($original->revision_no, $term->revision_no);
            self::assertSame($original->validated, $term->validated);
            self::assertSame($original->lock_version + ($term->scope_overridden ? 0 : 1), $term->lock_version);
            self::assertSame(! $term->scope_overridden, $term->scope_unconfirmed);
        }
        self::assertSame($generation + 1, (int) DB::table('catalogue_state')->where('id', 1)->value('generation'));
        $migration->up();
        self::assertSame($generation + 1, (int) DB::table('catalogue_state')->where('id', 1)->value('generation'));
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

    private function setScope(Term $term, ?int $owner): void
    {
        $term->update(['organization_id' => $owner, 'scope_overridden' => true, 'scope_unconfirmed' => false,
            'identity_hash' => hash('sha256', json_encode([$term->type, $owner, $term->key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
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
