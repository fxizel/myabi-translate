<?php

namespace Tests\Feature;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\EnsureSecureSession;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\OperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private string $privateStorage;

    private User $manager;

    private Organisation $organization;

    private Organisation $other;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'referentiel-export-test-'.bin2hex(random_bytes(8));
        mkdir($this->privateStorage.'/app/private/originals', 0700, true);
        mkdir($this->privateStorage.'/framework/views', 0700, true);
        $this->app->useStoragePath($this->privateStorage);
        config(['referentiel.storage_quota' => 2_000_000_000, 'referentiel.external_storage_used' => 0,
            'view.compiled' => $this->privateStorage.'/framework/views']);
        $this->withoutMiddleware([EnsureSecureSession::class, RequireTwoFactor::class]);
        $this->organization = Organisation::create(['name' => 'Vaud', 'code' => 'VD', 'active' => true]);
        $this->other = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $this->manager = $this->user('manager');
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current',
            'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateStorage) && str_starts_with(basename($this->privateStorage), 'referentiel-export-test-')) {
            File::deleteDirectory($this->privateStorage);
        }
        parent::tearDown();
    }

    public function test_each_supported_type_downloads_one_identical_csv_and_deletes_its_private_temporary_file(): void
    {
        foreach (FormatRegistry::all() as $type => $format) {
            [$source, $term] = $this->source($type);
            $before = $term->fresh()->getAttributes();
            $response = $this->download($type)->assertOk()->assertDownload($format->filename)
                ->assertHeader('Content-Type', 'text/csv; charset=Windows-1252');
            self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $path = $response->baseResponse->getFile()->getPathname();
            self::assertStringStartsWith(str_replace('\\', '/', storage_path('app/private')).'/', str_replace('\\', '/', $path));
            self::assertSame(file_get_contents($source->originalPath()), file_get_contents($path));
            self::assertSame($before, $term->fresh()->getAttributes());

            $audit = AuditEvent::where('action', 'export.downloaded')->latest('id')->firstOrFail();
            self::assertSame($source->id, $audit->after_values['sources'][0]['import_id']);
            self::assertSame($this->organization->id, $audit->after_values['organization_id']);
            self::assertSame($source->sha256, $audit->after_values['sources'][0]['sha256']);
            self::assertSame(hash_file('sha256', $path), $audit->after_values['sha256']);
            self::assertSame(1, $audit->after_values['records']);
            self::assertEqualsCanonicalizing(['version_id', 'type', 'organization_id', 'sources', 'sha256', 'records', 'format_version', 'coverage'], array_keys($audit->after_values));

            ob_start();
            try {
                $response->baseResponse->sendContent();
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            self::assertSame(file_get_contents($source->originalPath()), $bytes);
            self::assertFileDoesNotExist($path);
        }
        self::assertDatabaseCount('publications', 0);
        self::assertDatabaseCount('audit_events', count(FormatRegistry::all()));
    }

    public function test_only_current_revision_values_replace_cells_and_pending_proposals_remain_unchanged(): void
    {
        [$source, $term] = $this->source('mric', validated: ['label' => ['fr' => 'Valeur validée {}']]);
        $proposal = Proposal::create(['term_id' => $term->id, 'attribute' => 'label', 'language' => 'it',
            'value' => 'Proposition ignorée {}', 'value_hash' => hash('sha256', 'Proposition ignorée {}'),
            'status' => 'pending', 'author_id' => $this->manager->id, 'organization_id' => $this->organization->id,
            'anomalies' => [], 'edit_history' => []]);
        // The stored revision is the authority even if a denormalized term cache differs.
        $term->update(['validated' => ['label' => ['fr' => 'Valeur de cache {}']]]);
        $beforeTerm = $term->fresh()->getAttributes();
        $beforeProposal = $proposal->fresh()->getAttributes();

        $response = $this->download()->assertOk();
        $csv = iterator_to_array((new CsvReader)->records($response->baseResponse->getFile()->getPathname()));
        $original = iterator_to_array((new CsvReader)->records($source->originalPath()));
        $format = FormatRegistry::get('mric');
        self::assertSame('Valeur validée {}', $csv[1]->associative($format->headers)['fr_CH']);
        foreach ($original[1]->rawCells as $index => $raw) {
            if ($format->headers[$index] !== 'fr_CH') {
                self::assertSame($raw, $csv[1]->rawCells[$index]);
            }
        }
        self::assertSame($beforeTerm, $term->fresh()->getAttributes());
        self::assertSame($beforeProposal, $proposal->fresh()->getAttributes());
        self::assertDatabaseCount('revisions', 1);
        self::assertDatabaseCount('publications', 0);
    }

    public function test_current_term_source_is_independent_of_the_recipient_and_repeated_presences_add_no_row(): void
    {
        [$publisher, $term] = $this->source('mric');
        [$local] = $this->source('mric', $this->organization->id, existing: $term);
        $local->update(['is_complete' => false]);
        $this->download()->assertOk();
        self::assertSame($publisher->id, AuditEvent::where('action', 'export.downloaded')->latest('id')->firstOrFail()->after_values['sources'][0]['import_id']);
        $this->download(organizationId: $this->other->id)->assertOk();
        self::assertSame($publisher->id, AuditEvent::where('action', 'export.downloaded')->latest('id')->firstOrFail()->after_values['sources'][0]['import_id']);
        self::assertFalse($local->fresh()->is_complete);
        self::assertDatabaseCount('import_batches', 2);
        self::assertDatabaseCount('publications', 0);
    }

    public function test_export_menu_and_endpoints_require_a_manager(): void
    {
        $this->source('mric');
        $this->manager->update(['locale' => 'fr']);
        $this->actingAs($this->manager)->get(route('exports.index'))->assertOk()
            ->assertSee(route('exports.download'))->assertSee($this->version->number);
        $this->get(route('dashboard'))->assertOk()->assertSee(route('exports.index'));
        foreach (['reader', 'translator', 'validator', 'admin'] as $role) {
            $this->actingAs($this->user($role))->get(route('exports.index'))->assertForbidden();
            $this->post(route('exports.download'), $this->parameters())->assertForbidden();
            $this->get(route('dashboard'))->assertOk()->assertDontSee(route('exports.index'));
        }
        $technical = $this->user('manager');
        $technical->update(['is_technical' => true]);
        $this->actingAs($technical)->get(route('exports.index'))->assertForbidden();
        $this->post(route('exports.download'), $this->parameters())->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('exports.index'))->assertRedirect(route('login'));
        $this->post(route('exports.download'), $this->parameters())->assertRedirect(route('login'));
    }

    public function test_malformed_form_input_is_rejected_and_does_not_break_rendering(): void
    {
        $this->actingAs($this->manager)->from(route('exports.index'))->post(route('exports.download'), [
            'version_id' => [$this->version->id], 'type' => ['mric'], 'organization_id' => [$this->organization->id],
        ])->assertRedirect(route('exports.index'))->assertSessionHasErrors(['version_id', 'type', 'organization_id']);
        $this->get(route('exports.index'))->assertOk();
        self::assertDatabaseMissing('audit_events', ['action' => 'export.downloaded']);
    }

    public function test_unknown_type_and_inactive_recipient_are_rejected(): void
    {
        $this->other->update(['active' => false]);
        $this->actingAs($this->manager)->postJson(route('exports.download'), [
            'version_id' => $this->version->id, 'type' => '../originals', 'organization_id' => $this->other->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['type', 'organization_id']);
        $this->assertNoExportArtifacts();
    }

    public function test_rejected_export_keeps_the_selected_version_type_and_organization(): void
    {
        $this->source('mric');
        MyabiVersion::create(['number' => '2026.3', 'released_at' => '2026-10-01', 'status' => 'preparation',
            'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $parameters = $this->parameters('server', $this->other->id);
        $this->actingAs($this->manager)->from(route('exports.index'))->post(route('exports.download'), $parameters)
            ->assertRedirect(route('exports.index'))->assertSessionHasErrors('source');
        $response = $this->get(route('exports.index'))->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $page = new \DOMXPath($document);
        foreach ($parameters as $name => $value) {
            $selected = $page->query('//form[@action="'.route('exports.download').'"]//select[@name="'.$name.'"]/option[@selected]');
            self::assertSame(1, $selected->length, $name);
            self::assertSame((string) $value, $selected->item(0)->getAttribute('value'));
        }
    }

    public function test_only_abandoned_export_files_are_removed_before_a_new_download(): void
    {
        $this->source('mric');
        $directory = storage_path('app/private/exports');
        mkdir($directory, 0700, true);
        $abandoned = $directory.'/'.str_repeat('a', 32).'.csv';
        $recent = $directory.'/'.str_repeat('b', 32).'.csv';
        $unrelated = $directory.'/retained.csv';
        foreach ([$abandoned, $recent, $unrelated] as $path) {
            file_put_contents($path, 'Synthetic temporary content');
        }
        touch($abandoned, now()->subDays(2)->timestamp);
        touch($unrelated, now()->subDays(2)->timestamp);

        $response = $this->download()->assertOk();
        self::assertFileDoesNotExist($abandoned);
        self::assertFileExists($recent);
        self::assertFileExists($unrelated);
        self::assertFileExists($response->baseResponse->getFile()->getPathname());
    }

    public function test_another_heavy_operation_blocks_the_export_without_artifacts(): void
    {
        $this->source('mric');
        app(OperationLock::class)->run(fn () => $this->assertBlocked('operation', 'writes_paused'), true);
    }

    public function test_insufficient_storage_blocks_the_export_without_artifacts(): void
    {
        $this->source('mric');
        config(['referentiel.storage_quota' => 1]);
        $this->assertBlocked('storage', 'storage_insufficient');
    }

    public function test_missing_source_is_rejected_without_creating_a_publication_or_artifact(): void
    {
        $this->assertBlocked('source', 'publication_source_missing');
    }

    public function test_collisions_and_incomplete_provenance_cannot_be_exported(): void
    {
        [$source] = $this->source('mric');
        DB::table('import_rows')->where('import_id', $source->id)->update(['collision' => true]);
        $this->assertBlocked('source', 'publication_collision');
        DB::table('import_rows')->where('import_id', $source->id)->update(['collision' => false, 'term_id' => null]);
        $this->assertBlocked('source', 'publication_source_count');
    }

    public function test_terms_from_another_organization_are_exported(): void
    {
        [$source] = $this->source('mric', $this->other->id);
        $response = $this->download()->assertOk();
        self::assertSame(file_get_contents($source->originalPath()), file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_original_checksum_and_current_revision_are_required(): void
    {
        [$source, $term] = $this->source('mric');
        $term->update(['revision_no' => 2]);
        $this->assertBlocked('source', 'publication_identity_mismatch');
        $term->update(['revision_no' => 1]);
        file_put_contents($source->originalPath(), 'tampered');
        $this->assertBlocked('source', 'publication_source_checksum');
    }

    public function test_invalid_validated_values_fail_privately_and_remove_partial_csv_files(): void
    {
        [, $term] = $this->source('mric');
        foreach (['Synthetic invalid {other}', 'Synthetic invalid 漢 {}', 17] as $value) {
            DB::table('revisions')->where('term_id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => $value]])]);
            $response = $this->actingAs($this->manager)->postJson(route('exports.download'), $this->parameters())
                ->assertUnprocessable()->assertJsonValidationErrors('export')
                ->assertJsonPath('errors.export', [__('ui.export_failed')])
                ->assertJsonPath('message', __('ui.export_failed'));
            self::assertStringNotContainsString('Synthetic invalid', $response->getContent());
            self::assertStringNotContainsString($term->source_text, $response->getContent());
            self::assertDatabaseMissing('audit_events', ['action' => 'export.downloaded']);
            self::assertDatabaseCount('publications', 0);
            $this->assertNoExportArtifacts();
        }
    }

    public function test_all_known_terms_are_composed_from_their_own_originals_with_one_header_and_stable_order(): void
    {
        [$first] = $this->source('mric');
        [$second] = $this->source('mric', $this->other->id);
        $firstRecords = iterator_to_array((new CsvReader)->records($first->originalPath()));
        $secondRecords = iterator_to_array((new CsvReader)->records($second->originalPath()));
        $response = $this->download()->assertOk();
        $expected = $firstRecords[0]->raw().$firstRecords[1]->raw().$secondRecords[1]->raw();
        self::assertSame($expected, file_get_contents($response->baseResponse->getFile()->getPathname()));
        $other = $this->download(organizationId: $this->other->id)->assertOk();
        self::assertSame($expected, file_get_contents($other->baseResponse->getFile()->getPathname()));
        $audit = AuditEvent::where('action', 'export.downloaded')->latest('id')->firstOrFail();
        self::assertSame(2, $audit->after_values['records']);
        self::assertSame([$first->id, $second->id], array_column($audit->after_values['sources'], 'import_id'));
    }

    public function test_null_historical_validated_cell_uses_the_imported_fallback(): void
    {
        [$source] = $this->source('mric', validated: ['label' => ['fr' => null]]);
        $response = $this->download()->assertOk();
        self::assertSame(file_get_contents($source->originalPath()), file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_1200_known_terms_are_exported_from_sources_of_1000_and_200_rows_for_every_recipient(): void
    {
        [$first, $firstTerm] = $this->source('mric', validated: ['label' => ['fr' => 'Valeur commune {}']]);
        $firstKeys = $this->appendSyntheticRows($first, $firstTerm, 1000);
        [$second, $secondTerm] = $this->source('mric', $this->other->id, ['label' => ['fr' => 'Valeur commune {}']]);
        $secondKeys = $this->appendSyntheticRows($second, $secondTerm, 200);
        self::assertSame(1000, $first->fresh()->row_count);
        self::assertSame(200, $second->fresh()->row_count);
        self::assertDatabaseCount('terms', 1200);

        $response = $this->download()->assertOk();
        $bytes = file_get_contents($response->baseResponse->getFile()->getPathname());
        $records = iterator_to_array((new CsvReader)->records($response->baseResponse->getFile()->getPathname()));
        self::assertCount(1201, $records);
        self::assertSame(1, substr_count($bytes, $records[0]->raw()));
        $keys = [];
        foreach (array_slice($records, 1) as $record) {
            $row = $record->associative(FormatRegistry::get('mric')->headers);
            $keys[] = $row['Name'];
            self::assertSame('Import {}', $row['it_CH']);
            self::assertSame('Quelle {}', $row['de_CH']);
        }
        self::assertSame([...$firstKeys, ...$secondKeys], $keys);
        self::assertCount(1200, array_unique($keys));
        self::assertSame('Valeur commune {}', $records[1]->associative(FormatRegistry::get('mric')->headers)['fr_CH']);
        self::assertSame('Valeur commune {}', $records[1001]->associative(FormatRegistry::get('mric')->headers)['fr_CH']);
        self::assertSame('Import {}', $records[1200]->associative(FormatRegistry::get('mric')->headers)['fr_CH']);

        $other = $this->download(organizationId: $this->other->id)->assertOk();
        self::assertSame($bytes, file_get_contents($other->baseResponse->getFile()->getPathname()));
        $audit = AuditEvent::where('action', 'export.downloaded')->latest('id')->firstOrFail();
        self::assertSame(1200, $audit->after_values['records']);
        self::assertSame([$first->id, $second->id], array_column($audit->after_values['sources'], 'import_id'));
        self::assertSame(2, $audit->after_values['coverage']['fr']['validated']);
        self::assertSame(1198, $audit->after_values['coverage']['fr']['fallback']);
    }

    public function test_expected_term_with_missing_provenance_blocks_the_whole_multi_source_export(): void
    {
        $this->source('mric');
        [, $term] = $this->source('mric');
        DB::table('revisions')->where('term_id', $term->id)->update(['source_offset' => 0]);
        $this->assertBlocked('source', 'publication_identity_mismatch');
    }

    public function test_distinct_exact_keys_with_the_same_stored_fingerprint_are_not_merged(): void
    {
        [, $first] = $this->source('mric');
        [, $second] = $this->source('mric');
        $second->update(['identity_hash' => $first->identity_hash]);
        $response = $this->download()->assertOk();
        self::assertCount(3, iterator_to_array((new CsvReader)->records($response->baseResponse->getFile()->getPathname())));
    }

    public function test_archived_versions_cannot_be_exported(): void
    {
        $this->source('mric');
        $this->version->update(['status' => 'archived']);
        $this->assertBlocked('version_id', 'publication_old_version');
    }

    private function assertBlocked(string $field, string $key): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('exports.download'), $this->parameters())
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        self::assertStringContainsString(__('ui.'.$key), implode(' ', $response->json('errors.'.$field)));
        self::assertDatabaseMissing('audit_events', ['action' => 'export.downloaded']);
        self::assertDatabaseCount('publications', 0);
        $this->assertNoExportArtifacts();
    }

    private function assertNoExportArtifacts(): void
    {
        $path = storage_path('app/private/exports');
        self::assertSame([], is_dir($path) ? File::allFiles($path) : []);
    }

    private function download(string $type = 'mric', ?int $organizationId = null): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('exports.download'), $this->parameters($type, $organizationId));
    }

    private function parameters(string $type = 'mric', ?int $organizationId = null): array
    {
        return ['version_id' => $this->version->id, 'type' => $type, 'organization_id' => $organizationId ?? $this->organization->id];
    }

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => bin2hex(random_bytes(5)).'@example.test', 'password' => 'Long-test-password-123',
            'roles' => [$role], 'languages' => ['fr'], 'organization_id' => $this->organization->id, 'active' => true]);
    }

    /** Extend an isolated synthetic original and its provenance with batched inserts. */
    private function appendSyntheticRows(ImportBatch $source, Term $firstTerm, int $recordCount): array
    {
        $format = FormatRegistry::get('mric');
        $original = iterator_to_array((new CsvReader)->records($source->originalPath()));
        $row = $original[1]->associative($format->headers);
        $bytes = file_get_contents($source->originalPath());
        $keys = [$row['Name']];
        $termTemplate = $firstTerm->getAttributes();
        unset($termTemplate['id']);
        $revisionTemplate = $firstTerm->revisions()->firstOrFail()->getAttributes();
        unset($revisionTemplate['id']);
        $termRows = $revisionRows = $presenceRows = [];
        $nextId = (int) DB::table('terms')->max('id') + 1;
        for ($number = 2; $number <= $recordCount; $number++) {
            $row['Name'] = 'synthetic-volume-'.$source->id.'-'.$number;
            $keys[] = $row['Name'];
            $raw = implode(';', array_map(fn ($value) => '"'.str_replace('"', '""', Encoding::encode($value)).'"', array_values($row)))."\r\n";
            $offset = strlen($bytes);
            $bytes .= $raw;
            $id = $nextId++;
            $key = $format->identity($row);
            $hash = $format->identityHash($row);
            $termRows[] = [...$termTemplate, 'id' => $id, 'identity_hash' => $hash, 'raw_identity_hash' => $hash,
                'key' => json_encode($key, JSON_THROW_ON_ERROR), 'label' => $row['Name'], 'semantic_hash' => $format->semanticHash($row),
                'source_offset' => $offset, 'source_length' => strlen($raw), 'validated' => '[]'];
            $revisionRows[] = [...$revisionTemplate, 'term_id' => $id, 'source_offset' => $offset,
                'source_length' => strlen($raw), 'validated' => '[]',
                'metadata' => json_encode(['key' => $key, 'organization_id' => null], JSON_THROW_ON_ERROR)];
            $presenceRows[] = ['import_id' => $source->id, 'record_number' => $number, 'offset' => $offset, 'length' => strlen($raw),
                'identity_hash' => $hash, 'term_id' => $id, 'collision' => false, 'category' => 'new'];
        }
        foreach (array_chunk($termRows, 300) as $chunk) {
            DB::table('terms')->insert($chunk);
        }
        foreach (array_chunk($revisionRows, 300) as $chunk) {
            DB::table('revisions')->insert($chunk);
        }
        foreach (array_chunk($presenceRows, 300) as $chunk) {
            DB::table('import_rows')->insert($chunk);
        }
        file_put_contents($source->originalPath(), $bytes);
        $source->update(['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'row_count' => $recordCount, 'analysis_offset' => strlen($bytes)]);

        return $keys;
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
        $term = $existing ?? Term::create(['type' => $type, 'identity_hash' => $format->identityHash($row), 'key' => $key,
            'label' => implode(' / ', $key), 'context' => '', 'source_text' => 'Quelle {}', 'search_text' => 'Quelle {}',
            'semantic_hash' => $format->semanticHash($row), 'source_hash' => $format->sourceHash($row), 'source_import_id' => $source->id,
            'source_offset' => $record->offset, 'source_length' => $record->length, 'validated' => $validated, 'review_needed' => [],
            'revision_no' => 1, 'lock_version' => 1, 'obsolete' => false, 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
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
