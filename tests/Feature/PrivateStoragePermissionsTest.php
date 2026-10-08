<?php

namespace Tests\Feature;

use App\Services\AtomicFileWriter;
use App\Services\OperationLock;
use App\Services\PrivateStoragePermissions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivateStoragePermissionsTest extends TestCase
{
    private string $privateStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myabi-private-mode-'.bin2hex(random_bytes(8));
        mkdir($this->privateStorage, 0700);
        $this->app->useStoragePath($this->privateStorage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateStorage);
        parent::tearDown();
    }

    public static function profiles(): array
    {
        return ['owner only' => [false, 0600, 0700], 'explicit shared group' => [true, 0660, 0770]];
    }

    #[DataProvider('profiles')]
    public function test_private_disk_and_manual_writers_use_the_same_opt_in_profile(bool $shared, int $fileMode, int $directoryMode): void
    {
        $previousEnv = $_ENV['PRIVATE_SHARED_GROUP'] ?? null;
        $previousServer = $_SERVER['PRIVATE_SHARED_GROUP'] ?? null;
        try {
            $_ENV['PRIVATE_SHARED_GROUP'] = $_SERVER['PRIVATE_SHARED_GROUP'] = $shared ? 'true' : 'false';
            $config = require base_path('config/filesystems.php');
            $referentiel = require base_path('config/referentiel.php');
            config(['filesystems.disks.local' => $config['disks']['local'], 'referentiel.private_shared_group' => $referentiel['private_shared_group']]);
            Storage::forgetDisk('local');
            self::assertSame('private', $config['disks']['local']['visibility']);
            self::assertSame('private', $config['disks']['local']['directory_visibility']);
            self::assertFalse($config['disks']['local']['serve']);
            self::assertSame($fileMode, $config['disks']['local']['permissions']['file']['private']);
            self::assertSame($directoryMode, $config['disks']['local']['permissions']['dir']['private']);
            $permissions = new PrivateStoragePermissions;
            self::assertSame($fileMode, $permissions->fileMode());
            self::assertSame($directoryMode, $permissions->directoryMode());
            self::assertTrue(Storage::disk('local')->put('disk/example.txt', 'private fixture'));
            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertSame('private', Storage::disk('local')->visibility('disk/example.txt'));
            }
            self::assertSame('private fixture', Storage::disk('local')->get('disk/example.txt'));
            (new OperationLock)->run(function () use ($permissions) {
                $permissions->ensureDirectory(storage_path('app/private/progress'));
                (new AtomicFileWriter)->write(storage_path('app/private/progress/state.json'), '{"rows":300}');
            });
            self::assertSame('{"rows":300}', file_get_contents(storage_path('app/private/progress/state.json')));
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['PRIVATE_SHARED_GROUP']);
            } else {
                $_ENV['PRIVATE_SHARED_GROUP'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['PRIVATE_SHARED_GROUP']);
            } else {
                $_SERVER['PRIVATE_SHARED_GROUP'] = $previousServer;
            }
            Storage::forgetDisk('local');
        }
    }

    #[DataProvider('profiles')]
    public function test_posix_modes_survive_restrictive_umask_and_atomic_replacement(bool $shared, int $fileMode, int $directoryMode): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix permission bits require a POSIX filesystem.');
        }
        config(['referentiel.private_shared_group' => $shared]);
        $previousUmask = umask(0077);
        try {
            $permissions = new PrivateStoragePermissions;
            $root = storage_path('app/private');
            $permissions->ensureDirectory($root);
            chmod($root, 02000 | $directoryMode);
            $directory = $root.'/progress/checkpoints';
            $permissions->ensureDirectory($directory);
            $path = $directory.'/state.json';
            (new AtomicFileWriter)->write($path, '{"rows":300}');
            (new AtomicFileWriter)->write($path, '{"rows":600}');
            (new OperationLock)->run(fn () => null);
            foreach ([$root, dirname($directory), $directory] as $folder) {
                clearstatcache(true, $folder);
                self::assertSame(02000 | $directoryMode, fileperms($folder) & 07777);
            }
            foreach ([$path, $root.'/operations.lock'] as $file) {
                clearstatcache(true, $file);
                self::assertSame($fileMode, fileperms($file) & 07777);
            }
            self::assertSame('{"rows":600}', file_get_contents($path));
            self::assertSame([], glob($directory.'/*.tmp'));
        } finally {
            umask($previousUmask);
        }
    }
}
