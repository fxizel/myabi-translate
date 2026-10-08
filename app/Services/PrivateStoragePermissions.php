<?php

namespace App\Services;

use Illuminate\Container\Container;
use RuntimeException;

/** Private storage may be shared only with an explicitly provisioned Unix group. */
class PrivateStoragePermissions
{
    public function fileMode(): int
    {
        return $this->sharedGroup() ? 0660 : 0600;
    }

    public function directoryMode(): int
    {
        return $this->sharedGroup() ? 0770 : 0700;
    }

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            $parent = dirname($path);
            if (! is_dir($parent)) {
                $this->ensureDirectory($parent);
            }
            if (! @mkdir($path, $this->directoryMode()) && ! is_dir($path)) {
                throw new RuntimeException('Cannot create private storage directory.');
            }
        }
        $this->apply($path, $this->directoryMode(), true);
    }

    public function secureFile(string $path): void
    {
        $this->apply($path, $this->fileMode());
    }

    private function sharedGroup(): bool
    {
        // AtomicFileWriter is also used by standalone tests without a booted app.
        $container = Container::getInstance();

        return $container->bound('config') && (bool) $container->make('config')->get('referentiel.private_shared_group', false);
    }

    private function apply(string $path, int $mode, bool $directory = false): void
    {
        // Windows ACLs are provisioned by the operator; Unix modes do not model them.
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        clearstatcache(true, $path);
        $current = fileperms($path);
        if ($current === false) {
            throw new RuntimeException('Cannot inspect private storage permissions.');
        }
        // Preserve inherited setgid so descendants retain the provisioned group.
        $mode |= $directory ? $current & 02000 : 0;
        // Another group member may own an already-correct file and cannot be chmodded.
        if (($current & 07777) !== $mode && ! @chmod($path, $mode)) {
            throw new RuntimeException('Private storage permissions require repair by their owner.');
        }
    }
}
