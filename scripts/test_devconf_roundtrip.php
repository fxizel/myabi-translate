<?php

declare(strict_types=1);

// Private original-data compatibility test. Prints only counts, hashes and timings.
// Run: php scripts/test_devconf_roundtrip.php [path/to/private/imports] [--validate-semantics]
require dirname(__DIR__).'/vendor/autoload.php';

use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\RawExporter;

$directory = isset($argv[1]) && ! str_starts_with($argv[1], '--') ? $argv[1] : dirname(__DIR__).'/data/imports';
$validateSemantics = in_array('--validate-semantics', $argv, true);
$expected = ['mric' => 38562, 'monomot' => 3867, 'server' => 941, 'form' => 98974, 'workflow' => 22830, 'law' => 147, 'incident' => 415821];
$reports = [];
$failed = false;

foreach (FormatRegistry::all() as $code => $format) {
    $source = $directory.DIRECTORY_SEPARATOR.$format->filename;
    $destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'devconf-roundtrip-'.bin2hex(random_bytes(12)).'.csv';
    $started = microtime(true);
    $attributes = 0;
    try {
        $resolver = $validateSemantics ? function ($record, $row, $definition) use (&$attributes): array {
            $definition->identity($row);
            $attributes += count($definition->attributes($row));

            return [];
        } : null;
        $manifest = (new RawExporter)->export($source, $destination, $resolver, $format);
        $identical = hash_file('sha256', $source) === $manifest['sha256'] && filesize($source) === $manifest['bytes'];
        $valid = $identical && $manifest['records'] === $expected[$code];
        $failed = $failed || ! $valid;
        $reports[] = ['file' => $format->filename, ...$manifest, 'byte_identical' => $identical, 'expected_records' => $expected[$code], 'validated_attributes' => $validateSemantics ? $attributes : null, 'seconds' => round(microtime(true) - $started, 3), 'peak_memory_bytes' => memory_get_peak_usage(true), 'passed' => $valid];
    } catch (Throwable $error) {
        $failed = true;
        $reports[] = ['file' => $format->filename, 'error_type' => $error::class, 'passed' => false];
    } finally {
        if (is_file($destination)) {
            unlink($destination);
        }
    }
    echo json_encode(end($reports), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}

echo json_encode(['files' => count($reports), 'records' => array_sum(array_column($reports, 'records')), 'bytes' => array_sum(array_column($reports, 'bytes')), 'passed' => ! $failed], JSON_THROW_ON_ERROR).PHP_EOL;
exit($failed ? 1 : 0);
