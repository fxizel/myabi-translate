<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class DatabaseProcess
{
    /** Start a separate application/SQL connection. Fixtures must already be committed. */
    public static function start(string $body, array $parameters = []): Process
    {
        $bootstrap = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->useStoragePath($input['storage']);
        config(['app.env' => 'testing', 'app.key' => $input['key'], 'database.default' => 'concurrent',
            'database.connections.concurrent' => array_replace($input['database'], ['name' => 'concurrent']), 'session.driver' => 'array',
            'cache.default' => 'array', 'fortify.mfa_enabled' => false]);
        $parameters = $input['parameters'];
        PHP;
        $process = new Process([PHP_BINARY, '-r', $bootstrap."\n".$body], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        ], json_encode([
            'database' => DB::connection()->getConfig(), 'storage' => storage_path(),
            'key' => config('app.key'), 'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR), 20);
        $process->start();

        return $process;
    }

    public static function awaitOutput(Process $process, string $marker): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), $marker)) {
                return;
            }
            if (! $process->isRunning()) {
                throw new \RuntimeException('Database worker exited before '.$marker.': '.$process->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Database worker timed out before '.$marker.'.');
    }
}
