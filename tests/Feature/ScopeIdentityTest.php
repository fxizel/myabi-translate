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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_an_exact_identity_in_the_target_scope_blocks_without_business_writes(): void
    {
        $this->import([['Name' => 'Exact key']]);
        $term = Term::firstOrFail();
        $this->variant($term, $this->manager->organization_id);
        $before = $term->getRawOriginal();
        $revisions = Revision::count();
        $generation = DB::table('catalogue_state')->where('id', 1)->value('generation');

        $this->changeScope($term, $this->manager->organization_id)->assertSessionHasErrors('scope');

        self::assertSame($before, $term->fresh()->getRawOriginal());
        self::assertSame($revisions, Revision::count());
        self::assertSame($generation, DB::table('catalogue_state')->where('id', 1)->value('generation'));
        self::assertSame(0, DB::table('audit_events')->where('action', 'term.scope')->count());
    }

    public function test_confirming_a_terms_existing_scope_does_not_conflict_with_itself(): void
    {
        $this->import([['Name' => 'Self']]);
        $term = Term::firstOrFail();

        $this->changeScope($term, null)->assertSessionHasNoErrors();

        self::assertNull($term->fresh()->organization_id);
        self::assertTrue($term->fresh()->scope_overridden);
        self::assertSame(2, $term->fresh()->revision_no);
    }

    public function test_case_and_leading_or_trailing_spaces_remain_distinct_in_one_scope(): void
    {
        $keys = ['Clé', 'clé', 'Clé ', ' Clé'];
        $this->import(array_map(fn ($key) => ['Name' => $key], $keys));
        $terms = Term::orderBy('id')->get();

        foreach ($terms as $term) {
            $this->changeScope($term, $this->manager->organization_id)->assertSessionHasNoErrors();
        }

        self::assertSame(array_map(fn ($key) => [$key], $keys), Term::orderBy('id')->pluck('key')->all());
        self::assertSame(4, Term::where('organization_id', $this->manager->organization_id)->count());
        self::assertSame(4, DB::table('audit_events')->where('action', 'term.scope')->count());
    }

    public function test_private_variants_only_conflict_in_the_requested_target_scope(): void
    {
        $this->import([['Name' => 'Private variant']]);
        $term = Term::firstOrFail();
        $other = Organisation::create(['name' => 'Fribourg', 'code' => 'FR', 'active' => true]);
        $variant = $this->variant($term, $other->id);
        $variant->update(['raw_identity_hash' => null]);

        $this->changeScope($term, $this->manager->organization_id)->assertSessionHasNoErrors();
        self::assertSame($this->manager->organization_id, $term->fresh()->organization_id);
        self::assertSame($other->id, $variant->fresh()->organization_id);

        $this->changeScope($term->fresh(), $other->id)->assertSessionHasErrors('scope');
        self::assertSame($this->manager->organization_id, $term->fresh()->organization_id);
        self::assertSame(2, Term::count());
    }

    #[DataProvider('missingRawHashes')]
    public function test_missing_raw_hashes_do_not_bypass_exact_scope_conflicts(bool $currentMissing, bool $candidateMissing): void
    {
        $this->import([['Name' => 'Clé historique']]);
        $term = Term::firstOrFail();
        $candidate = $this->variant($term, $this->manager->organization_id);
        if ($currentMissing) {
            $term->update(['raw_identity_hash' => null]);
        }
        if ($candidateMissing) {
            $candidate->update(['raw_identity_hash' => null]);
        }

        $this->changeScope($term, $this->manager->organization_id)->assertSessionHasErrors('scope');

        self::assertNull($term->fresh()->organization_id);
        self::assertSame(1, $term->fresh()->revision_no);
        self::assertSame($currentMissing, $term->fresh()->raw_identity_hash === null);
        self::assertSame($candidateMissing, $candidate->fresh()->raw_identity_hash === null);
    }

    public static function missingRawHashes(): array
    {
        return ['current' => [true, false], 'candidate' => [false, true], 'both' => [true, true]];
    }

    public function test_matching_raw_and_scoped_digests_do_not_merge_distinct_exact_keys(): void
    {
        $this->import([['Name' => 'First exact key'], ['Name' => 'Different exact key']]);
        [$term, $candidate] = Term::orderBy('id')->get()->all();
        $candidate->update(['organization_id' => $this->manager->organization_id,
            'raw_identity_hash' => $term->raw_identity_hash, 'identity_hash' => $this->scopedHash($term, $this->manager->organization_id)]);

        $this->changeScope($term, $this->manager->organization_id)->assertSessionHasNoErrors();

        self::assertSame(2, Term::count());
        self::assertSame(['First exact key'], $term->fresh()->key);
        self::assertSame(['Different exact key'], $candidate->fresh()->key);
        self::assertSame($candidate->identity_hash, $term->fresh()->identity_hash);
        self::assertSame($this->manager->organization_id, $term->fresh()->organization_id);
    }

    public function test_another_type_cannot_block_the_same_exact_key_even_with_matching_digests(): void
    {
        $this->import([['Name' => 'Shared key']], 'server');
        $this->import([['Name' => 'Shared key']], 'mric');
        $term = Term::where('type', 'server')->firstOrFail();
        $candidate = Term::where('type', 'mric')->firstOrFail();
        $candidate->update(['organization_id' => $this->manager->organization_id,
            'raw_identity_hash' => $term->raw_identity_hash, 'identity_hash' => $this->scopedHash($term, $this->manager->organization_id)]);

        $this->changeScope($term, $this->manager->organization_id)->assertSessionHasNoErrors();

        self::assertSame(2, Term::count());
        self::assertSame('server', $term->fresh()->type);
        self::assertSame('mric', $candidate->fresh()->type);
        self::assertSame($this->manager->organization_id, $term->fresh()->organization_id);
    }

    public function test_confirmed_common_scope_preserves_identity_provenance_and_revisions_on_reimport(): void
    {
        $rows = [['Form Type' => 'Form', 'Form Template Name' => 'VD_Form', 'Control Name' => 'Control', 'Column Name' => 'Caption',
            'Default Language' => 'de_CH', 'Default Label' => 'Quelle {}', 'Language 1' => 'fr_CH', 'Translation 1' => 'Traduction {}']];
        $original = $this->import($rows, 'form');
        $term = Term::firstOrFail();
        app(WorkflowService::class)->decide($this->manager, Proposal::firstOrFail(), 'validate', 1);
        $term->refresh();
        $rawHash = $term->raw_identity_hash;
        $initialHash = $term->identity_hash;
        $initialPresence = (array) DB::table('import_rows')->where('import_id', $original->id)->first();
        $priorRevisions = Revision::orderBy('id')->get()->map(fn ($revision) => $revision->getRawOriginal())->all();
        $validated = $term->validated;

        $this->changeScope($term, null)->assertSessionHasNoErrors();
        $term->refresh();
        self::assertNotSame($initialHash, $term->identity_hash);
        self::assertSame($rawHash, $term->raw_identity_hash);
        $scopeRevision = $term->revisions()->where('number', $term->revision_no)->firstOrFail();
        self::assertSame('scope', $scopeRevision->origin);
        self::assertNull($scopeRevision->metadata['organization_id']);
        self::assertSame($term->key, $scopeRevision->metadata['key']);
        self::assertSame($validated, $scopeRevision->validated);
        self::assertSame($original->id, $scopeRevision->source_import_id);
        $snapshot = $scopeRevision->getRawOriginal();
        $revisionCount = Revision::count();

        $sameSource = $this->import($rows, 'form');
        $otherSource = $this->import($rows, 'form', 'organization', $this->manager->organization_id);

        self::assertSame(1, Term::count());
        self::assertNull($term->fresh()->organization_id);
        self::assertTrue($term->fresh()->scope_overridden);
        self::assertFalse($term->fresh()->scope_unconfirmed);
        self::assertSame($validated, $term->fresh()->validated);
        self::assertSame($rawHash, $term->fresh()->raw_identity_hash);
        self::assertSame($revisionCount, Revision::count());
        self::assertSame($priorRevisions, Revision::where('number', '<', $scopeRevision->number)->orderBy('id')->get()->map(fn ($revision) => $revision->getRawOriginal())->all());
        self::assertSame($snapshot, $scopeRevision->fresh()->getRawOriginal());
        self::assertSame($initialPresence, (array) DB::table('import_rows')->where('import_id', $original->id)->first());
        foreach ([$sameSource, $otherSource] as $reimport) {
            $presence = DB::table('import_rows')->where('import_id', $reimport->id)->first();
            self::assertSame($term->id, $presence->term_id);
            self::assertSame($rawHash, $presence->raw_identity_hash);
            self::assertSame($term->identity_hash, $presence->identity_hash);
            self::assertSame(1, $reimport->report['unchanged']);
        }
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

    private function changeScope(Term $term, ?int $owner): TestResponse
    {
        return $this->from('/terms/'.$term->id)->post(route('terms.scope', $term), [
            'organization_id' => $owner, 'lock_version' => $term->lock_version,
        ])->assertRedirect('/terms/'.$term->id);
    }

    private function variant(Term $term, int $owner): Term
    {
        $variant = $term->replicate();
        $variant->fill(['organization_id' => $owner, 'identity_hash' => $this->scopedHash($term, $owner), 'scope_overridden' => true]);
        $variant->save();

        return $variant;
    }

    private function scopedHash(Term $term, ?int $owner): string
    {
        return hash('sha256', json_encode([$term->type, $owner, $term->key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
