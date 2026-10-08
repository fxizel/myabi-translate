<?php

namespace Tests\Feature;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\EnsureSecureSession;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Publication;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\OperationLock;
use App\Services\PublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class PublicationTest extends TestCase
{
    use RefreshDatabase;

    private string $privateStorage;

    private User $manager;

    private User $reader;

    private Organisation $organization;

    private Organisation $other;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'referentiel-publication-test-'.bin2hex(random_bytes(8));
        mkdir($this->privateStorage.'/app/private/originals', 0700, true);
        mkdir($this->privateStorage.'/framework/views', 0700, true);
        $this->app->useStoragePath($this->privateStorage);
        config(['referentiel.storage_quota' => 2_000_000_000, 'referentiel.external_storage_used' => 0,
            'view.compiled' => $this->privateStorage.'/framework/views']);
        $this->withoutMiddleware([EnsureSecureSession::class, RequireTwoFactor::class]);
        $this->organization = Organisation::create(['name' => 'Vaud', 'code' => 'VD', 'active' => true]);
        $this->other = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $this->manager = $this->user('manager', $this->organization->id);
        $this->reader = $this->user('reader', $this->organization->id);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateStorage) && str_starts_with(basename($this->privateStorage), 'referentiel-publication-test-')) {
            File::deleteDirectory($this->privateStorage);
        }
        parent::tearDown();
    }

    public function test_submission_without_applied_sources_is_rejected_before_a_publication_is_created(): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('publications.store'), [
            'version_id' => $this->version->id, 'types' => ['mric'], 'confirm' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('source');

        self::assertStringContainsString(FormatRegistry::get('mric')->label, implode(' ', $response->json('errors.source')));
        self::assertStringContainsString(__('ui.publication_source_missing'), implode(' ', $response->json('errors.source')));
        self::assertDatabaseCount('publications', 0);
        self::assertDatabaseMissing('audit_events', ['action' => 'publication.queued']);
    }

    public function test_submission_reports_only_missing_types_without_creating_a_publication(): void
    {
        [$source] = $this->source('mric');
        $source->update(['is_complete' => false]);

        $response = $this->actingAs($this->manager)->postJson(route('publications.store'), [
            'version_id' => $this->version->id, 'types' => ['mric', 'server', 'monomot'], 'confirm' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('source');

        $errors = implode(' ', $response->json('errors.source'));
        foreach (['server', 'monomot'] as $type) {
            self::assertStringContainsString(FormatRegistry::get($type)->label, $errors);
        }
        self::assertStringNotContainsString(FormatRegistry::get('mric')->label, $errors);
        self::assertStringContainsString(__('ui.publication_source_missing'), $errors);
        self::assertCount(2, $response->json('errors.source'));
        self::assertDatabaseCount('publications', 0);
        self::assertDatabaseMissing('audit_events', ['action' => 'publication.queued']);
        self::assertFalse($source->fresh()->is_complete);
    }

    public function test_organization_source_with_legacy_false_flag_keeps_priority_over_publisher(): void
    {
        [$publisher, $term] = $this->source('mric');
        [$local] = $this->source('mric', $this->organization->id, existing: $term);
        $local->update(['is_complete' => false]);

        $this->actingAs($this->manager)->post(route('publications.store'), [
            'version_id' => $this->version->id, 'types' => ['mric'],
            'recipients' => [$this->organization->id, $this->other->id], 'confirm' => 1,
        ])->assertRedirect();

        self::assertDatabaseCount('publications', 1);
        $publication = Publication::firstOrFail();
        $this->build($publication);
        $publication->refresh();
        self::assertSame('published', $publication->status);
        self::assertSame($local->id, $publication->manifest['recipients'][$this->organization->id]['files'][0]['source_import_id']);
        self::assertSame($publisher->id, $publication->manifest['recipients'][$this->other->id]['files'][0]['source_import_id']);
        self::assertFalse($local->fresh()->is_complete);
        self::assertTrue($publisher->fresh()->is_complete);
        self::assertDatabaseCount('import_batches', 2);
    }

    public function test_legacy_source_can_be_published_and_downloaded_without_reimport_or_flag_change(): void
    {
        [$source] = $this->source('mric');
        $source->update(['is_complete' => false]);
        $this->actingAs($this->manager)->post(route('publications.store'), [
            'version_id' => $this->version->id, 'types' => ['mric'], 'confirm' => 1,
        ])->assertRedirect();

        $publication = Publication::firstOrFail();
        self::assertSame('queued', $publication->status);
        self::assertSame(['mric'], $publication->types);
        self::assertCount(2, $publication->recipients);
        $this->actingAs($this->reader)->getJson(route('publications.download', $publication))->assertUnprocessable();

        $this->artisan('referentiel:work', ['--once' => true])->assertSuccessful();

        $publication->refresh();
        self::assertSame('published', $publication->status);
        $recipient = $publication->manifest['recipients'][$this->organization->id];
        self::assertCount(1, $recipient['files']);
        self::assertSame('mric', $recipient['files'][0]['type']);
        self::assertSame($source->id, $recipient['files'][0]['source_import_id']);
        self::assertSame($source->sha256, $recipient['files'][0]['sha256']);
        self::assertFalse($source->fresh()->is_complete);
        self::assertDatabaseCount('import_batches', 1);
        $this->get(route('publications.show', $publication))->assertOk()
            ->assertSee(route('publications.download', ['publication' => $publication, 'organization_id' => $this->organization->id]));
        $this->get(route('publications.download', $publication))->assertOk()->assertDownload($recipient['archive']['name']);
        self::assertDatabaseHas('audit_events', ['action' => 'publication.downloaded']);
    }

    public function test_rejected_submission_keeps_the_selected_version_and_types_in_the_form(): void
    {
        MyabiVersion::create(['number' => '2026.3', 'released_at' => '2026-10-01', 'status' => 'preparation',
            'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $this->actingAs($this->manager)->from(route('publications.index'))->post(route('publications.store'), [
            'version_id' => $this->version->id, 'types' => ['mric'], 'confirm' => 1,
        ])->assertRedirect(route('publications.index'))->assertSessionHasErrors('source');

        $response = $this->get(route('publications.index'))->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $page = new \DOMXPath($document);
        $form = '//form[@action="'.route('publications.store').'"]';
        $versions = $page->query($form.'//select[@name="version_id"]/option[@selected]');
        self::assertSame(1, $versions->length);
        self::assertSame((string) $this->version->id, $versions->item(0)->getAttribute('value'));
        $types = $page->query($form.'//input[@name="types[]" and @checked]');
        self::assertSame(1, $types->length);
        self::assertSame('mric', $types->item(0)->getAttribute('value'));
    }

    public function test_malformed_old_input_does_not_prevent_rendering_the_publication_form(): void
    {
        $this->actingAs($this->manager)->from(route('publications.index'))->post(route('publications.store'), [
            'version_id' => [$this->version->id], 'types' => 'mric', 'confirm' => 1,
        ])->assertRedirect(route('publications.index'))->assertSessionHasErrors(['version_id', 'types']);

        $this->get(route('publications.index'))->assertOk()->assertSee(__('ui.new_publication'));
        self::assertDatabaseCount('publications', 0);
    }

    public function test_deselecting_every_type_keeps_the_form_empty_after_validation(): void
    {
        $this->actingAs($this->manager)->from(route('publications.index'))->post(route('publications.store'), [
            'version_id' => $this->version->id, 'confirm' => 1,
        ])->assertRedirect(route('publications.index'))->assertSessionHasErrors('types');

        $response = $this->get(route('publications.index'))->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $page = new \DOMXPath($document);
        $checkboxes = '//form[@action="'.route('publications.store').'"]//input[@name="types[]"]';
        self::assertSame(count(FormatRegistry::all()), $page->query($checkboxes)->length);
        self::assertSame(0, $page->query($checkboxes.'[@checked]')->length);
        self::assertDatabaseCount('publications', 0);
    }

    public function test_publication_freezes_all_seven_files_and_current_revisions(): void
    {
        $sources = [];
        foreach (array_keys(FormatRegistry::all()) as $type) {
            $sources[$type] = $this->source($type);
        }
        $publication = $this->enqueue(array_keys($sources));
        self::assertSame('queued', $publication->status);
        self::assertNull($publication->manifest);
        $this->build($publication);
        $publication->refresh();
        self::assertSame('published', $publication->status);
        $recipient = $publication->manifest['recipients'][$this->organization->id];
        self::assertCount(7, $recipient['files']);
        self::assertSame(7, $recipient['row_manifest']['records']);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($this->path($publication, $recipient['archive']['path'])));
        foreach ($recipient['files'] as $file) {
            $source = $sources[$file['type']][0];
            self::assertSame($source->sha256, $file['sha256']);
            self::assertSame(file_get_contents($source->originalPath()), $zip->getFromName($file['name']));
        }
        self::assertNotFalse($zip->getFromName('catalogue.json'));
        self::assertSame(7, substr_count($zip->getFromName('revisions.jsonl'), "\n"));
        $zip->close();
        $this->actingAs($this->reader)->get(route('publications.download', $publication))->assertOk()->assertDownload($recipient['archive']['name']);
        self::assertDatabaseHas('audit_events', ['action' => 'publication.downloaded']);
    }

    public function test_only_validated_cells_are_exported_and_later_changes_never_rebuild_a_publication(): void
    {
        [$source, $term] = $this->source('mric', validated: ['label' => ['fr' => 'Version validée {}']]);
        $publication = $this->enqueue(['mric']);
        $this->build($publication);
        $publication->refresh();
        $recipient = $publication->manifest['recipients'][$this->organization->id];
        $file = $recipient['files'][0];
        $csv = iterator_to_array((new CsvReader)->records($this->path($publication, $file['path'])));
        $original = iterator_to_array((new CsvReader)->records($source->originalPath()));
        self::assertSame('Version validée {}', $csv[1]->associative(FormatRegistry::get('mric')->headers)['fr_CH']);
        foreach ($original[1]->rawCells as $index => $raw) {
            if ($index !== 5) {
                self::assertSame($raw, $csv[1]->rawCells[$index]);
            }
        }
        $before = file_get_contents($this->path($publication, $recipient['archive']['path']));
        $term->update(['validated' => ['label' => ['fr' => 'Une autre valeur {}']], 'revision_no' => 2]);
        $this->build($publication);
        self::assertSame($before, file_get_contents($this->path($publication, $recipient['archive']['path'])));
        self::assertSame('Version validée {}', Revision::where('term_id', $term->id)->first()->validated['label']['fr']);
        self::assertSame(1, $term->fresh()->last_published_revision);
        self::assertSame($publication->id, $term->fresh()->last_publication_id);
    }

    public function test_shared_group_publication_keeps_all_frozen_artifacts_private_and_readable_to_the_runtime_group(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix publication permissions require a POSIX filesystem.');
        }
        config(['referentiel.private_shared_group' => true]);
        $previousUmask = umask(0077);
        try {
            $this->source('mric');
            $publication = $this->enqueue(['mric']);
            $this->build($publication);
            $publication->refresh();
            self::assertSame('published', $publication->status);
            $root = storage_path('app/private/publications/'.$publication->id);
            $files = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                self::assertSame($entry->isDir() ? 0770 : 0660, $entry->getPerms() & 0777);
                $files += $entry->isFile() ? 1 : 0;
            }
            self::assertSame(5, $files); // CSV, ZIP, revisions, catalogue and publication manifest.
        } finally {
            umask($previousUmask);
        }
    }

    public function test_recipient_uses_its_instance_source_and_other_recipient_uses_publisher(): void
    {
        [$publisher, $term] = $this->source('mric');
        [$local] = $this->source('mric', $this->organization->id, existing: $term);
        $publication = $this->enqueue(['mric'], [$this->organization->id, $this->other->id]);
        $this->build($publication);
        $publication->refresh();
        self::assertSame($local->id, $publication->manifest['recipients'][$this->organization->id]['files'][0]['source_import_id']);
        self::assertSame($publisher->id, $publication->manifest['recipients'][$this->other->id]['files'][0]['source_import_id']);
    }

    public function test_readers_cannot_publish_withdraw_or_download_another_organization_archive(): void
    {
        $this->source('mric');
        $publication = $this->enqueue(['mric']);
        $this->build($publication);
        $otherReader = $this->user('reader', $this->other->id);
        $this->actingAs($otherReader)->get(route('publications.download', $publication))->assertForbidden();
        $this->actingAs($this->reader)->get(route('publications.download', $publication).'?organization_id='.$this->other->id)->assertForbidden();
        $this->actingAs($this->reader)->post(route('publications.store'), ['version_id' => $this->version->id, 'types' => ['mric'], 'confirm' => 1])->assertForbidden();
        $this->actingAs($this->reader)->post(route('publications.withdraw', $publication), ['reason' => 'Reason', 'confirm' => 1])->assertForbidden();
    }

    public function test_withdrawal_requires_reason_and_download_requires_explicit_withdrawn_choice(): void
    {
        $this->source('mric');
        $publication = $this->enqueue(['mric']);
        $this->build($publication);
        $this->actingAs($this->manager)->postJson(route('publications.withdraw', $publication), ['reason' => '', 'confirm' => 1])->assertUnprocessable();
        app(PublicationService::class)->withdraw($this->manager, $publication, 'Terminology under review');
        $publication->refresh();
        self::assertSame('withdrawn', $publication->status);
        $this->actingAs($this->reader)->getJson(route('publications.download', $publication))->assertUnprocessable();
        $this->actingAs($this->reader)->get(route('publications.download', $publication).'?withdrawn=1')->assertOk();
    }

    public function test_collision_and_incomplete_row_provenance_still_block_a_legacy_source(): void
    {
        [$source] = $this->source('mric');
        $source->update(['is_complete' => false]);
        DB::table('import_rows')->where('import_id', $source->id)->update(['collision' => true]);
        $this->assertBuildBlocked('publication_collision');
        DB::table('import_rows')->where('import_id', $source->id)->update(['collision' => false]);
        DB::table('import_rows')->where('import_id', $source->id)->update(['term_id' => null]);
        $this->assertBuildBlocked('publication_source_count');
        self::assertFalse($source->fresh()->is_complete);
    }

    public function test_foreign_and_unconfirmed_scope_block_instead_of_omitting_lines(): void
    {
        [, $term] = $this->source('mric');
        $term->update(['organization_id' => $this->other->id]);
        $this->assertBuildBlocked('publication_foreign_scope');
        $term->update(['organization_id' => null, 'scope_unconfirmed' => true]);
        $this->assertBuildBlocked('publication_scope_unconfirmed');
    }

    public function test_missing_source_and_modified_original_block_publication(): void
    {
        [$source] = $this->source('mric');
        $publication = $this->enqueue(['mric']);
        $source->update(['status' => 'analyzed']);
        $this->assertBuildBlocked('publication_source_missing', $publication);
        $source->update(['status' => 'applied']);
        file_put_contents($source->originalPath(), 'tampered');
        $this->assertBuildBlocked('publication_source_checksum');
    }

    public function test_missing_term_in_fallback_source_is_not_silently_omitted(): void
    {
        $this->source('mric');
        $this->source('mric');
        $this->assertBuildBlocked('publication_source_missing_rows');
    }

    public function test_unavailable_revision_and_stale_placeholders_fail_without_downloadable_files(): void
    {
        [, $term] = $this->source('mric');
        $term->update(['revision_no' => 2]);
        $this->assertBuildBlocked('publication_identity_mismatch');
        $term->update(['revision_no' => 1]);
        DB::table('revisions')->where('term_id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Missing placeholder']])]);
        $publication = $this->enqueue(['mric']);
        try {
            $this->build($publication);
            self::fail('A stale placeholder must stop publication.');
        } catch (\InvalidArgumentException) {
            self::assertSame('failed', $publication->fresh()->status);
            self::assertDirectoryDoesNotExist(storage_path('app/private/publications/'.$publication->id));
        }
    }

    public function test_finalized_files_recover_after_database_commit_interruption_without_rebuilding(): void
    {
        [, $term] = $this->source('mric');
        $publication = $this->enqueue(['mric']);
        $this->build($publication);
        $publication->refresh();
        $manifest = $publication->manifest;
        $publication->update(['status' => 'building', 'manifest' => null]);
        $term->update(['validated' => ['label' => ['fr' => 'Later validation {}']], 'revision_no' => 2]);
        $this->build($publication);
        self::assertSame($manifest, $publication->fresh()->manifest);
        self::assertSame('published', $publication->fresh()->status);
        self::assertSame(1, $term->fresh()->last_published_revision, 'Recovery must keep the frozen revision, not the current revision 2.');
    }

    public function test_interrupted_staging_is_cleaned_before_retry_and_is_never_downloadable(): void
    {
        $this->source('mric');
        $publication = $this->enqueue(['mric']);
        $publication->update(['status' => 'building']);
        $staging = storage_path('app/private/publications/.building-'.$publication->id.'-interrupted');
        mkdir($staging, 0700, true);
        file_put_contents($staging.'/partial.csv', 'partial');
        $this->actingAs($this->reader)->getJson(route('publications.download', $publication))->assertUnprocessable();
        $this->build($publication);
        self::assertDirectoryDoesNotExist($staging);
        self::assertSame('published', $publication->fresh()->status);
    }

    public function test_older_versions_cannot_receive_new_publications_but_frozen_downloads_survive(): void
    {
        $this->source('mric');
        $old = $this->enqueue(['mric']);
        $this->build($old);
        $newVersion = MyabiVersion::create(['number' => '2026.3', 'released_at' => '2026-10-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $this->version = $newVersion;
        $this->source('mric');
        $this->actingAs($this->reader)->get(route('publications.download', $old))->assertOk();
        $this->expectException(ValidationException::class);
        app(PublicationService::class)->enqueue($this->manager, ['version_id' => $old->version_id, 'types' => ['mric'], 'recipients' => [$this->organization->id]]);
    }

    public function test_version_management_enforces_manager_and_one_current_version(): void
    {
        $this->actingAs($this->reader)->post(route('versions.store'), ['number' => '2026.3', 'released_at' => '2026-10-01', 'status' => 'current'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('versions.store'), ['number' => '2026.3', 'released_at' => '2026-10-01', 'status' => 'current'])->assertRedirect();
        self::assertSame(1, MyabiVersion::where('status', 'current')->count());
        self::assertSame('archived', $this->version->fresh()->status);
    }

    private function assertBuildBlocked(string $key, ?Publication $publication = null): void
    {
        $publication ??= $this->enqueue(['mric']);
        try {
            $this->build($publication);
            self::fail('Expected publication block: '.$key);
        } catch (ValidationException $error) {
            self::assertStringContainsString(__('ui.'.$key), implode(' ', array_merge(...array_values($error->errors()))));
            self::assertSame('failed', $publication->fresh()->status);
            self::assertNull($publication->fresh()->manifest);
            self::assertDirectoryDoesNotExist(storage_path('app/private/publications/'.$publication->id));
        }
    }

    private function enqueue(array $types, ?array $recipients = null): Publication
    {
        return app(PublicationService::class)->enqueue($this->manager, ['version_id' => $this->version->id, 'types' => $types, 'recipients' => $recipients ?? [$this->organization->id]]);
    }

    private function build(Publication $publication): void
    {
        app(OperationLock::class)->run(fn () => app(PublicationService::class)->build($publication), true);
    }

    private function path(Publication $publication, string $relative): string
    {
        return app(PublicationService::class)->artifactPath($publication, $relative);
    }

    private function user(string $role, int $organizationId): User
    {
        return User::create(['name' => ucfirst($role), 'email' => bin2hex(random_bytes(5)).'@example.test', 'password' => 'Long-test-password-123',
            'roles' => [$role], 'languages' => ['fr'], 'organization_id' => $organizationId, 'active' => true]);
    }

    /** Synthetic sources contain no institutional data. */
    private function source(string $type, ?int $organizationId = null, array $validated = [], ?Term $existing = null): array
    {
        $format = FormatRegistry::get($type);
        $row = array_fill_keys($format->headers, '');
        $key = $existing?->key ?? array_map(fn ($column) => $type.'-'.bin2hex(random_bytes(4)), $format->identityColumns);
        foreach ($format->identityColumns as $index => $column) {
            $row[$column] = $key[$index];
        }
        if ($format->layout === 'pairs') {
            $row['Default Language'] = 'de_CH';
            $row['Default Label'] = 'Quelle {}';
            foreach (['it_CH', 'fr_CH', 'en_US', 'sa_IN'] as $index => $language) {
                $row['Language '.($index + 1)] = $language;
                $row['Translation '.($index + 1)] = 'Import {}';
            }
        } else {
            foreach ($format->attributes($row) as $attribute) {
                foreach ($attribute['columns'] as $language => $column) {
                    $row[$column] = $language === 'de' ? 'Quelle {}' : 'Import {}';
                }
            }
        }
        $csv = function (array $values): string {
            return implode(';', array_map(fn ($value) => '"'.str_replace('"', '""', Encoding::encode($value)).'"', $values))."\r\n";
        };
        $bytes = $csv($format->headers).$csv(array_values($row));
        $path = 'originals/'.bin2hex(random_bytes(10)).'.csv';
        file_put_contents(storage_path('app/private/'.$path), $bytes);
        $record = iterator_to_array((new CsvReader)->records(storage_path('app/private/'.$path)))[1];
        $source = ImportBatch::create(['version_id' => $this->version->id, 'type' => $type, 'format_version' => $format->version,
            'organization_id' => $organizationId, 'source_kind' => $organizationId ? 'organization' : 'publisher', 'is_complete' => true,
            'filename' => $format->filename, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'status' => 'applied',
            'row_count' => 1, 'analysis_offset' => strlen($bytes), 'report' => [], 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $term = $existing ?? Term::create(['type' => $type, 'organization_id' => null, 'identity_hash' => $format->identityHash($row), 'key' => $key,
            'label' => implode(' / ', $key), 'context' => '', 'source_text' => 'Quelle {}', 'search_text' => 'Quelle {}',
            'semantic_hash' => $format->semanticHash($row), 'source_hash' => $format->sourceHash($row), 'source_import_id' => $source->id,
            'source_offset' => $record->offset, 'source_length' => $record->length, 'validated' => $validated, 'review_needed' => [],
            'revision_no' => 1, 'lock_version' => 1, 'obsolete' => false, 'scope_unconfirmed' => false, 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        DB::table('import_rows')->insert(['import_id' => $source->id, 'record_number' => 1, 'offset' => $record->offset, 'length' => $record->length,
            'identity_hash' => $format->identityHash($row), 'term_id' => $term->id, 'collision' => false, 'category' => 'new']);
        if (! $existing) {
            Revision::create(['term_id' => $term->id, 'number' => 1, 'version_id' => $this->version->id, 'source_import_id' => $source->id,
                'source_offset' => $record->offset, 'source_length' => $record->length, 'validated' => $validated,
                'metadata' => ['key' => $key, 'organization_id' => null], 'origin' => 'import', 'origin_id' => $source->id, 'created_by' => $this->manager->id, 'created_at' => now()]);
        }

        return [$source, $term];
    }
}
