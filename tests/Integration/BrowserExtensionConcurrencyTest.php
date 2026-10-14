<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\SearchProjection;
use App\Services\BrowserExtension\SearchService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BrowserExtensionConcurrencyFixture as Fixture;
use Tests\TestCase;

class BrowserExtensionConcurrencyTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MYABI_ISOLATED_TESTS') !== '1'
            || ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires an explicitly isolated portable MariaDB instance.');
        }
        Fixture::assertIsolated();
        $root = getenv('MYABI_CONCURRENCY_DIRECTORY');
        $this->directory = $root.'/case-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['browser-extension.rate_limit_store' => 'database', 'fortify.mfa_enabled' => false]);
        Fixture::bindSource();
        $this->reader = User::factory()->create(['roles' => ['reader'], 'active' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if (is_resource($worker)) {
                $status = proc_get_status($worker);
                if ($status['running']) {
                    proc_terminate($worker);
                }
                proc_close($worker);
            }
        }
        $this->workers = [];
        parent::tearDown();
    }

    public function test_two_workers_cannot_exceed_the_shared_token_quota(): void
    {
        $a = $this->start('quota', ['name' => 'quota-a', 'attempts' => 80, 'tokens' => ['shared']]);
        $b = $this->start('quota', ['name' => 'quota-b', 'attempts' => 80, 'tokens' => ['shared']]);
        $this->barrier('quota-a-ready.json');
        $this->barrier('quota-b-ready.json');
        touch($this->directory.'/quota-start');
        $results = [$this->finish('quota-a', $a), $this->finish('quota-b', $b)];
        self::assertSame(60, array_sum(array_column($results, 'accepted')));
        self::assertSame(100, array_sum(array_column($results, 'rejected')));
    }

    public function test_two_workers_cannot_bypass_the_user_quota_with_different_tokens(): void
    {
        $a = $this->start('quota', ['name' => 'quota-a', 'attempts' => 80, 'tokens' => ['a', 'b']]);
        $b = $this->start('quota', ['name' => 'quota-b', 'attempts' => 80, 'tokens' => ['c', 'd']]);
        $this->barrier('quota-a-ready.json');
        $this->barrier('quota-b-ready.json');
        touch($this->directory.'/quota-start');
        $results = [$this->finish('quota-a', $a), $this->finish('quota-b', $b)];
        self::assertSame(120, array_sum(array_column($results, 'accepted')));
        self::assertSame(40, array_sum(array_column($results, 'rejected')));
        foreach ($results as $result) {
            foreach ($result['perToken'] as $count) {
                self::assertLessThanOrEqual(60, $count);
            }
        }
    }

    public function test_reader_generation_is_coherent_while_a_writer_waits_on_the_state_barrier(): void
    {
        $one = Fixture::term('ONE', $this->reader->id);
        $two = Fixture::term('TWO', $this->reader->id);
        app(SearchProjection::class)->rebuild();
        $reader = $this->start('reader', ['name' => 'reader', 'userId' => $this->reader->id, 'pause' => true]);
        $this->barrier('reader-locked.json');
        $writer = $this->start('writer', ['name' => 'writer', 'termId' => $two->id]);
        $connection = $this->barrier('writer-issued.json')['connectionId'];
        $this->assertLockWait($connection);
        self::assertFileDoesNotExist($this->directory.'/writer-result.json');
        touch($this->directory.'/reader-release');
        $response = $this->finish('reader', $reader);
        self::assertSame(['status' => 200, 'matchState' => 'strong', 'exactCount' => 1, 'complete' => true],
            array_intersect_key($response, array_flip(['status', 'matchState', 'exactCount', 'complete'])));
        $this->finish('writer', $writer);
        try {
            app(SearchService::class)->search($this->reader, Fixture::request());
            self::fail('A committed competing cell must invalidate the old generation.');
        } catch (ApiProblem $error) {
            self::assertSame(503, $error->status);
        }
        app(SearchProjection::class)->syncMany(collect([$two->fresh()]));
        $response = app(SearchService::class)->search($this->reader, Fixture::request());
        self::assertSame('multiple', $response['matchState']);
        self::assertSame(2, $response['exactCandidateCount']);
    }

    public function test_dirty_marker_and_generation_counter_roll_back_with_the_business_write(): void
    {
        Fixture::term('ONE', $this->reader->id);
        $two = Fixture::term('TWO', $this->reader->id);
        app(SearchProjection::class)->rebuild();
        $mutation = DB::table(SearchProjection::STATE)->where('id', 1)->value('mutation_no');
        $writer = $this->start('rollback', ['name' => 'writer', 'termId' => $two->id]);
        $this->barrier('writer-written.json');
        self::assertSame($mutation, DB::table(SearchProjection::STATE)->where('id', 1)->value('mutation_no'));
        self::assertSame(0, DB::table(SearchProjection::DIRTY)->count());
        touch($this->directory.'/writer-release');
        $this->finish('writer', $writer);
        self::assertSame($mutation, DB::table(SearchProjection::STATE)->where('id', 1)->value('mutation_no'));
        self::assertSame(0, DB::table(SearchProjection::DIRTY)->count());
        $response = app(SearchService::class)->search($this->reader, Fixture::request());
        self::assertSame(1, $response['exactCandidateCount']);
        self::assertSame('strong', $response['matchState']);
    }

    public function test_a_rebuilding_generation_never_certifies_uniqueness_before_atomic_activation(): void
    {
        Fixture::term('ONE', $this->reader->id);
        app(SearchProjection::class)->rebuild();
        $old = DB::table(SearchProjection::STATE)->where('id', 1)->value('active_generation');
        Fixture::term('THREE', $this->reader->id);
        $rebuild = $this->start('rebuild', ['name' => 'rebuild']);
        $this->barrier('rebuild-reading.json');
        $state = DB::table(SearchProjection::STATE)->where('id', 1)->first();
        self::assertFalse((bool) $state->ready);
        self::assertSame($old, $state->active_generation);
        $reader = $this->start('reader', ['name' => 'reader', 'userId' => $this->reader->id]);
        $connection = $this->barrier('reader-issued.json')['connectionId'];
        $this->assertLockWait($connection);
        touch($this->directory.'/rebuild-release');
        $response = $this->finish('reader', $reader);
        if ($response['status'] === 200) {
            self::assertSame('multiple', $response['matchState']);
            self::assertSame(2, $response['exactCount']);
        } else {
            self::assertSame(503, $response['status']);
            self::assertSame('search_index_unavailable', $response['code']);
        }
        $this->finish('rebuild', $rebuild);
        $response = app(SearchService::class)->search($this->reader, Fixture::request());
        self::assertSame('multiple', $response['matchState']);
        self::assertSame(2, $response['exactCandidateCount']);
        self::assertTrue($response['candidateSetComplete']);
    }

    private function start(string $mode, array $input)
    {
        $name = $input['name'];
        $path = $this->directory.'/'.$name.'-input.json';
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $environment = getenv();
        $environment['MYABI_CONCURRENCY_DIRECTORY'] = $this->directory;
        $worker = proc_open([PHP_BINARY, base_path('tests/Support/browser-extension-concurrency-worker.php'), $mode, $path], [
            0 => ['pipe', 'r'], 1 => ['file', $this->directory.'/'.$name.'-stdout.log', 'a'],
            2 => ['file', $this->directory.'/'.$name.'-stderr.log', 'a'],
        ], $pipes, base_path(), $environment, ['bypass_shell' => true]);
        self::assertIsResource($worker);
        fclose($pipes[0]);
        $this->workers[] = $worker;

        return $worker;
    }

    private function barrier(string $name): array
    {
        Fixture::wait($this->directory.'/'.$name);

        return json_decode(file_get_contents($this->directory.'/'.$name), true, 8, JSON_THROW_ON_ERROR);
    }

    private function finish(string $name, $worker): array
    {
        Fixture::wait($this->directory.'/'.$name.'-result.json', 30);
        $deadline = microtime(true) + 5;
        while (proc_get_status($worker)['running'] && microtime(true) < $deadline) {
            usleep(10000);
        }
        $result = json_decode(file_get_contents($this->directory.'/'.$name.'-result.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok'] ?? false, 'The synthetic worker failed without disclosing connection details.');

        return $result;
    }

    private function assertLockWait(int $connection): void
    {
        $deadline = microtime(true) + 10;
        do {
            $waiting = DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.INNODB_LOCK_WAITS w
                JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id
                WHERE t.trx_mysql_thread_id = ?', [$connection])->aggregate;
            if ($waiting > 0) {
                self::assertGreaterThan(0, $waiting);

                return;
            }
            // MariaDB may omit a read-only locking transaction from INNODB_TRX.
            // A one-row PK lookup still running at least a second on this exact
            // shared-lock statement proves that the other process is waiting.
            $process = DB::selectOne('SELECT TIME AS elapsed, INFO AS statement FROM information_schema.PROCESSLIST WHERE ID = ?', [$connection]);
            if ($process && $process->elapsed >= 1
                && str_contains((string) $process->statement, SearchProjection::STATE)
                && str_contains(strtolower((string) $process->statement), 'lock in share mode')) {
                self::assertGreaterThanOrEqual(1, (int) $process->elapsed);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('A separate connection did not wait on the state row lock.');
    }
}
