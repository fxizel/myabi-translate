<?php

namespace Tests\Feature;

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\ImportService;
use App\Services\WorkflowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScopeIdentityTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStorage;

    private User $manager;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myabi-scope-identity-test-'.bin2hex(random_bytes(8));
        mkdir($this->isolatedStorage.'/app/private', 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
        config(['referentiel.storage_quota' => 2_000_000_000, 'referentiel.external_storage_used' => 0]);
        $organization = Organisation::create(['name' => 'Vaud', 'code' => 'VD', 'active' => true]);
        $this->manager = User::factory()->create(['organization_id' => $organization->id,
            'roles' => ['manager' => ['fr'], 'validator' => ['fr']], 'active' => true]);
        User::factory()->create(['name' => 'Technical import', 'is_technical' => true, 'active' => false]);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current',
            'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $this->withoutMiddleware(RequireTwoFactor::class);
        $this->actingAs($this->manager);
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage) && dirname($this->isolatedStorage) === rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            && str_starts_with(basename($this->isolatedStorage), 'myabi-scope-identity-test-')) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    public function test_organizations_reuse_one_exact_term_and_submit_concurrent_proposals(): void
    {
        $other = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $first = $this->import([['Name' => 'Shared', 'fr_CH' => 'Vaud {}']], 'server', 'organization', $this->manager->organization_id);
        $term = Term::firstOrFail();
        $second = $this->import([['Name' => 'Shared', 'fr_CH' => 'Fribourg {}']], 'server', 'organization', $other->id);
        self::assertSame(1, Term::count());
        self::assertSame(1, Revision::count());
        self::assertSame([], $term->fresh()->validated);
        self::assertSame([$term->id, $term->id], DB::table('import_rows')->orderBy('id')->pluck('term_id')->all());
        self::assertSame([$first->id, $second->id], Proposal::orderBy('id')->pluck('import_id')->all());
        self::assertSame([$this->manager->organization_id, $other->id], Proposal::orderBy('id')->pluck('organization_id')->all());
        app(WorkflowService::class)->decide($this->manager, Proposal::where('import_id', $second->id)->firstOrFail(), 'validate', 1);
        self::assertSame('Fribourg {}', $term->fresh()->validated['label']['fr']);
        self::assertSame('rejected', Proposal::where('import_id', $first->id)->first()->status);
        self::assertSame('superseded', Proposal::where('import_id', $first->id)->first()->rejection_reason);
        self::assertArrayNotHasKey('organization_id', Revision::latest('id')->first()->metadata);
    }

    public function test_identical_pending_value_keeps_all_import_origins_without_duplicate(): void
    {
        $other = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $first = $this->import([['Name' => 'Shared']], 'server', 'organization', $this->manager->organization_id);
        $second = $this->import([['Name' => 'Shared']], 'server', 'organization', $other->id);
        self::assertSame(1, Proposal::count());
        $proposal = Proposal::firstOrFail();
        self::assertSame($first->id, $proposal->import_id);
        self::assertSame($this->manager->organization_id, $proposal->organization_id);
        self::assertSame([$second->id], DB::table('proposal_imports')->where('proposal_id', $proposal->id)->pluck('import_id')->all());
    }

    public function test_claiming_a_proposal_and_moving_its_author_preserves_historical_origin(): void
    {
        $other = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $first = $this->import([['Name' => 'Shared']], 'server', 'organization', $this->manager->organization_id);
        $second = $this->import([['Name' => 'Shared']], 'server', 'organization', $other->id);
        $translator = User::factory()->create(['organization_id' => $other->id, 'roles' => ['translator' => ['fr']]]);
        $proposal = app(WorkflowService::class)->edit($translator, Proposal::firstOrFail(), 'Correction {}', 1);
        $translator->update(['organization_id' => $this->manager->organization_id]);
        self::assertSame($this->manager->organization_id, $proposal->fresh()->organization_id);
        self::assertSame($translator->id, $proposal->author_id);
        self::assertSame('Traduction {}', $proposal->edit_history[0]['value']);
        self::assertSame([$first->id, $second->id], $proposal->edit_history[0]['import_ids']);
        self::assertSame($this->manager->organization_id, $proposal->edit_history[0]['organization_id']);
        self::assertSame($first->id, $proposal->import_id);
    }

    public function test_case_spaces_and_empty_allowed_components_remain_exact(): void
    {
        $keys = ['Clé', 'clé', 'Clé ', ' Clé'];
        $this->import(array_map(fn ($key) => ['Name' => $key], $keys));
        self::assertSame(array_map(fn ($key) => [$key], $keys), Term::orderBy('id')->pluck('key')->all());
        $row = ['Form Type' => 'Form', 'Form Template Name' => 'VD_Form', 'Control Name' => '', 'Column Name' => 'Caption',
            'Default Language' => 'de_CH', 'Default Label' => 'Quelle {}', 'Language 1' => 'fr_CH', 'Translation 1' => 'Traduction {}'];
        $this->import([$row], 'form');
        $this->import([$row], 'form', 'organization', $this->manager->organization_id);
        self::assertSame(1, Term::where('type', 'form')->count());
        self::assertSame(['Form', 'VD_Form', '', 'Caption'], Term::where('type', 'form')->first()->key);
    }

    public function test_historical_missing_hash_reuses_unicode_exact_key(): void
    {
        $this->import([['Name' => 'Clé historique']]);
        $term = Term::firstOrFail();
        $term->update(['raw_identity_hash' => null]);
        $this->import([['Name' => 'Clé historique']], 'server', 'organization', $this->manager->organization_id);
        self::assertSame(1, Term::count());
        self::assertSame(1, DB::table('import_rows')->distinct()->count('term_id'));
    }

    public function test_unconsolidated_exact_duplicates_block_instead_of_source_preference(): void
    {
        $this->import([['Name' => 'Ambiguous']]);
        $term = Term::firstOrFail();
        $copy = $term->replicate();
        $copy->raw_identity_hash = null;
        $copy->save();
        $revisions = Revision::count();
        $generation = DB::table('catalogue_state')->where('id', 1)->value('generation');
        try {
            $this->import([['Name' => 'Ambiguous']], 'server', 'organization', $this->manager->organization_id);
            self::fail('Ambiguous current terms must require consolidation.');
        } catch (\RuntimeException $error) {
            self::assertSame('Conflicting current exact identities; consolidation required.', $error->getMessage());
        }
        self::assertSame($revisions, Revision::count());
        self::assertSame($generation, DB::table('catalogue_state')->where('id', 1)->value('generation'));
    }

    public function test_digest_collision_does_not_merge_distinct_keys_during_creation(): void
    {
        $format = FormatRegistry::get('server');
        $path = $this->isolatedStorage.'/collision.csv';
        $stream = fopen($path, 'wb');
        foreach ([$format->headers, array_map(fn ($col) => $col === 'Name' ? 'First' : '', $format->headers), array_map(fn ($col) => $col === 'Name' ? 'Different' : '', $format->headers)] as $values) {
            fwrite($stream, Encoding::encode('"'.implode('";"', $values).'"')."\r\n");
        }
        fclose($stream);
        $service = app(ImportService::class);
        $import = $service->receive($path, $format->filename, ['type' => 'server', 'version_id' => $this->version->id, 'source_kind' => 'publisher'], $this->manager);
        $service->analyze($import);
        $hash = DB::table('import_rows')->where('import_id', $import->id)->value('raw_identity_hash');
        DB::table('import_rows')->where('import_id', $import->id)->update(['raw_identity_hash' => $hash, 'identity_hash' => $hash]);
        $service->apply($import->fresh());
        self::assertSame([['First'], ['Different']], Term::orderBy('id')->pluck('key')->all());
        self::assertSame(2, Revision::count());
        self::assertSame(2, DB::table('import_rows')->where('import_id', $import->id)->distinct()->count('term_id'));
    }

    public function test_type_is_part_of_exact_identity_even_with_matching_digests(): void
    {
        $this->import([['Name' => 'Shared']], 'server');
        $this->import([['Name' => 'Shared']], 'mric');
        $server = Term::where('type', 'server')->firstOrFail();
        $mric = Term::where('type', 'mric')->firstOrFail();
        $mric->update(['identity_hash' => $server->identity_hash, 'raw_identity_hash' => $server->raw_identity_hash]);
        $this->import([['Name' => 'Shared']], 'server');
        self::assertSame(2, Term::count());
        self::assertSame($server->id, DB::table('import_rows')->latest('id')->value('term_id'));
    }

    public function test_index_migration_resumes_from_partial_states_without_changing_columns_or_data(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL idempotence is isolated to transactional SQLite tests.');
        }
        $this->import([['Name' => 'Migration data']]);
        Term::firstOrFail()->update(['last_publication_id' => 47, 'last_published_revision' => 1]);
        $terms = DB::table('terms')->get()->map(fn ($row) => (array) $row)->all();
        $presences = DB::table('import_rows')->get()->map(fn ($row) => (array) $row)->all();
        $columns = Schema::getColumnListing('terms');
        $migration = require database_path('migrations/2026_09_21_240000_remove_redundant_term_indexes.php');
        $indexes = ['identity_hash' => 'terms_identity_hash_index', 'last_publication_id' => 'terms_last_publication_id_index'];

        foreach ([array_keys($indexes), ['identity_hash'], ['last_publication_id'], []] as $present) {
            foreach ($indexes as $column => $name) {
                if (in_array($column, $present, true) && ! Schema::hasIndex('terms', $name)) {
                    Schema::table('terms', fn (Blueprint $table) => $table->index($column, $name));
                } elseif (! in_array($column, $present, true) && Schema::hasIndex('terms', $name)) {
                    Schema::table('terms', fn (Blueprint $table) => $table->dropIndex($name));
                }
            }
            $migration->up();
            $migration->up();

            foreach ($indexes as $name) {
                self::assertFalse(Schema::hasIndex('terms', $name));
            }
            self::assertTrue(Schema::hasIndex('terms', 'terms_raw_identity_hash_index'));
            self::assertTrue(Schema::hasIndex('terms', 'terms_context_index'));
            self::assertTrue(Schema::hasIndex('import_rows', 'import_rows_term_id_index'));
            self::assertSame($columns, Schema::getColumnListing('terms'));
            self::assertSame($terms, DB::table('terms')->get()->map(fn ($row) => (array) $row)->all());
            self::assertSame($presences, DB::table('import_rows')->get()->map(fn ($row) => (array) $row)->all());
        }
    }

    private function import(array $rows, string $type = 'server', string $sourceKind = 'publisher', ?int $organizationId = null): ImportBatch
    {
        $format = FormatRegistry::get($type);
        $path = $this->isolatedStorage.'/input-'.bin2hex(random_bytes(8)).'.csv';
        $stream = fopen($path, 'wb');
        $write = function (array $values) use ($stream): void {
            fwrite($stream, Encoding::encode('"'.implode('";"', array_map(fn ($value) => str_replace('"', '""', $value), $values)).'"')."\r\n");
        };
        $write($format->headers);
        foreach ($rows as $row) {
            $row += ['de_CH' => 'Quelle {}', 'fr_CH' => 'Traduction {}'];
            $write(array_map(fn ($column) => $row[$column] ?? '', $format->headers));
        }
        fclose($stream);
        $service = app(ImportService::class);
        $import = $service->receive($path, $format->filename, ['version_id' => $this->version->id, 'type' => $type,
            'source_kind' => $sourceKind, 'organization_id' => $organizationId], $this->manager);
        $service->analyze($import);
        $service->apply($import->fresh());

        return $import->fresh();
    }
}
