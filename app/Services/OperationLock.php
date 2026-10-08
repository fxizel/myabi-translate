<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class OperationLock
{
    /** Shared locks let existing short writes finish before heavy work starts. */
    public function run(callable $callback, bool $heavy = false): mixed
    {
        $path = storage_path('app/private/operations.lock');
        $permissions = new PrivateStoragePermissions;
        $permissions->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle) {
            try {
                $permissions->secureFile($path);
            } catch (\Throwable $error) {
                fclose($handle);
                throw $error;
            }
        }
        if (! $handle || ! flock($handle, ($heavy ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            throw ValidationException::withMessages(['operation' => __('ui.writes_paused')]);
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function busy(): bool
    {
        $path = storage_path('app/private/operations.lock');
        if (! is_file($path)) {
            return false;
        }
        $h = fopen($path, 'r');
        $free = flock($h, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        } fclose($h);

        return ! $free;
    }
}
