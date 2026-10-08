<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\User;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportCollisionTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStorage;

    private User $manager;

    private MyabiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myabi-collision-test-'.bin2hex(random_bytes(8));
        mkdir($this->isolatedStorage.'/app/private', 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
        config(['referentiel.storage_quota' => 1024 * 1024 * 1024, 'referentiel.batch_size' => 300]);
        $organization = Organisation::create(['code' => 'QA', 'name' => 'Synthetic collision test', 'active' => true]);
        $this->manager = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['manager']]);
        User::factory()->create(['email' => 'import@referentiel.invalid', 'is_technical' => true, 'active' => false]);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage) && dirname($this->isolatedStorage) === rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) && str_starts_with(basename($this->isolatedStorage), 'myabi-collision-test-')) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    public function test_large_duplicate_group_retains_every_row_and_limits_only_the_summary(): void
    {
        $count = 12000;
        $import = $this->receive((function () use ($count) {
            for ($row = 0; $row < $count; $row++) {
                yield 'Synthetic duplicate';
            }
        })());
        $checksum = hash_file('sha256', $import->originalPath());
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        app(ImportService::class)->analyze($import);
        $growth = memory_get_peak_usage(true) - $baseline;
        self::assertLessThan(12 * 1024 * 1024, $growth, 'Analysis memory must stay bounded for a large collision group.');
        $report = $import->fresh()->report;
        self::assertSame($count - 1, $report['collisions']);
        self::assertSame($count, $report['collision_rows_total']);
        self::assertSame(range(1, 1000), $report['collision_rows']);
        self::assertTrue($report['collision_rows_truncated']);
        self::assertSame($count, DB::table('import_rows')->where('import_id', $import->id)->where('collision', true)->count());
        self::assertSame($checksum, hash_file('sha256', $import->originalPath()));

        $this->withoutMiddleware(RequireTwoFactor::class)->actingAs($this->manager);
        $reportCsv = $this->get('/imports/'.$import->id.'/report')->assertOk()->streamedContent();
        self::assertSame($count + 1, substr_count($reportCsv, "\n"));
        self::assertSame($count, substr_count($reportCsv, ';yes;'));
        self::assertStringContainsString('12000;', $reportCsv);
        $this->assertDatabaseCount('terms', 0);
        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_collision_group_pagination_preserves_all_groups_and_blocks_proposals(): void
    {
        $import = $this->receive((function () {
            for ($group = 0; $group < 320; $group++) {
                yield 'Synthetic '.$group;
                yield 'Synthetic '.$group;
            }
        })());
        $service = app(ImportService::class);
        $service->analyze($import);
        $report = $import->fresh()->report;
        self::assertSame(320, $report['collisions']);
        self::assertSame(640, $report['collision_rows_total']);
        self::assertFalse($report['collision_rows_truncated']);
        self::assertSame(range(1, 640), $report['collision_rows']);
        $service->apply($import->fresh());
        self::assertSame(640, DB::table('import_rows')->where('import_id', $import->id)->where('collision', true)->count());
        $this->assertDatabaseCount('proposals', 0);
        self::assertSame('applied', $import->fresh()->status);
    }

    public function test_shared_hash_does_not_confuse_distinct_exact_keys_or_clear_duplicate_flags(): void
    {
        $import = $this->receive(['Exact', 'exact', 'Exact ', 'Exact', 'exact', 'Unique']);
        $service = app(ImportService::class);
        $service->analyze($import);
        // Simulate a cryptographic bucket collision without changing the immutable CSV.
        DB::table('import_rows')->where('import_id', $import->id)->update(['raw_identity_hash' => str_repeat('a', 64)]);
        $service->analyze($import->fresh());
        $report = $import->fresh()->report;
        self::assertSame(2, $report['collisions']);
        self::assertSame(4, $report['collision_rows_total']);
        self::assertSame([1, 2, 4, 5], $report['collision_rows']);
        self::assertSame([3, 6], DB::table('import_rows')->where('import_id', $import->id)->where('collision', false)->orderBy('record_number')->pluck('record_number')->all());
    }

    private function receive(iterable $names): ImportBatch
    {
        $format = FormatRegistry::get('server');
        $file = storage_path('app/private/synthetic.csv');
        $handle = fopen($file, 'wb');
        fputcsv($handle, $format->headers, ';', '"', '', "\r\n");
        foreach ($names as $name) {
            fputcsv($handle, array_map(fn ($column) => match ($column) {
                'Name' => $name, 'de_CH' => 'Synthetic reference', 'fr_CH' => 'Synthetic translation', default => '',
            }, $format->headers), ';', '"', '', "\r\n");
        }
        fclose($handle);

        return app(ImportService::class)->receive($file, $format->filename, ['type' => 'server', 'version_id' => $this->version->id,
            'organization_id' => null, 'source_kind' => 'publisher'], $this->manager);
    }
}
