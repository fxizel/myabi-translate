<?php

namespace Tests\Feature;

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CommonTermTransition;
use App\Services\SourceRecord;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\GuardedSchemaReset;
use Tests\TestCase;

class CommonTermTransitionTest extends TestCase
{
    use GuardedSchemaReset;
    use RefreshDatabase {
        beginDatabaseTransaction as private beginTransactionalTestDatabase;
    }

    private string $privateStorage;

    private User $author;

    private MyabiVersion $version;

    private int $organizationId;

    protected function beforeRefreshingDatabase(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Check isolation before RefreshDatabase can run migrate:fresh.
            $this->assertIsolatedTestDatabase();
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function beginDatabaseTransaction(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->beginTransactionalTestDatabase();

            return;
        }
        // MariaDB ALTER statements commit implicitly. A wrapping test
        // transaction would turn group transactions into invalid savepoints.
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
            DB::disconnect();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().'/myabi-common-transition-'.bin2hex(random_bytes(8));
        mkdir($this->privateStorage.'/app/private', 0700, true);
        $this->app->useStoragePath($this->privateStorage);
        $this->author = User::factory()->create();
        $this->organizationId = Organisation::create(['code' => 'SYN', 'name' => 'Synthetic source', 'active' => true])->id;
        $this->version = MyabiVersion::create(['number' => '2026.synthetic', 'released_at' => '2026-10-13',
            'status' => 'current', 'created_by' => $this->author->id, 'updated_by' => $this->author->id]);
        // Represent an installation immediately before the transition; no real database is used.
        Schema::table('terms', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->boolean('scope_unconfirmed')->default(false);
            $table->boolean('scope_overridden')->default(false);
        });
        DB::table('common_term_transition')->delete();
    }

    protected function tearDown(): void
    {
        if (isset($this->privateStorage) && dirname($this->privateStorage) === str_replace('\\', '/', sys_get_temp_dir())
            && str_starts_with(basename($this->privateStorage), 'myabi-common-transition-')) {
            File::deleteDirectory($this->privateStorage);
        }
        parent::tearDown();
    }

    public function test_diagnostic_is_read_only_includes_missing_hashes_and_checks_exact_keys(): void
    {
        $base = $this->term('Élément', 'Reference {}', ['label' => ['fr' => 'Base {}']]);
        $other = $this->term('Élément', 'Reference {}', ['label' => ['fr' => 'Divergence {}']]);
        $distinct = $this->term('Élément ', 'Reference {}');
        DB::table('terms')->where('id', $base)->update(['raw_identity_hash' => null]);
        DB::table('terms')->where('id', $distinct)->update(['raw_identity_hash' => DB::table('terms')->where('id', $other)->value('raw_identity_hash')]);
        $before = DB::table('terms')->get()->all();
        $reports = [];
        $counts = app(CommonTermTransition::class)->diagnose(function ($group) use (&$reports) {
            $reports[] = $group;
        });

        self::assertSame(1, $counts['missing_hashes']);
        self::assertSame(1, $counts['compatible_groups']);
        self::assertSame(0, $counts['blocked_groups']);
        self::assertSame(1, $counts['divergent_cells']);
        self::assertSame([$base, $other], $reports[0]['term_ids']);
        self::assertEquals($before, DB::table('terms')->get()->all());
        self::assertSame(0, DB::table('term_consolidations')->count());
    }

    public function test_diagnostic_temporary_candidates_do_not_commit_the_callers_transaction(): void
    {
        $this->term('Shared', 'Reference {}');
        $this->term('Shared', 'Reference {}');
        $generation = DB::table('catalogue_state')->where('id', 1)->value('generation');
        $transactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            DB::table('catalogue_state')->where('id', 1)->increment('generation', 100);
            self::assertSame(1, app(CommonTermTransition::class)->diagnose()['compatible_groups']);
            self::assertTrue(DB::connection()->getPdo()->inTransaction());
            self::assertSame($transactionLevel + 1, DB::transactionLevel());
        } finally {
            while (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }
        }
        self::assertSame($generation, DB::table('catalogue_state')->where('id', 1)->value('generation'));
        self::assertSame(0, DB::table('term_consolidations')->count());
    }

    public function test_consolidation_preserves_first_values_decisions_revisions_and_frozen_publications(): void
    {
        $base = $this->term('Identité', 'Reference {}', ['label' => ['fr' => 'Première {}']]);
        $other = $this->term('Identité', 'Reference {}', ['label' => ['fr' => 'Deuxième {}', 'it' => 'Seconda {}']]);
        $oldRevisions = DB::table('revisions')->get()->all();
        $historicalDecision = $this->proposal($other, 'Deuxième {}', 'validated');
        $pending = $this->proposal($base, 'Deuxième {}');
        $publication = DB::table('publications')->insertGetId(['version_id' => $this->version->id, 'number' => 'frozen-synthetic',
            'status' => 'published', 'types' => '["server"]', 'recipients' => '[]', 'manifest' => '{"immutable":"synthetic"}',
            'created_by' => $this->author->id, 'updated_by' => $this->author->id, 'created_at' => now(), 'updated_at' => now()]);
        $frozen = DB::table('publications')->where('id', $publication)->first();

        app(CommonTermTransition::class)->consolidate();

        self::assertSame(1, Term::count());
        self::assertSame($base, Term::firstOrFail()->id);
        self::assertSame(['label' => ['fr' => 'Première {}']], Term::firstOrFail()->validated);
        self::assertSame($base, DB::table('terms')->where('id', $other)->value('consolidated_into_id'));
        self::assertEquals($oldRevisions, DB::table('revisions')->get()->all());
        self::assertSame('validated', DB::table('proposals')->where('id', $historicalDecision)->value('status'));
        self::assertSame($other, DB::table('proposals')->where('id', $historicalDecision)->value('legacy_term_id'));
        self::assertSame($pending, DB::table('common_term_recovered_values')->where('legacy_term_id', $other)->where('language', 'fr')->value('proposal_id'));
        self::assertSame(1, DB::table('proposals')->where('term_id', $base)->where('language', 'it')->where('status', 'pending')->count());
        self::assertSame(2, DB::table('import_rows')->where('term_id', $base)->count());
        self::assertFalse(DB::table('term_search')->where('term_id', $other)->exists());
        self::assertEquals($frozen, DB::table('publications')->where('id', $publication)->first());
        $before = DB::table('proposals')->count();
        app(CommonTermTransition::class)->consolidate();
        self::assertSame($before, DB::table('proposals')->count());
        self::assertSame(1, DB::table('term_consolidations')->count());
    }

    public function test_incompatible_references_block_before_any_business_change(): void
    {
        $this->term('Ambiguous', 'One {}');
        $this->term('Ambiguous', 'Another {}');
        $before = DB::table('terms')->get()->all();
        self::assertSame(1, app(CommonTermTransition::class)->diagnose()['blocked_groups']);
        try {
            app(CommonTermTransition::class)->consolidate();
            self::fail('Ambiguous references must block.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ambiguous identity', $error->getMessage());
        }
        self::assertEquals($before, DB::table('terms')->get()->all());
        self::assertSame(0, DB::table('term_consolidations')->count());
    }

    public function test_transition_resumes_after_a_committed_group_without_duplicate_proposals(): void
    {
        foreach (['A', 'B'] as $key) {
            $this->term($key, 'Reference {}', ['label' => ['fr' => 'Base {}']]);
            $this->term($key, 'Reference {}', ['label' => ['fr' => 'Autre {}']]);
        }
        $interrupting = new class extends CommonTermTransition
        {
            protected function checkpoint(): void
            {
                throw new RuntimeException('Synthetic interruption');
            }
        };
        try {
            $interrupting->consolidate();
            self::fail('Interruption must fire.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic interruption', $error->getMessage());
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            self::assertFalse(DB::connection()->getPdo()->inTransaction(), 'The first group must already be durably committed.');
            DB::disconnect();
        }
        self::assertSame(1, DB::table('term_consolidations')->count());
        self::assertNull(DB::table('common_term_transition')->where('id', 1)->value('completed_at'));
        $result = app(CommonTermTransition::class)->consolidate();
        self::assertSame(2, DB::table('term_consolidations')->count());
        self::assertSame(2, DB::table('proposals')->where('status', 'pending')->count());
        self::assertSame(2, Term::count());
        self::assertSame(2, $result['compatible_groups']);
        self::assertSame(2, $result['consolidated_terms']);
    }

    public function test_mariadb_schema_cleanup_resumes_after_individually_committed_column_changes(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Real MariaDB implicit DDL commits require the isolated MariaDB test configuration.');
        }
        $term = $this->term('Unique', 'Reference {}', ['label' => ['fr' => 'Base {}']]);
        $revisions = DB::table('revisions')->get()->all();
        app(CommonTermTransition::class)->consolidate();
        $counts = DB::table('common_term_transition')->where('id', 1)->value('counts');
        // Model a process interruption after two independent ALTER statements
        // have committed, while the last ownership column and indexes remain.
        Schema::table('terms', fn (Blueprint $table) => $table->dropColumn('organization_id'));
        Schema::table('terms', fn (Blueprint $table) => $table->dropColumn('scope_overridden'));
        Schema::table('terms', fn (Blueprint $table) => $table->dropIndex('terms_consolidated_into_id_index'));
        Schema::table('proposals', fn (Blueprint $table) => $table->dropIndex('proposals_legacy_term_id_index'));
        DB::disconnect();
        self::assertFalse(Schema::hasColumn('terms', 'organization_id'));
        self::assertFalse(Schema::hasColumn('terms', 'scope_overridden'));
        self::assertTrue(Schema::hasColumn('terms', 'scope_unconfirmed'));

        $migration = require database_path('migrations/2026_10_13_120000_transition_to_common_terms.php');
        $migration->up();
        $migration->up();
        self::assertFalse(Schema::hasColumn('terms', 'scope_unconfirmed'));
        self::assertTrue(Schema::hasIndex('terms', 'terms_consolidated_into_id_index'));
        self::assertTrue(Schema::hasIndex('proposals', 'proposals_legacy_term_id_index'));
        self::assertSame($counts, DB::table('common_term_transition')->where('id', 1)->value('counts'));
        self::assertSame(['label' => ['fr' => 'Base {}']], Term::findOrFail($term)->validated);
        self::assertEquals($revisions, DB::table('revisions')->get()->all());
        self::assertSame($this->organizationId, json_decode(DB::table('term_legacy_scopes')->where('term_id', $term)->value('metadata'), true)['organization_id']);
    }

    public function test_schema_cleanup_is_repeatable_preserves_triggers_and_historical_scopes(): void
    {
        $term = $this->term('Unique', 'Reference {}');
        $migration = require database_path('migrations/2026_10_13_120000_transition_to_common_terms.php');
        $migration->up();
        Schema::table('terms', fn (Blueprint $table) => $table->dropIndex('terms_consolidated_into_id_index'));
        Schema::table('proposals', fn (Blueprint $table) => $table->dropIndex('proposals_legacy_term_id_index'));
        Schema::table('term_consolidations', fn (Blueprint $table) => $table->dropIndex('term_consolidations_common_term_id_index'));
        $migration->up();
        self::assertFalse(Schema::hasColumn('terms', 'organization_id'));
        self::assertFalse(Schema::hasColumn('terms', 'scope_unconfirmed'));
        self::assertFalse(Schema::hasColumn('terms', 'scope_overridden'));
        self::assertSame($this->organizationId, json_decode(DB::table('term_legacy_scopes')->where('term_id', $term)->value('metadata'), true)['organization_id']);
        DB::table('terms')->where('id', $term)->update(['search_text' => 'Changed projection', 'validated' => '{"label":{"fr":"Validé {}"}}']);
        self::assertSame('Changed projection', DB::table('term_search')->where('term_id', $term)->value('search_text'));
        self::assertSame(2, DB::table('term_states')->where('id', $term)->value('validated_languages'));
        self::assertTrue(Schema::hasIndex('terms', 'terms_consolidated_into_id_index'));
        self::assertTrue(Schema::hasIndex('proposals', 'proposals_legacy_term_id_index'));
        self::assertTrue(Schema::hasIndex('term_consolidations', 'term_consolidations_common_term_id_index'));
    }

    public function test_private_diagnostic_command_exposes_only_counts(): void
    {
        $this->term('Private key', 'Reference {}');
        $this->term('Private key', 'Reference {}');
        $this->artisan('referentiel:common-terms --diagnose')->expectsOutputToContain('"compatible_groups":1')->assertExitCode(0);
        self::assertSame(2, Term::count());
        $reports = glob($this->privateStorage.'/app/private/reports/*.jsonl');
        self::assertCount(1, $reports);
        self::assertStringContainsString('Private key', file_get_contents($reports[0]));
    }

    public function test_inconsistent_revision_blocks_and_recovery_keeps_null_manual_provenance(): void
    {
        $base = $this->term('Shared', 'Reference {}', ['label' => ['fr' => 'Base {}']]);
        $other = $this->term('Shared', 'Reference {}', ['label' => ['fr' => 'Autre {}']]);
        $decision = $this->proposal($other, 'Autre {}', 'validated');
        DB::table('proposals')->where('id', $decision)->update(['organization_id' => null, 'import_id' => null, 'claimed' => true]);
        DB::table('revisions')->where('term_id', $other)->update(['validated' => '[]']);
        self::assertSame(1, app(CommonTermTransition::class)->diagnose()['blocked_groups']);
        DB::table('revisions')->where('term_id', $other)->update(['validated' => '{"label":{"fr":"Autre {}"}}']);
        app(CommonTermTransition::class)->consolidate();
        $recovered = DB::table('proposals')->where('term_id', $base)->where('status', 'pending')->first();
        self::assertNull($recovered->organization_id);
        self::assertNull($recovered->import_id);
        self::assertSame(1, $recovered->claimed);
        self::assertSame(0, DB::table('proposal_imports')->where('proposal_id', $recovered->id)->count());
        self::assertNotNull($recovered->context_fingerprint);
    }

    public function test_reused_divergence_keeps_the_decisions_primary_import(): void
    {
        $base = $this->term('Shared', 'Reference {}', ['label' => ['fr' => 'Base {}']]);
        $other = $this->term('Shared', 'Reference {}', ['label' => ['fr' => 'Autre {}']]);
        $third = $this->term('Separate source', 'Reference {}');
        $importId = DB::table('terms')->where('id', $third)->value('source_import_id');
        $decision = $this->proposal($other, 'Autre {}', 'validated');
        DB::table('proposals')->where('id', $decision)->update(['import_id' => $importId]);
        $pending = $this->proposal($base, 'Autre {}');
        app(CommonTermTransition::class)->consolidate();
        self::assertTrue(DB::table('proposal_imports')->where('proposal_id', $pending)->where('import_id', $importId)->exists());
        self::assertSame(1, DB::table('proposals')->where('term_id', $base)->where('status', 'pending')->count());
    }

    public function test_completed_campaigns_and_revision_sources_keep_their_historical_lineage(): void
    {
        $base = $this->term('Shared', 'Reference {}');
        $other = $this->term('Shared', 'Reference {}');
        $campaign = DB::table('translation_campaigns')->insertGetId(['title' => 'Frozen synthetic', 'objective' => 'review',
            'language' => 'fr', 'status' => 'completed', 'created_by' => $this->author->id, 'updated_by' => $this->author->id,
            'created_at' => now(), 'updated_at' => now()]);
        $participation = DB::table('campaign_participations')->insertGetId(['translation_campaign_id' => $campaign, 'term_id' => $other,
            'attribute' => 'label', 'status' => 'confirmed', 'confirmation_fingerprint' => str_repeat('c', 64),
            'confirmed_at' => now(), 'confirmed_by' => $this->author->id, 'confirmed_revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('campaign_participations')->where('id', $participation)->first();
        app(CommonTermTransition::class)->consolidate();
        self::assertEquals($before, DB::table('campaign_participations')->where('id', $participation)->first());
        $revision = Revision::where('term_id', $other)->firstOrFail();
        self::assertSame('Reference {}', app(SourceRecord::class)->attributes($revision)['label']['reference']);
        self::assertSame($base, Term::firstOrFail()->id);
    }

    public function test_matching_old_context_is_updated_and_stale_context_stays_stale(): void
    {
        $termId = $this->term('Single', 'Reference {}');
        $term = Term::findOrFail($termId);
        $service = app(CampaignService::class);
        $old = $service->historicalContextFingerprint($term, 'label', 'fr', $this->organizationId);
        $pending = $this->proposal($termId, 'Proposée {}');
        $stale = $this->proposal($termId, 'Ancienne {}');
        DB::table('proposals')->where('id', $pending)->update(['context_fingerprint' => $old]);
        DB::table('proposals')->where('id', $stale)->update(['context_fingerprint' => str_repeat('f', 64)]);
        app(CommonTermTransition::class)->consolidate();
        self::assertSame($service->contextFingerprint($term, 'label', 'fr'), DB::table('proposals')->where('id', $pending)->value('context_fingerprint'));
        self::assertSame(str_repeat('f', 64), DB::table('proposals')->where('id', $stale)->value('context_fingerprint'));
    }

    public function test_open_confirmations_are_preserved_only_when_context_and_value_match(): void
    {
        $termId = $this->term('Single', 'Reference {}', ['label' => ['fr' => 'Base {}']]);
        $term = Term::findOrFail($termId);
        $contexts = app(CampaignService::class);
        $old = hash('sha256', $contexts->historicalContextFingerprint($term, 'label', 'fr', $this->organizationId).'\0'.json_encode('Base {}', JSON_THROW_ON_ERROR));
        $participations = [];
        foreach ([$old, str_repeat('f', 64)] as $fingerprint) {
            $campaign = DB::table('translation_campaigns')->insertGetId(['title' => 'Open synthetic', 'objective' => 'review',
                'language' => 'fr', 'status' => 'in_progress', 'created_by' => $this->author->id, 'updated_by' => $this->author->id,
                'created_at' => now(), 'updated_at' => now()]);
            $participations[] = DB::table('campaign_participations')->insertGetId(['translation_campaign_id' => $campaign, 'term_id' => $termId,
                'attribute' => 'label', 'status' => 'confirmed', 'confirmation_fingerprint' => $fingerprint,
                'confirmed_at' => now(), 'confirmed_by' => $this->author->id, 'confirmed_revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(CommonTermTransition::class)->consolidate();
        self::assertSame('confirmed', DB::table('campaign_participations')->where('id', $participations[0])->value('status'));
        self::assertSame(hash('sha256', $contexts->contextFingerprint($term, 'label', 'fr').'\0'.json_encode('Base {}', JSON_THROW_ON_ERROR)),
            DB::table('campaign_participations')->where('id', $participations[0])->value('confirmation_fingerprint'));
        self::assertSame('to_process', DB::table('campaign_participations')->where('id', $participations[1])->value('status'));
        self::assertSame(1, DB::table('campaign_events')->where('campaign_participation_id', $participations[1])->where('action', 'automatic_reexamination')->count());
    }

    public function test_a_database_failure_does_not_expose_bound_business_values(): void
    {
        $this->term('Shared', 'Reference {}');
        $this->term('Shared', 'Reference {}');
        $interrupting = new class extends CommonTermTransition
        {
            protected function checkpoint(): void
            {
                throw new QueryException('sqlite', 'UPDATE terms SET label = ?', ['Private synthetic value'], new \PDOException('Synthetic failure'));
            }
        };
        try {
            $interrupting->consolidate();
            self::fail('Failure must fire.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('database operation', $error->getMessage());
            self::assertStringNotContainsString('Private synthetic value', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        app(CommonTermTransition::class)->consolidate();
        self::assertSame(1, Term::count());
    }

    private function term(string $key, string $reference, array $validated = []): int
    {
        $format = FormatRegistry::get('server');
        $row = array_fill_keys($format->headers, '');
        $row['Name'] = $key;
        $row['de_CH'] = $reference;
        $row['fr_CH'] = 'Importée {}';
        $encode = fn ($values) => Encoding::encode('"'.implode('";"', array_map(fn ($value) => str_replace('"', '""', $value), $values)).'"')."\r\n";
        $header = $encode($format->headers);
        $raw = $encode(array_values($row));
        $filename = 'synthetic-'.bin2hex(random_bytes(8)).'.csv';
        file_put_contents($this->privateStorage.'/app/private/'.$filename, $header.$raw);
        $import = ImportBatch::create(['version_id' => $this->version->id, 'type' => 'server', 'format_version' => $format->version,
            'organization_id' => $this->organizationId, 'source_kind' => 'organization', 'filename' => $format->filename,
            'path' => $filename, 'sha256' => hash('sha256', $header.$raw), 'size' => strlen($header.$raw), 'status' => 'applied',
            'row_count' => 1, 'created_by' => $this->author->id, 'updated_by' => $this->author->id]);
        $id = DB::table('terms')->insertGetId(['type' => 'server', 'organization_id' => $this->organizationId,
            'identity_hash' => $format->identityHash($row), 'raw_identity_hash' => $format->identityHash($row),
            'key' => json_encode([$key], JSON_THROW_ON_ERROR), 'label' => $key, 'context' => '', 'source_text' => $reference,
            'search_text' => $key, 'source_hash' => $format->sourceHash($row), 'semantic_hash' => $format->semanticHash($row),
            'source_import_id' => $import->id, 'source_offset' => strlen($header), 'source_length' => strlen($raw),
            'validated' => json_encode($validated, JSON_THROW_ON_ERROR), 'review_needed' => '[]',
            'created_by' => $this->author->id, 'updated_by' => $this->author->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('import_rows')->insert(['import_id' => $import->id, 'record_number' => 1, 'offset' => strlen($header), 'length' => strlen($raw),
            'identity_hash' => $format->identityHash($row), 'raw_identity_hash' => $format->identityHash($row), 'term_id' => $id, 'collision' => false]);
        DB::table('revisions')->insert(['term_id' => $id, 'number' => 1, 'version_id' => $this->version->id,
            'source_import_id' => $import->id, 'source_offset' => strlen($header), 'source_length' => strlen($raw), 'validated' => json_encode($validated, JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['organization_id' => $this->organizationId, 'key' => [$key], 'context' => ''], JSON_THROW_ON_ERROR),
            'origin' => 'import', 'origin_id' => $import->id, 'created_by' => $this->author->id, 'created_at' => now()]);

        return $id;
    }

    private function proposal(int $termId, string $value, string $status = 'pending'): int
    {
        return DB::table('proposals')->insertGetId(['term_id' => $termId, 'attribute' => 'label', 'language' => 'fr',
            'value' => $value, 'value_hash' => hash('sha256', $value), 'status' => $status, 'author_id' => $this->author->id,
            'organization_id' => $this->organizationId, 'import_id' => DB::table('terms')->where('id', $termId)->value('source_import_id'),
            'anomalies' => '[]', 'edit_history' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }
}
