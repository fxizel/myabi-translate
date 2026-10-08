<?php

namespace Tests\Feature;

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Controllers\ImportController;
use App\Http\Middleware\EnsureSecureSession;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DevconfExportService;
use App\Services\ImportService;
use App\Services\LocalizedMessage;
use App\Services\OperationLock;
use App\Services\ProposalFilter;
use App\Services\PublicationService;
use App\Services\SourceRecord;
use App\Services\WorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowImportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $translator;

    private User $validator;

    private MyabiVersion $version;

    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['referentiel.storage_quota' => 10 * 1024 * 1024 * 1024]);
        $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myabi-workflow-test-'.bin2hex(random_bytes(8));
        mkdir($this->isolatedStorage.'/app/private', 0700, true);
        mkdir($this->isolatedStorage.'/framework/views', 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
        config(['view.compiled' => $this->isolatedStorage.'/framework/views']);
        $org = Organisation::create(['name' => 'Police VD', 'code' => 'VD', 'active' => true]);
        foreach (['manager', 'translator', 'validator'] as $role) {
            $this->$role = User::factory()->create(['organization_id' => $org->id, 'roles' => [$role => ['fr']], 'active' => true]);
        }
        User::factory()->create(['name' => 'Import', 'email' => 'import@referentiel.invalid', 'is_technical' => true, 'active' => false]);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage) && dirname($this->isolatedStorage) === rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) && str_starts_with(basename($this->isolatedStorage), 'myabi-workflow-test-')) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    private function input(array $rows, string $type = 'server'): string
    {
        $format = FormatRegistry::get($type);
        $dir = storage_path('app/private/tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir.'/'.bin2hex(random_bytes(8)).'.csv';
        $h = fopen($file, 'wb');
        $write = function ($values) use ($h) {
            fwrite($h, Encoding::encode('"'.implode('";"', array_map(fn ($v) => str_replace('"', '""', $v), $values)).'"')."\r\n");
        };
        $write($format->headers);
        foreach ($rows as $row) {
            $write(array_map(fn ($col) => $row[$col] ?? '', $format->headers));
        } fclose($h);

        return $file;
    }

    private function import(array $rows): ImportBatch
    {
        $file = $this->input($rows);
        $service = app(ImportService::class);
        $i = $service->receive($file, '03-DEVCONF-NLS-SERVER.csv', ['type' => 'server', 'version_id' => $this->version->id, 'organization_id' => null, 'source_kind' => 'publisher'], $this->manager);
        $service->analyze($i);
        $i->refresh();
        $service->apply($i);

        return $i->refresh();
    }

    public function test_import_is_dry_until_accepted_and_creates_proposals_not_validations(): void
    {
        $s = app(ImportService::class);
        $file = $this->input([['Name' => 'Greeting', 'de_CH' => 'Hallo {name}', 'fr_CH' => 'Bonjour {name}', 'sa_IN' => '#12345']]);
        $i = $s->receive($file, '03-DEVCONF-NLS-SERVER.csv', ['type' => 'server', 'version_id' => $this->version->id, 'organization_id' => null, 'source_kind' => 'publisher'], $this->manager);
        $s->analyze($i);
        $this->assertDatabaseCount('terms', 0);
        $this->assertDatabaseCount('import_rows', 1);
        $s->apply($i->fresh());
        $term = Term::first();
        $this->assertSame([], $term->validated);
        $this->assertSame(1, $term->revision_no);
        $this->assertDatabaseHas('proposals', ['value' => 'Bonjour {name}', 'status' => 'pending']);
        $this->assertSame('#12345', app(SourceRecord::class)->row($term)['sa_IN']);
    }

    public function test_import_form_and_upload_use_one_mode_without_a_completeness_parameter(): void
    {
        $this->withoutMiddleware([EnsureSecureSession::class, RequireTwoFactor::class]);
        $this->actingAs($this->manager)->get(route('imports.index'))->assertOk()
            ->assertDontSee('name="is_complete"', false);
        $path = $this->input([['Name' => 'Greeting', 'de_CH' => 'Hallo', 'fr_CH' => 'Bonjour']]);
        $response = $this->post(route('imports.store'), [
            'version_id' => $this->version->id, 'type' => 'server', 'source_kind' => 'publisher',
            'file' => new UploadedFile($path, 'source.csv', 'text/csv', null, true),
        ])->assertSessionHasNoErrors();
        $import = ImportBatch::firstOrFail();
        $response->assertRedirect(route('imports.show', $import));
        $this->assertSame('queued', $import->status);
        $this->assertDatabaseCount('terms', 0);
        $this->get(route('imports.show', $import))->assertOk();
    }

    public function test_validator_processes_five_hundred_proposals_in_the_same_filtered_queue_session(): void
    {
        config(['referentiel.storage_quota' => 1024 * 1024 * 1024]);
        $rows = [];
        for ($number = 1; $number <= 500; $number++) {
            $rows[] = ['Name' => 'session.'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'de_CH' => 'Referenz '.$number, 'fr_CH' => 'Traduction '.$number];
        }
        $this->import($rows);
        $this->validator->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($this->validator);
        $queue = '/validation?language=fr&type=server&per_page=100';
        $sessionStarted = null;
        for ($page = 0; $page < 5; $page++) {
            $response = $this->get($queue)->assertOk();
            $proposals = $response->viewData('proposals');
            $this->assertCount(100, $proposals->items());
            $this->assertSame(500 - $page * 100, $proposals->total());
            $sessionStarted ??= session('security.started_at');
            $this->assertNotNull($sessionStarted);
            $this->assertSame($sessionStarted, session('security.started_at'));
            $this->from($queue)->post('/proposals/bulk', [
                'ids' => $proposals->pluck('id')->all(),
                'lock_versions' => $proposals->pluck('lock_version', 'id')->all(),
                'decision' => 'validate',
                'confirmed' => '1',
            ])->assertSessionHasNoErrors()->assertRedirect($queue);
            $this->travel(5)->minutes();
        }
        $this->get($queue)->assertOk()->assertViewHas('proposals', fn ($proposals) => $proposals->total() === 0);
        $this->assertSame($sessionStarted, session('security.started_at'));
        $this->assertAuthenticatedAs($this->validator);
        $this->assertSame(500, Proposal::where('status', 'validated')->where('decided_by', $this->validator->id)->count());
        $this->assertSame(500, Term::where('revision_no', 2)->count());
        $this->assertDatabaseCount('revisions', 1000);
        $this->assertSame(500, DB::table('audit_events')->where('action', 'proposal.validate')->count());
        $this->assertSame(5, DB::table('audit_events')->where('action', 'proposal.bulk')->count());
        foreach (Term::orderBy('label')->get(['label', 'validated']) as $number => $term) {
            $this->assertSame('Traduction '.($number + 1), data_get($term->validated, 'label.fr'));
        }
    }

    public function test_search_projection_preserves_all_translations_through_edits_and_source_changes(): void
    {
        $this->import([['Name' => 'Key', 'de_CH' => 'Referenz', 'fr_CH' => 'Origine', 'it_CH' => 'Italiano', 'en_US' => '0']]);
        $workflow = app(WorkflowService::class);
        $term = Term::first();
        $proposal = $workflow->propose($this->translator, $term, 'label', 'fr', 'Manuelle');
        $text = DB::table('term_search')->where('term_id', $term->id)->value('search_text');
        foreach (['Origine', 'Italiano', '0', 'Manuelle'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $workflow->edit($this->translator, $proposal, 'Corrigee', 1);
        $text = DB::table('term_search')->where('term_id', $term->id)->value('search_text');
        $this->assertStringNotContainsString('Manuelle', $text);
        $this->assertStringContainsString('Corrigee', $text);
        $workflow->decide($this->validator, $proposal, 'validate', 2);
        $this->import([['Name' => 'Key', 'de_CH' => 'Neue Referenz', 'fr_CH' => 'Nouvelle origine', 'it_CH' => 'Italiano', 'en_US' => '0']]);
        $text = DB::table('term_search')->where('term_id', $term->id)->value('search_text');
        foreach (['Neue Referenz', 'Origine', 'Nouvelle origine', 'Italiano', 'Corrigee', '0'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
    }

    public function test_application_order_selects_latest_source_not_upload_order(): void
    {
        $service = app(ImportService::class);
        $parameters = ['type' => 'server', 'version_id' => $this->version->id, 'organization_id' => null, 'source_kind' => 'publisher'];
        $a = $service->receive($this->input([['Name' => 'A', 'de_CH' => 'Source A'], ['Name' => 'B', 'de_CH' => 'Source B']]), 'a.csv', $parameters, $this->manager);
        $b = $service->receive($this->input([['Name' => 'B', 'de_CH' => 'Source B']]), 'b.csv', $parameters, $this->manager);
        foreach ([$b, $a] as $batch) {
            $service->analyze($batch);
            $service->apply($batch->fresh());
        }
        $this->assertFalse(Term::where('label', 'B')->first()->obsolete);
        $publicationService = app(PublicationService::class);
        $publication = $publicationService->enqueue($this->manager, ['version_id' => $this->version->id, 'types' => ['server'], 'recipients' => [$this->manager->organization_id]]);
        app(OperationLock::class)->run(fn () => $publicationService->build($publication), true);
        $manifest = $publication->fresh()->manifest;
        $this->assertSame($a->id, $manifest['recipients'][$this->manager->organization_id]['files'][0]['source_import_id']);
    }

    public function test_reimport_unchanged_or_trace_only_adds_presence_without_duplicate_revision_or_proposal(): void
    {
        $row = ['Name' => 'Key', 'de_CH' => 'Wert', 'fr_CH' => 'Valeur', 'Update User' => 'one'];
        $first = $this->import([$row]);
        $row['Update User'] = 'two';
        $second = $this->import([$row]);
        $this->assertDatabaseCount('terms', 1);
        $this->assertDatabaseCount('revisions', 1);
        $this->assertDatabaseCount('proposals', 1);
        $this->assertDatabaseCount('import_rows', 2);
        $this->assertDatabaseCount('proposal_imports', 1);
        $this->assertSame('applied', $second->status);
    }

    public function test_four_eyes_stale_edits_supersession_and_full_revision(): void
    {
        $this->import([['Name' => 'Key', 'de_CH' => 'Wert']]);
        $term = Term::first();
        $workflow = app(WorkflowService::class);
        $p = $workflow->propose($this->translator, $term, 'label', 'fr', 'Valeur', 1);
        $other = $workflow->propose($this->translator, $term, 'label', 'fr', 'Autre', 1);
        $p = $workflow->edit($this->translator, $p, 'La valeur', 1);
        try {
            $workflow->decide($this->validator, $p, 'validate', 1);
            $this->fail('stale edit accepted');
        } catch (ValidationException) {
        }
        $workflow->decide($this->validator, $p, 'validate', 2);
        $this->assertSame('La valeur', Term::first()->validated['label']['fr']);
        $this->assertSame('rejected', $other->fresh()->status);
        $this->assertSame(2, Revision::count());
        $this->assertSame([], Revision::where('number', 1)->first()->validated);
        try {
            $workflow->decide($this->validator, $other, 'validate', 1);
            $this->fail('superseded accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(2, Revision::count());
    }

    public function test_manager_self_approval_requires_explicit_audited_override(): void
    {
        $this->import([['Name' => 'Key', 'de_CH' => 'Wert']]);
        $w = app(WorkflowService::class);
        $p = $w->propose($this->manager, Term::first(), 'label', 'fr', 'Valeur');
        try {
            $w->decide($this->manager, $p, 'validate', 1);
            $this->fail('self approval accepted');
        } catch (ValidationException) {
        }
        $w->decide($this->manager, $p, 'validate', 1, null, true);
        $this->assertDatabaseHas('audit_events', ['action' => 'validation.four_eyes_override']);
    }

    public function test_placeholders_encoding_and_rejection_reason_are_enforced_on_server(): void
    {
        $this->import([['Name' => 'Key', 'de_CH' => 'Hallo {name} {} [Tatbestand]', 'fr_CH' => 'Invalide']]);
        $w = app(WorkflowService::class);
        $p = Proposal::first();
        $this->assertNotEmpty($p->anomalies);
        foreach (['validate', 'reject'] as $decision) {
            try {
                $w->decide($this->validator, $p, $decision, 1);
                $this->fail('invalid decision accepted');
            } catch (ValidationException) {
            }
        }
        foreach (['Bonjour', 'Bonjour {name} {} [Tatbestand] 😀', ''] as $value) {
            try {
                $w->propose($this->translator, Term::first(), 'label', 'fr', $value);
                $this->fail('invalid value accepted');
            } catch (ValidationException) {
            }
        }
        $w->decide($this->validator, $p, 'reject', 1, 'Placeholder manquant');
        $this->assertSame('rejected', $p->fresh()->status);
    }

    public function test_source_changes_require_review_and_confirmation_does_not_create_revision(): void
    {
        $this->import([['Name' => 'Key', 'de_CH' => 'Wert', 'fr_CH' => 'Valeur']]);
        $w = app(WorkflowService::class);
        $w->decide($this->validator, Proposal::first(), 'validate', 1);
        $this->import([['Name' => 'Key', 'de_CH' => 'Neuer Wert', 'fr_CH' => 'Valeur']]);
        $term = Term::first();
        $this->assertSame('review', $term->state('label', 'fr'));
        $this->assertSame(3, $term->revision_no);
        $w->confirm($this->validator, $term, 'label', 'fr', $term->lock_version);
        $this->assertSame('validated', $term->fresh()->state('label', 'fr'));
        $this->assertSame(3, Revision::count());
    }

    public function test_import_preserves_absent_terms_and_their_history_even_with_legacy_flags(): void
    {
        $a = ['Name' => 'A', 'de_CH' => 'A'];
        $b = ['Name' => 'B', 'de_CH' => 'B'];
        $first = $this->import([$a, $b]);
        $first->update(['is_complete' => true]);
        $before = Term::where('label', 'B')->firstOrFail()->toArray();
        $service = app(ImportService::class);
        foreach ([false, true] as $legacyFlag) {
            $import = $service->receive($this->input([$a]), 'source.csv', [
                'type' => 'server', 'version_id' => $this->version->id, 'organization_id' => null,
                'source_kind' => 'publisher', 'is_complete' => $legacyFlag,
            ], $this->manager);
            $this->assertFalse($import->is_complete, 'Older callers cannot select an import mode.');
            // Also cover work received before this change with either historical flag.
            $import->update(['is_complete' => $legacyFlag]);
            $service->analyze($import);
            $service->apply($import->fresh());
            $this->assertSame($before, Term::where('label', 'B')->firstOrFail()->toArray());
            $this->assertSame(0, $import->fresh()->report['obsolete']);
        }
        $this->assertDatabaseCount('terms', 2);
        $this->assertDatabaseCount('revisions', 2);
    }

    public function test_exact_keys_preserve_case_and_trailing_spaces_and_keep_collisions(): void
    {
        $i = $this->import([['Name' => 'a', 'de_CH' => 'a'], ['Name' => 'A', 'de_CH' => 'b'], ['Name' => 'A ', 'de_CH' => 'c'], ['Name' => 'a', 'de_CH' => 'd']]);
        $this->assertDatabaseCount('terms', 3);
        $this->assertDatabaseCount('import_rows', 4);
        $this->assertSame(1, $i->report['collisions']);
        $this->assertSame(2, DB::table('import_rows')->where('collision', true)->count());
    }

    public function test_changed_catalogue_requires_new_analysis_before_apply(): void
    {
        $s = app(ImportService::class);
        $i = $s->receive($this->input([['Name' => 'Key', 'de_CH' => 'A']]), 'source.csv', ['type' => 'server', 'version_id' => $this->version->id, 'organization_id' => null, 'source_kind' => 'publisher'], $this->manager);
        $s->analyze($i);
        DB::table('catalogue_state')->where('id', 1)->increment('generation');
        $s->apply($i->fresh());
        $this->assertSame('queued', $i->fresh()->status);
        $this->assertDatabaseCount('terms', 0);
        $s->analyze($i->fresh());
        $s->apply($i->fresh());
        $this->assertDatabaseCount('terms', 1);
        $this->assertDatabaseCount('import_rows', 1);
    }

    public function test_stale_cancellation_and_retry_cannot_remove_an_applied_export_source(): void
    {
        $service = app(ImportService::class);
        $import = $service->receive($this->input([['Name' => 'Stable', 'de_CH' => 'Text', 'fr_CH' => 'Texte']]), 'source.csv', [
            'type' => 'server', 'version_id' => $this->version->id, 'source_kind' => 'publisher',
        ], $this->manager);
        $service->analyze($import);
        $stale = $import->fresh();
        $service->apply($import->fresh());
        $this->actingAs($this->manager);

        foreach (['cancel' => 'analyzed', 'retry' => 'failed'] as $method => $oldStatus) {
            $stale->status = $oldStatus;
            try {
                app(ImportController::class)->$method($stale, app(OperationLock::class), app(AuditService::class));
                $this->fail('Stale transition was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame('applied', $import->fresh()->status);
            $this->assertSame($import->id, app(DevconfExportService::class)->findSource($this->version->id, 'server', $this->manager->organization_id)?->id);
            $this->assertDatabaseCount('terms', 1);
            $this->assertDatabaseCount('revisions', 1);
            $this->assertDatabaseCount('proposals', 1);
            $this->assertSame(1, DB::table('import_rows')->where('import_id', $import->id)->whereNotNull('term_id')->count());
        }
    }

    public function test_cancel_and_retry_roll_back_if_their_audit_fails_and_audit_once_on_success(): void
    {
        $import = app(ImportService::class)->receive($this->input([['Name' => 'Transition']]), 'source.csv', [
            'type' => 'server', 'version_id' => $this->version->id, 'source_kind' => 'publisher',
        ], $this->manager);
        $this->actingAs($this->manager);
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Synthetic audit failure'));
        foreach (['cancel' => 'cancelled', 'retry' => 'queued'] as $method => $targetStatus) {
            $import->refresh()->update(['status' => 'failed', 'error' => 'ui.analysis_stale']);
            $before = $import->fresh()->getRawOriginal();
            try {
                app(ImportController::class)->$method($import, app(OperationLock::class), $audit);
                $this->fail('Audit failure was ignored.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Synthetic audit failure', $exception->getMessage());
            }
            $this->assertSame($before, $import->fresh()->getRawOriginal());
            $action = $method === 'cancel' ? 'import.cancelled' : 'import.retried';
            $this->assertSame(0, DB::table('audit_events')->where('action', $action)->count());
            app(ImportController::class)->$method($import, app(OperationLock::class), app(AuditService::class));
            $this->assertSame($targetStatus, $import->fresh()->status);
            $this->assertSame(1, DB::table('audit_events')->where('action', $action)->count());
        }
        $this->assertNull($import->fresh()->error);
    }

    public function test_worker_technical_failure_never_persists_or_logs_sql_bindings(): void
    {
        $import = app(ImportService::class)->receive($this->input([['Name' => 'Failure']]), 'source.csv', [
            'type' => 'server', 'version_id' => $this->version->id, 'source_kind' => 'publisher',
        ], $this->manager);
        $secret = 'SYNTHETIC_PRIVATE_MARKER_98';
        $cause = new \PDOException('driver failure '.$secret);
        $cause->errorInfo = ['HY000', 1, $secret];
        $exception = new QueryException('testing', 'insert into terms values (?)', [$secret], $cause);
        $this->mock(ImportService::class)->shouldReceive('analyze')->once()->andThrow($exception);
        $logPath = storage_path('app/private/failure-test.log');
        $logger = new Logger('redaction', [new StreamHandler($logPath)]);
        Log::swap(new \Illuminate\Log\Logger($logger));

        $this->artisan('referentiel:work', ['--once' => true])->assertExitCode(1);
        $stored = $import->fresh()->error;
        $metadata = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertSame('ui.operation_failed', $metadata['myabi_error']);
        $this->assertStringNotContainsString($secret, $stored);
        $log = file_get_contents($logPath);
        $this->assertStringNotContainsString($secret, $log);
        $this->assertStringNotContainsString('insert into', $log);
        $this->assertStringContainsString('HY000', $log);
        $this->assertStringContainsString($metadata['reference'], $log);
        foreach (['de', 'fr', 'it'] as $locale) {
            app()->setLocale($locale);
            $this->assertStringContainsString($metadata['reference'], LocalizedMessage::display($stored));
        }
    }

    public function test_root_and_null_scopes_are_visible_in_catalogue_search_dashboard_and_export(): void
    {
        $this->withoutMiddleware(RequireTwoFactor::class);
        $import = $this->import([
            ['Name' => 'Common null', 'de_CH' => 'A', 'fr_CH' => 'Texte A'],
            ['Name' => 'Common root', 'de_CH' => 'B', 'fr_CH' => 'Texte B'],
        ]);
        $root = Organisation::create(['name' => 'Root', 'code' => 'ARGE', 'is_root' => true, 'active' => true]);
        $term = Term::where('label', 'Common root')->firstOrFail();
        $this->actingAs($this->manager)->post(route('terms.scope', $term), [
            'organization_id' => $root->id, 'lock_version' => $term->lock_version,
        ])->assertSessionHasNoErrors();
        $term->refresh();
        $this->assertSame($root->id, $term->organization_id);
        $this->assertTrue($this->translator->canTranslate('fr', $root->id));
        $this->assertTrue($this->translator->canSeeOrganisation($root->id));
        $reader = User::factory()->create(['organization_id' => $this->translator->organization_id, 'roles' => ['reader']]);
        foreach ([$reader, $this->translator] as $user) {
            $this->actingAs($user)->get(route('terms.show', $term))->assertOk();
            foreach (['', '?scope=common', '?q=Common'] as $query) {
                $response = $this->get('/terms'.$query)->assertOk();
                $this->assertSame(2, $response->viewData('terms')->total());
                $this->assertSame(['server' => 2], $response->viewData('typeCounts')->all());
            }
            $this->assertSame(2, $this->get('/')->assertOk()->viewData('stats')['terms']);
        }
        $this->assertSame(2, app(ProposalFilter::class)->query($this->manager, ['language' => 'fr', 'scope' => 'common'])->count());
        $sources = app(DevconfExportService::class)->selectSources($this->version->id, ['server'], [$this->manager->organization_id]);
        $this->assertSame($import->id, $sources[$this->manager->organization_id]['server']->id);
        $this->assertDatabaseCount('terms', 2);
    }

    public function test_heavy_operation_blocks_writes_and_quota_preflight_prevents_import(): void
    {
        $lock = app(OperationLock::class);
        $lock->run(function () use ($lock) {
            $this->assertTrue($lock->busy());
            try {
                $lock->run(fn () => null);
                $this->fail('concurrent heavy accepted');
            } catch (ValidationException) {
            }
        }, true);
        config(['referentiel.storage_quota' => 1]);
        try {
            $this->import([['Name' => 'K']]);
            $this->fail('quota ignored');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_reader_cannot_import_or_submit_and_foreign_term_is_protected(): void
    {
        $import = $this->import([['Name' => 'Key', 'de_CH' => 'Wert']]);
        $other = Organisation::create(['code' => 'TI', 'name' => 'Police TI']);
        $term = Term::first();
        $term->update(['organization_id' => $other->id]);
        $reader = User::factory()->create(['organization_id' => $this->translator->organization_id, 'roles' => ['reader' => ['fr']]]);
        $this->actingAs($reader)->get('/terms/'.$term->id)->assertForbidden();
        $this->get('/imports')->assertForbidden();
        $this->get('/imports/'.$import->id.'/original')->assertForbidden();
        $this->post('/terms/'.$term->id.'/proposals', ['attribute' => 'label', 'language' => 'fr', 'value' => 'Valeur', 'lock_version' => 1])->assertForbidden();
    }

    public function test_explicit_scope_survives_same_source_reimport_without_resetting_validated_values(): void
    {
        $row = ['Name' => 'Scoped', 'de_CH' => 'Wert', 'fr_CH' => 'Valeur'];
        $this->import([$row]);
        app(WorkflowService::class)->decide($this->validator, Proposal::first(), 'validate', 1);
        $term = Term::first();
        $owner = $this->manager->organization_id;
        $term->update(['organization_id' => $owner, 'identity_hash' => hash('sha256', json_encode(['server', $owner, $term->key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
        $this->import([$row]);
        $this->assertDatabaseCount('terms', 1);
        $this->assertSame($owner, $term->fresh()->organization_id);
        $this->assertSame('Valeur', $term->fresh()->validated['label']['fr']);
    }
}
