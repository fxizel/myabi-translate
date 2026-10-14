<?php

use App\Models\User;
use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\RateLimits;
use App\Services\BrowserExtension\SearchProjection;
use App\Services\BrowserExtension\SearchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BrowserExtensionConcurrencyFixture as Fixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$resultFile = null;
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Fixture::assertIsolated();
    $directory = realpath(getenv('MYABI_CONCURRENCY_DIRECTORY') ?: '');
    $root = Fixture::normalize(base_path('.runtime/mariadb-tests')).'/';
    if (! $directory || ! str_starts_with(Fixture::normalize($directory).'/', $root)) {
        throw new RuntimeException('The worker directory is not isolated.');
    }
    $inputFile = $argv[2] ?? '';
    if (! is_file($inputFile) || dirname(realpath($inputFile)) !== $directory) {
        throw new RuntimeException('The worker input is not isolated.');
    }
    $data = json_decode(file_get_contents($inputFile), true, 4, JSON_THROW_ON_ERROR);
    $name = $data['name'];
    if (! preg_match('/\A[a-z0-9_-]+\z/', $name)) {
        throw new RuntimeException('Invalid technical worker name.');
    }
    $resultFile = $directory.'/'.$name.'-result.json';
    $mark = static function (string $event, array $values = []) use ($directory, $name): void {
        Fixture::write($directory.'/'.$name.'-'.$event.'.json', $values + [
            'connectionId' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
        ]);
    };
    config(['browser-extension.rate_limit_store' => 'database']);
    switch ($argv[1] ?? '') {
        case 'quota':
            $mark('ready');
            Fixture::wait($directory.'/quota-start');
            $accepted = 0;
            $rejected = 0;
            $perToken = [];
            foreach (range(0, $data['attempts'] - 1) as $i) {
                $token = $data['tokens'][$i % count($data['tokens'])];
                $retry = app(RateLimits::class)->consume([
                    'extension:search-user:synthetic' => 120,
                    'extension:search-token:'.$token => 60,
                ], 'user:synthetic');
                if ($retry) {
                    $rejected++;
                } else {
                    $accepted++;
                    $perToken[$token] = ($perToken[$token] ?? 0) + 1;
                }
            }
            $result = ['accepted' => $accepted, 'rejected' => $rejected, 'perToken' => $perToken];
            break;
        case 'reader':
            Fixture::bindSource();
            DB::listen(function ($event) use ($mark, $directory, $data) {
                if (str_contains($event->sql, SearchProjection::STATE)
                    && str_contains(strtolower($event->sql), 'lock in share mode')
                    && ($data['pause'] ?? false)) {
                    $mark('locked');
                    Fixture::wait($directory.'/reader-release');
                }
            });
            $mark('issued');
            try {
                $response = app(SearchService::class)->search(User::findOrFail($data['userId']), Fixture::request());
                $result = ['status' => 200, 'matchState' => $response['matchState'], 'exactCount' => $response['exactCandidateCount'], 'complete' => $response['candidateSetComplete']];
            } catch (ApiProblem $error) {
                $result = ['status' => $error->status, 'code' => $error->errorCode];
            }
            break;
        case 'writer':
        case 'rollback':
            $rollback = ($argv[1] ?? '') === 'rollback';
            DB::beginTransaction();
            $mark('issued');
            DB::table('terms')->where('id', $data['termId'])->update([
                'validated' => json_encode(['label' => ['fr' => 'Concurrent match']], JSON_THROW_ON_ERROR),
                'revision_no' => DB::raw('revision_no + 1'),
            ]);
            $mark('written');
            if ($rollback) {
                Fixture::wait($directory.'/writer-release');
                DB::rollBack();
            } else {
                DB::commit();
            }
            $result = ['rolledBack' => $rollback];
            break;
        case 'rebuild':
            $first = true;
            Fixture::bindSource(function () use (&$first, $mark, $directory) {
                if ($first) {
                    $first = false;
                    $mark('reading');
                    Fixture::wait($directory.'/rebuild-release');
                }
            });
            $result = app(SearchProjection::class)->rebuild();
            break;
        default:
            throw new RuntimeException('Unknown test worker operation.');
    }
    Fixture::write($resultFile, ['ok' => true] + $result);
    exit(0);
} catch (Throwable $error) {
    if ($resultFile) {
        Fixture::write($resultFile, ['ok' => false, 'error' => get_class($error)]);
    }
    // Do not print exception messages, DSNs, credentials, or original records.
    fwrite(STDERR, "Synthetic concurrency worker failed.\n");
    exit(1);
}
