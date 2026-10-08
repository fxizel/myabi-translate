<?php

namespace Tests\Unit;

use App\Services\AtomicFileWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AtomicFileWriterTest extends TestCase
{
    public function test_transient_read_lock_keeps_old_checkpoint_until_atomic_replacement_succeeds(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'myabi-progress-');
        file_put_contents($path, '{"rows":300}');
        $writer = new class extends AtomicFileWriter
        {
            public int $attempts = 0;

            protected function replace(string $temporary, string $path): bool
            {
                self::checkPrevious($path);

                return ++$this->attempts < 3 ? false : parent::replace($temporary, $path);
            }

            private static function checkPrevious(string $path): void
            {
                if (file_get_contents($path) !== '{"rows":300}') {
                    throw new RuntimeException('Previous checkpoint was damaged.');
                }
            }
        };
        try {
            $writer->write($path, '{"rows":600}');
            self::assertSame(3, $writer->attempts);
            self::assertSame(['rows' => 600], json_decode(file_get_contents($path), true));
            self::assertSame([], glob($path.'.*.tmp'));
        } finally {
            unlink($path);
        }
    }

    public function test_persistent_failure_retains_readable_checkpoint_and_cleans_temporary_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'myabi-progress-');
        file_put_contents($path, '{"rows":300}');
        $writer = new class extends AtomicFileWriter
        {
            protected function replace(string $temporary, string $path): bool
            {
                return false;
            }
        };
        try {
            try {
                $writer->write($path, '{"rows":600}');
                self::fail('Permanent replacement failure must be reported.');
            } catch (RuntimeException $exception) {
                self::assertSame('Unable to replace the progress checkpoint.', $exception->getMessage());
            }
            self::assertSame(['rows' => 300], json_decode(file_get_contents($path), true));
            self::assertSame([], glob($path.'.*.tmp'));
        } finally {
            unlink($path);
        }
    }
}
