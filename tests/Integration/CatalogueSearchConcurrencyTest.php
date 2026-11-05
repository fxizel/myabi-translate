<?php

namespace Tests\Integration;

use App\Models\Term;
use App\Models\User;
use App\Services\CatalogueSearch\Budget;
use App\Services\CatalogueSearch\Projection;
use App\Services\CatalogueSearch\Search;
use App\Services\CatalogueSearch\SqlSpool;
use App\Services\SourceRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Illuminate\Support\Facades\DB;
use Tests\Support\DatabaseProcess;
use Tests\Support\GuardedSchemaReset;
use Tests\TestCase;

class CatalogueSearchConcurrencyTest extends TestCase
{
    use CanConfigureMigrationCommands;
    use GuardedSchemaReset;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MYABI_ISOLATED_TESTS') !== '1' || ! in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Requires an explicitly isolated MariaDB 11.4 instance.');
        }
        $this->resetIsolatedTestSchema();
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturn(['label' => ['reference' => 'Bezeichnung', 'reference_language' => 'de', 'translations' => [], 'columns' => ['fr' => 'fr_CH']]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_snapshot_reader_does_not_block_a_committed_indexed_writer(): void
    {
        $user = User::factory()->create(['roles' => ['reader']]);
        $term = $this->term($user);
        $neutral = $term->getRawOriginal();
        unset($neutral['id']);
        $neutral['validated'] = json_encode(['label' => ['fr' => 'Archive']]);
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [...$neutral, 'identity_hash' => hash('sha256', 'neutral-'.$i), 'label' => 'neutral-'.$i];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('terms')->insert($batch);
        }
        app(Projection::class)->rebuild();
        $release = tempnam(sys_get_temp_dir(), 'myabi-cs-release-');
        unlink($release);
        $reader = DatabaseProcess::start(<<<'PHP'
            \Illuminate\Support\Facades\DB::transaction(function () use ($parameters) {
                \Illuminate\Support\Facades\DB::table('catalogue_search_state')->first();
                echo "SNAPSHOT_READY\n"; flush();
                $end = microtime(true) + 10;
                while (! is_file($parameters['release']) && microtime(true) < $end) { usleep(10000); clearstatcache(); }
                $user = \App\Models\User::findOrFail($parameters['user']);
                $result = app(\App\Services\CatalogueSearch\Search::class)->search(\App\Models\Term::visibleTo($user), ['q'=>'dosier','language'=>'fr'], 1, hrtime(true)/1e9);
                echo json_encode(['status'=>$result['status'],'ids'=>$result['ids']->all()]), "\n";
            });
            PHP, ['release' => $release, 'user' => $user->id]);
        $this->workers[] = $reader;
        try {
            DatabaseProcess::awaitOutput($reader, 'SNAPSHOT_READY');
            $start = microtime(true);
            DB::transaction(function () use ($term) {
                DB::table('terms')->where('id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Archive']])]);
                app(Projection::class)->syncMany(collect([$term->fresh()]));
            });
            self::assertLessThan(2, microtime(true) - $start, 'A reader must not hold a long global shared lock.');
            touch($release);
            self::assertSame(0, $reader->wait(), $reader->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($reader->getOutput()))));
            self::assertSame(['status' => 'complete', 'ids' => [$term->id]], json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR));
            $fresh = app(Search::class)->search(Term::visibleTo($user), ['q' => 'dosier', 'language' => 'fr'], 1, hrtime(true) / 1e9);
            self::assertSame([], $fresh['ids']->all());
        } finally {
            if (is_file($release)) {
                unlink($release);
            }
        }
    }

    public function test_committed_sql_write_invalidates_approximation_until_explicit_sync(): void
    {
        $user = User::factory()->create(['roles' => ['reader']]);
        $term = $this->term($user);
        app(Projection::class)->rebuild();
        $writer = DatabaseProcess::start(<<<'PHP'
            \Illuminate\Support\Facades\DB::transaction(fn () => \Illuminate\Support\Facades\DB::table('terms')->where('id',$parameters['term'])->update(['validated'=>json_encode(['label'=>['fr'=>'Dossier nouveau']])]));
            PHP, ['term' => $term->id]);
        $this->workers[] = $writer;
        self::assertSame(0, $writer->wait(), $writer->getErrorOutput());
        $result = app(Search::class)->search(Term::visibleTo($user), ['q' => 'nouveau', 'language' => 'fr'], 1, hrtime(true) / 1e9);
        self::assertSame('unavailable', $result['status']);
        self::assertSame([$term->id], $result['ids']->all(), 'Dirty IDs must bypass an outdated ordinary superset projection.');
        app(Projection::class)->syncMany(collect([$term->fresh()]));
        self::assertFalse(DB::table('catalogue_search_dirty')->exists());
        self::assertSame('complete', app(Search::class)->search(Term::visibleTo($user), ['q' => 'dosier', 'language' => 'fr'], 1, hrtime(true) / 1e9)['status']);
    }

    public function test_sql_cancellation_is_early_and_does_not_leak_session_timeout(): void
    {
        $before = (float) DB::selectOne('SELECT @@max_statement_time AS timeout')->timeout;
        config(['catalogue-search.statement_seconds' => .05]);
        $start = microtime(true);
        try {
            (new Budget)->sql(fn () => DB::select('SELECT SLEEP(2)'));
            self::fail('A deliberately slow statement must be interrupted.');
        } catch (QueryException $error) {
            self::assertSame(1969, (int) $error->errorInfo[1]);
        }
        self::assertLessThan(1, microtime(true) - $start);
        self::assertSame($before, (float) DB::selectOne('SELECT @@max_statement_time AS timeout')->timeout);
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS ok')->ok);
        $buffered = DB::connection()->getPdo()->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try {
            SqlSpool::read(DB::query()->selectRaw('SLEEP(2) AS id'), 8, fn ($row) => pack('P', $row['id']), new Budget);
            self::fail('The streaming read must obey the same SQL limit.');
        } catch (QueryException $error) {
            self::assertSame(1969, (int) $error->errorInfo[1]);
        }
        self::assertSame($buffered, DB::connection()->getPdo()->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
        self::assertSame($before, (float) DB::selectOne('SELECT @@max_statement_time AS timeout')->timeout);
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS ok')->ok);
    }

    public function test_snapshot_overrides_read_committed_without_changing_the_session_default(): void
    {
        $user = User::factory()->create(['roles' => ['reader']]);
        $term = $this->term($user);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            app(Search::class)->snapshot(function () use ($term) {
                $before = DB::table('catalogue_search_state')->value('mutation_no');
                $writer = DatabaseProcess::start(<<<'PHP'
                    \Illuminate\Support\Facades\DB::table('terms')->where('id',$parameters['term'])->update(['validated'=>json_encode(['label'=>['fr'=>'Archive']])]);
                    PHP, ['term' => $term->id]);
                $this->workers[] = $writer;
                self::assertSame(0, $writer->wait(), $writer->getErrorOutput());
                self::assertSame($before, DB::table('catalogue_search_state')->value('mutation_no'));
            });
            self::assertSame('READ-COMMITTED', DB::selectOne('SELECT @@tx_isolation AS isolation')->isolation);
            self::assertSame('Archive', $term->fresh()->validated['label']['fr']);
        } finally {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
    }

    private function term(User $user): Term
    {
        return Term::create(['type' => 'server', 'identity_hash' => hash('sha256', 'synthetic'), 'key' => ['synthetic'], 'label' => 'synthetic', 'context' => '',
            'source_text' => 'Bezeichnung', 'search_text' => 'Bezeichnung Dossier', 'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1, 'validated' => ['label' => ['fr' => 'Dossier']], 'review_needed' => [],
            'created_by' => $user->id, 'updated_by' => $user->id]);
    }
}
