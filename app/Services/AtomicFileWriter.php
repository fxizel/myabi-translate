<?php

namespace App\Services;

use RuntimeException;

class AtomicFileWriter
{
    public function write(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Unable to write the progress checkpoint.');
            }
            (new PrivateStoragePermissions)->secureFile($temporary);
            // Windows can briefly deny replacement while another process reads
            // the old checkpoint. Keep it intact and retry the atomic rename.
            for ($attempt = 0; $attempt < 40; $attempt++) {
                if ($this->replace($temporary, $path)) {
                    return;
                }
                usleep(25_000);
            }
            throw new RuntimeException('Unable to replace the progress checkpoint.');
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    protected function replace(string $temporary, string $path): bool
    {
        return @rename($temporary, $path);
    }
}
