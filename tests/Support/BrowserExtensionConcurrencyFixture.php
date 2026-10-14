<?php

namespace Tests\Support;

use App\Models\Revision;
use App\Models\Term;
use App\Services\SourceRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BrowserExtensionConcurrencyFixture
{
    public static function assertIsolated(): void
    {
        $connection = DB::connection();
        $schema = $connection->getDatabaseName();
        $expected = getenv('MYABI_CONCURRENCY_EXPECTED_DATADIR');
        if (getenv('MYABI_ISOLATED_TESTS') !== '1'
            || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || ! preg_match('/\Amyabi_test_[a-z0-9_]+\z/', $schema)
            || ! is_string($expected) || $expected === '') {
            throw new RuntimeException('An explicitly isolated MariaDB test schema is required.');
        }
        $actual = $connection->selectOne('SELECT @@datadir AS datadir, VERSION() AS version, DATABASE() AS db');
        $root = self::normalize(base_path('.runtime/mariadb-tests')).'/';
        if (! str_contains($actual->version, 'MariaDB')
            || $actual->db !== $schema
            || ! str_starts_with(self::normalize($expected).'/', $root)
            || self::normalize($actual->datadir) !== self::normalize($expected)) {
            throw new RuntimeException('The database does not belong to the portable test instance.');
        }
    }

    public static function normalize(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    public static function bindSource(?\Closure $beforeRead = null): void
    {
        app()->instance(SourceRecord::class, new class($beforeRead) extends SourceRecord
        {
            public function __construct(private ?\Closure $beforeRead) {}

            public function attributes(Term|Revision $item): array
            {
                ($this->beforeRead ?? static fn () => null)();

                return ['label' => [
                    'reference' => 'Synthetic reference', 'reference_language' => 'de',
                    'translations' => ['fr' => ($item->key[0] ?? null) === 'TWO' ? 'Other value' : 'Concurrent match'],
                    'columns' => ['fr' => 'fr_CH'],
                ]];
            }
        });
    }

    public static function term(string $key, int $actor): Term
    {
        return Term::create([
            'type' => 'server', 'identity_hash' => hash('sha256', json_encode(['server', [$key]])),
            'key' => [$key], 'label' => $key, 'context' => '', 'source_text' => 'Synthetic reference', 'search_text' => '',
            'semantic_hash' => hash('sha256', 'semantic-'.$key), 'source_hash' => hash('sha256', 'source-'.$key),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => [], 'review_needed' => [], 'created_by' => $actor, 'updated_by' => $actor,
        ]);
    }

    public static function request(): array
    {
        return [
            'contractVersion' => 2, 'text' => 'Concurrent match', 'textTruncated' => false,
            'searchLanguage' => 'fr', 'lang' => 'fr', 'maxResults' => 20,
        ];
    }

    public static function write(string $path, array $data): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        if (! rename($temporary, $path)) {
            throw new RuntimeException('A synchronization file could not be written.');
        }
    }

    public static function wait(string $path, float $seconds = 15): void
    {
        $deadline = microtime(true) + $seconds;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('A test worker did not reach its synchronization barrier.');
            }
            usleep(10000);
        }
    }
}
