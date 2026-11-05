<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/myabi-version-test-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory.'/cache', 0700, true);
        $this->app->useConfigPath($this->directory);
        $this->app->useBootstrapPath($this->directory);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_display_uses_the_installed_configuration_without_changing_source(): void
    {
        $this->source('2026.11.1');
        config(['version.number' => '2026.11.7']);

        $this->artisan('app:version')->expectsOutput('2026.11.7')->assertSuccessful();

        self::assertSame('2026.11.1', (require $this->directory.'/version.php')['number']);
    }

    #[DataProvider('increments')]
    public function test_bump_uses_the_source_and_swiss_calendar_and_clears_cached_configuration(string $current, string $date, string $expected): void
    {
        $this->source($current);
        $this->travelTo(new \DateTimeImmutable($date));
        config(['version.number' => '2020.01.1']);
        $cache = $this->app->getCachedConfigPath();
        File::put($cache, '<?php return [];');

        $this->artisan('app:version', ['--bump' => true])->expectsOutput($expected)->assertSuccessful();

        self::assertSame($expected, (require $this->directory.'/version.php')['number']);
        self::assertSame($expected, config('version.number'));
        self::assertFileDoesNotExist($cache);
    }

    public static function increments(): array
    {
        return [
            ['2026.11.1', '2026-11-05T12:00:00+01:00', '2026.11.2'],
            ['2026.11.9', '2026-11-05T12:00:00+01:00', '2026.11.10'],
            ['2026.10.7', '2026-11-05T12:00:00+01:00', '2026.11.1'],
            ['2026.12.7', '2027-01-01T00:00:00+01:00', '2027.01.1'],
            ['2026.11.7', '2026-11-30T23:30:00+00:00', '2026.12.1'],
        ];
    }

    #[DataProvider('invalidVersions')]
    public function test_invalid_or_future_version_is_not_overwritten(string $current): void
    {
        $this->source($current);
        $this->travelTo(new \DateTimeImmutable('2026-11-05T12:00:00+01:00'));

        $this->artisan('app:version', ['--bump' => true])->assertFailed();

        self::assertSame($current, (require $this->directory.'/version.php')['number']);
    }

    public static function invalidVersions(): array
    {
        return [['dev'], ['2026.1.1'], ['2026.13.1'], ['2026.11.0'], ['2026.11.01'], ['2026.12.1'], ['2026.11.999999999999999999999999999']];
    }

    private function source(string $version): void
    {
        File::put($this->directory.'/version.php', '<?php return '.var_export(['number' => $version], true).';');
    }
}
