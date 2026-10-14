<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\RawExporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MultiSourceRawExporterTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_composition_keeps_optional_quotes_and_multiline_raw_cells_and_separates_unterminated_source_rows(): void
    {
        $format = FormatRegistry::get('incident');
        $row = array_fill_keys($format->headers, 'synthetic');
        $row['TEXT_DE'] = "Quelle\n{}";
        $row['TEXT_FR'] = 'Import {}';
        $row['ATTRIBUTES'] = '{"synthetic": true}';
        $first = $this->file();
        $second = $this->file();
        $output = $this->file();
        $header = $this->csv($format->headers);
        file_put_contents($first, $header.$this->csv(array_values($row), ''));
        $row['CODEVALUE'] = ' Second ';
        file_put_contents($second, $header.$this->csv(array_values($row)));
        $firstRecords = iterator_to_array((new CsvReader)->records($first));
        $secondRecords = iterator_to_array((new CsvReader)->records($second));
        $result = (new RawExporter)->exportRecords($firstRecords[0], [
            ['record' => $firstRecords[1], 'replacements' => ['TEXT_FR' => "Validé; \"texte\"\n{}"]],
            ['record' => $secondRecords[1], 'replacements' => []],
        ], $output, $format);
        $records = iterator_to_array((new CsvReader)->records($output));
        self::assertSame(2, $result['records']);
        self::assertCount(3, $records);
        self::assertSame($firstRecords[0]->raw(), $records[0]->raw());
        self::assertSame("\r\n", $records[1]->ending);
        self::assertSame($secondRecords[1]->raw(), $records[2]->raw());
        self::assertSame("Validé; \"texte\"\n{}", $records[1]->associative($format->headers)['TEXT_FR']);
        foreach ($firstRecords[1]->rawCells as $index => $raw) {
            if ($format->headers[$index] !== 'TEXT_FR') {
                self::assertSame($raw, $records[1]->rawCells[$index]);
            }
        }
        self::assertSame(hash_file('sha256', $output), $result['sha256']);
    }

    public function test_failure_while_resolving_a_later_source_removes_all_partial_output(): void
    {
        $format = FormatRegistry::get('mric');
        $source = $this->file();
        $output = $this->file();
        file_put_contents($source, $this->csv($format->headers).$this->csv(array_fill(0, count($format->headers), 'synthetic')));
        $records = iterator_to_array((new CsvReader)->records($source));
        $items = static function () use ($records) {
            yield ['record' => $records[1], 'replacements' => []];
            throw new RuntimeException('Synthetic interruption');
        };
        try {
            (new RawExporter)->exportRecords($records[0], $items(), $output, $format);
            self::fail('An interrupted composition must fail.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($output);
        }
    }

    public function test_empty_catalogue_preserves_an_unterminated_native_header(): void
    {
        $format = FormatRegistry::get('mric');
        $source = $this->file();
        $output = $this->file();
        $bytes = $this->csv($format->headers, '');
        file_put_contents($source, $bytes);
        $header = (new CsvReader)->readAt($source, 0, 0);
        $result = (new RawExporter)->exportRecords($header, [], $output, $format);
        self::assertSame($bytes, file_get_contents($output));
        self::assertSame(0, $result['records']);
    }

    public function test_composition_rejects_technical_replacements_and_encoding_loss(): void
    {
        $format = FormatRegistry::get('mric');
        $source = $this->file();
        file_put_contents($source, $this->csv($format->headers).$this->csv(array_fill(0, count($format->headers), 'synthetic')));
        $records = iterator_to_array((new CsvReader)->records($source));
        foreach ([['Name' => 'Changed'], ['fr_CH' => '漢']] as $replacements) {
            $output = $this->file();
            try {
                (new RawExporter)->exportRecords($records[0], [['record' => $records[1], 'replacements' => $replacements]], $output, $format);
                self::fail('An invalid replacement must fail.');
            } catch (InvalidArgumentException) {
                self::assertFileDoesNotExist($output);
            }
        }
    }

    private function file(): string
    {
        return $this->files[] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'devconf-multi-source-'.bin2hex(random_bytes(10)).'.csv';
    }

    private function csv(array $values, string $ending = "\r\n"): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $value = Encoding::encode($value);
            $cells[] = $index % 2 === 0 || strpbrk($value, ";\"\r\n") !== false ? '"'.str_replace('"', '""', $value).'"' : $value;
        }

        return implode(';', $cells).$ending;
    }
}
