<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorageBudget
{
    public function usage(): array
    {
        $external = (int) config('referentiel.external_storage_used');
        $files = 0;
        $database = 0;
        foreach ([storage_path(), ...array_map(fn ($path) => base_path($path), ['vendor', 'app', 'public', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'scripts'])] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $files += $file->getSize();
                }
            }
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            $database = (int) DB::selectOne('SELECT COALESCE(SUM(data_length + index_length),0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()')->bytes;
        }
        foreach (['artisan', 'composer.json', 'composer.lock', 'RELEASE.json', '.env'] as $name) {
            if (is_file(base_path($name))) {
                $files += filesize(base_path($name));
            }
        }
        $bytes = $files + $database + $external;
        $quota = (int) config('referentiel.storage_quota');

        return ['bytes' => $bytes, 'quota' => $quota, 'ratio' => $quota ? $bytes / $quota : 1, 'warning' => $bytes >= $quota * .8,
            'files_bytes' => $files, 'database_bytes' => $database, 'external_bytes' => $external];
    }

    public function ensure(int $additional): void
    {
        $usage = $this->usage();
        if ($usage['quota'] < $usage['bytes'] + $additional || disk_free_space(storage_path()) < $additional) {
            throw ValidationException::withMessages(['storage' => __('ui.storage_insufficient')]);
        }
    }
}
