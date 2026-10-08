<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\User;
use App\Services\DevconfExportService;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseProcess;
use Tests\TestCase;

class ImportTransitionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function transitions(): array
    {
        return [['cancel', 'analyzed'], ['retry', 'failed']];
    }

    #[DataProvider('transitions')]
    public function test_transition_waits_for_the_row_lock_and_rechecks_committed_status(string $action, string $status): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB row locks and separate committed connections.');
        }
        $storage = sys_get_temp_dir().'/myabi-import-concurrency-'.bin2hex(random_bytes(8));
        File::makeDirectory($storage.'/app/private', 0700, true);
        $this->app->useStoragePath($storage);
        config(['referentiel.storage_quota' => 2_000_000_000, 'referentiel.external_storage_used' => 0]);
        $process = null;
        try {
            $org = Organisation::create(['code' => 'VD', 'name' => 'Synthetic', 'active' => true]);
            $user = User::factory()->create(['roles' => ['manager'], 'organization_id' => $org->id]);
            User::factory()->create(['is_technical' => true, 'active' => false]);
            $version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $user->id, 'updated_by' => $user->id]);
            $file = $storage.'/app/private/source.csv';
            $stream = fopen($file, 'wb');
            $headers = FormatRegistry::get('server')->headers;
            fputcsv($stream, $headers, ';', '"', '');
            fputcsv($stream, array_map(fn ($column) => ['Name' => 'Concurrent', 'de_CH' => 'Text', 'fr_CH' => 'Texte'][$column] ?? '', $headers), ';', '"', '');
            fclose($stream);
            $service = app(ImportService::class);
            $import = $service->receive($file, 'source.csv', ['type' => 'server', 'version_id' => $version->id, 'source_kind' => 'publisher'], $user);
            $service->analyze($import);
            $import->refresh()->update(['status' => $status]);
            $process = DatabaseProcess::start(<<<'PHP'
                $snapshot = \App\Models\ImportBatch::findOrFail($parameters['id']);
                auth()->setUser(\App\Models\User::findOrFail($parameters['user']));
                echo "snapshot\n"; flush();
                $deadline = microtime(true) + 10;
                while (! is_file(storage_path('go'))) {
                    if (microtime(true) > $deadline) { throw new \RuntimeException('Barrier timed out'); }
                    usleep(10000);
                }
                echo "attempt\n"; flush();
                try {
                    app(\App\Http\Controllers\ImportController::class)->{$parameters['action']}(
                        $snapshot, app(\App\Services\OperationLock::class), app(\App\Services\AuditService::class));
                    echo "accepted\n";
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                    echo 'status:'.$exception->getStatusCode()."\n";
                }
                PHP, ['id' => $import->id, 'user' => $user->id, 'action' => $action]);
            DatabaseProcess::awaitOutput($process, 'snapshot');
            DB::beginTransaction();
            try {
                ImportBatch::whereKey($import->id)->lockForUpdate()->firstOrFail();
                touch($storage.'/go');
                DatabaseProcess::awaitOutput($process, 'attempt');
                $service->apply($import->fresh());
                $this->assertTrue($process->isRunning(), 'The transition must wait for the import row lock.');
                DB::commit();
            } catch (\Throwable $error) {
                DB::rollBack();
                throw $error;
            }
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertStringContainsString('status:409', $process->getOutput());
            $this->assertStringNotContainsString('accepted', $process->getOutput());
            $this->assertSame('applied', $import->fresh()->status);
            $this->assertSame($import->id, app(DevconfExportService::class)->findSource($version->id, 'server', $org->id)?->id);
            $this->assertDatabaseCount('terms', 1);
            $this->assertDatabaseCount('proposals', 1);
            $this->assertDatabaseCount('revisions', 1);
            $this->assertSame(1, DB::table('import_rows')->whereNotNull('term_id')->count());
        } finally {
            $process?->stop();
            File::deleteDirectory($storage);
        }
    }
}
