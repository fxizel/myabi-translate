<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatDefinition;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\RawExporter;
use App\Domain\Devconf\TranslationValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevconfTest extends TestCase
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

    public static function formats(): iterable
    {
        foreach (array_keys(FormatRegistry::all()) as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('formats')]
    public function test_each_format_roundtrips_exactly_and_only_replaces_the_selected_cell(string $code): void
    {
        $format = FormatRegistry::get($code);
        $row = $this->row($format);
        $input = $this->file();
        $unchanged = $this->file();
        $changed = $this->file();
        $bytes = $this->csv($format->headers, true).$this->csv(array_values($row), $format->quoteAll);
        file_put_contents($input, $bytes);
        $exporter = new RawExporter;
        $manifest = $exporter->export($input, $unchanged, null, $format);
        self::assertSame($bytes, file_get_contents($unchanged));
        self::assertSame(hash('sha256', $bytes), $manifest['sha256']);
        self::assertSame(1, $manifest['records']);
        self::assertSame(0, $manifest['changed_cells']);
        $attributes = $format->attributes($row);
        $column = reset($attributes)['columns']['fr'];
        $value = "Élément; avec \"guillemets\"\nretour LF et\r\nretour CRLF  ";
        $manifest = $exporter->export($input, $changed, fn () => [$column => $value], $format);
        self::assertSame(1, $manifest['changed_cells']);
        $before = iterator_to_array((new CsvReader)->records($input));
        $after = iterator_to_array((new CsvReader)->records($changed));
        self::assertSame($before[0]->raw(), $after[0]->raw());
        $targetIndex = array_search($column, $format->headers, true);
        self::assertSame($value, $after[1]->values[$targetIndex]);
        foreach ($before[1]->rawCells as $index => $cell) {
            if ($index !== $targetIndex) {
                self::assertSame($cell, $after[1]->rawCells[$index]);
            }
        }
        self::assertSame($bytes, file_get_contents($input), 'Original bytes must remain unchanged.');
    }

    public function test_optional_quotes_spaces_and_unchanged_replacements_are_preserved(): void
    {
        $format = FormatRegistry::get('incident');
        $row = $this->row($format);
        $row['CODEVALUE'] = ' A ';
        $input = $this->file();
        $output = $this->file();
        $bytes = $this->csv($format->headers, false).$this->csv(array_values($row), false);
        file_put_contents($input, $bytes);
        (new RawExporter)->export($input, $output, fn () => ['TEXT_FR' => $row['TEXT_FR']], $format);
        self::assertSame($bytes, file_get_contents($output));
    }

    public function test_offsets_support_resumption_and_direct_record_reading(): void
    {
        $path = $this->file();
        $raw = "a;b\r\n\"une\nvaleur\";\"b\"\r\nc;d";
        file_put_contents($path, $raw);
        $reader = new CsvReader;
        $records = iterator_to_array($reader->records($path));
        self::assertCount(3, $records);
        self::assertSame("une\nvaleur", $records[1]->values[0]);
        foreach ($records as $record) {
            self::assertSame(substr($raw, $record->offset, $record->length), $record->raw());
            self::assertSame($record->raw(), $reader->readAt($path, $record->offset, $record->number, $record->length)->raw());
        }
        $resumed = iterator_to_array($reader->records($path, $records[2]->offset, 2));
        self::assertCount(1, $resumed);
        self::assertSame('c;d', $resumed[2]->raw());
    }

    public function test_quoted_records_can_span_multiple_buffer_chunks(): void
    {
        $path = $this->file();
        $value = str_repeat('x', 65_533).'"'.str_repeat('é', 70_000)."\r\nfin";
        $raw = $this->csv([$value, ''], true);
        file_put_contents($path, $raw);
        $record = (new CsvReader)->readAt($path, 0, 0);
        self::assertSame([$value, ''], $record->values);
        self::assertSame($raw, $record->raw());
    }

    public function test_exact_identity_keeps_case_empty_components_and_trailing_spaces(): void
    {
        $format = FormatRegistry::get('form');
        $row = $this->row($format);
        $row['Form Type'] = '';
        $row['Control Name'] = '';
        $row['Form Template Name'] = 'Abc ';
        self::assertSame(['', 'Abc ', '', 'label'], $format->identity($row));
        $hash = $format->identityHash($row);
        $row['Form Template Name'] = 'abc ';
        self::assertNotSame($hash, $format->identityHash($row));
        $row['Form Template Name'] = 'Abc';
        self::assertNotSame($hash, $format->identityHash($row));
    }

    public function test_language_pair_mapping_uses_codes_in_each_row_and_preserves_sa_in(): void
    {
        $format = FormatRegistry::get('form');
        $row = $this->row($format);
        $row['Language 1'] = 'fr_CH';
        $row['Language 2'] = 'it_CH';
        $attributes = $format->attributes($row)['label'];
        self::assertSame('Translation 1', $attributes['columns']['fr']);
        self::assertSame('Translation 2', $attributes['columns']['it']);
        self::assertNotContains('Translation 4', $format->editableColumns($row));
        self::assertSame('de', $attributes['reference_language']);
    }

    public function test_reference_fallbacks_and_hashes_ignore_import_trace_and_target_changes(): void
    {
        $format = FormatRegistry::get('mric');
        $row = $this->row($format);
        $row['de_CH'] = '';
        $row['Default'] = 'Default source';
        self::assertSame('Default source', $format->attributes($row)['label']['reference']);
        $sourceHash = $format->sourceHash($row);
        $semanticHash = $format->semanticHash($row);
        $row['fr_CH'] = 'Cible modifiée';
        $row['Update Time'] = '26.09.2026 18:02';
        $row['Update User'] = 'different';
        self::assertSame($sourceHash, $format->sourceHash($row));
        self::assertSame($semanticHash, $format->semanticHash($row));
        $row['Default'] = '';
        $row['Caption'] = 'Last fallback';
        self::assertSame('Last fallback', $format->attributes($row)['label']['reference']);
        self::assertNotSame($sourceHash, $format->sourceHash($row));
    }

    public static function forbiddenColumns(): iterable
    {
        yield ['Update User'];
        yield ['sa_IN'];
        yield ['Name'];
    }

    #[DataProvider('forbiddenColumns')]
    public function test_export_rejects_technical_and_non_target_cells_and_removes_partial_file(string $column): void
    {
        $format = FormatRegistry::get('mric');
        $path = $this->file();
        $output = $this->file();
        file_put_contents($path, $this->csv($format->headers, true).$this->csv(array_values($this->row($format)), true));
        try {
            (new RawExporter)->export($path, $output, fn () => [$column => 'Changed'], $format);
            self::fail('Technical replacement should fail.');
        } catch (InvalidArgumentException) {
            self::assertFileDoesNotExist($output);
        }
    }

    public function test_unrepresentable_text_is_rejected_without_substitution(): void
    {
        self::assertSame('Élément € œ', Encoding::decode(Encoding::encode('Élément € œ')));
        $this->expectException(InvalidArgumentException::class);
        Encoding::encode('Police 🚓');
    }

    public static function malformedCsv(): iterable
    {
        yield ['"not closed'];
        yield ['unquoted"quote;cell'];
        yield ['"closed"unexpected;cell'];
        yield ["cell\rnot lf"];
        yield ["\xEF\xBB\xBFheader"];
    }

    #[DataProvider('malformedCsv')]
    public function test_malformed_csv_is_rejected(string $bytes): void
    {
        $path = $this->file();
        file_put_contents($path, $bytes);
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new CsvReader)->records($path));
    }

    public function test_large_record_limit_protects_worker_memory(): void
    {
        $path = $this->file();
        file_put_contents($path, str_repeat('x', 200));
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new CsvReader(100))->records($path));
    }

    public function test_header_order_and_nls_ambiguity_require_explicit_format(): void
    {
        $format = FormatRegistry::get('mric');
        self::assertSame('mric', FormatRegistry::detect($format->headers, $format->filename)->code);
        $this->expectException(InvalidArgumentException::class);
        FormatRegistry::detect($format->headers);
    }

    public function test_placeholder_multisets_include_unnamed_named_and_square_tokens(): void
    {
        $source = '{} {field} [Tatbestand] {} %1$s';
        self::assertSame([], TranslationValidator::validate($source, 'Texte {} [Tatbestand] %1$s {field} {}')['errors']);
        self::assertNotEmpty(TranslationValidator::validate($source, '{} {field} [Tatbestand] %1$s')['errors']);
        self::assertNotEmpty(TranslationValidator::validate($source, '{} {} {other} [Tatbestand] %1$s')['errors']);
        self::assertNotEmpty(TranslationValidator::validate('Référence', '  ')['errors']);
        self::assertNotEmpty(TranslationValidator::validate('Référence', '🚓')['errors']);
        self::assertNotEmpty(TranslationValidator::validate('Référence', 'Référence')['warnings']);
    }

    public function test_scope_is_only_a_suggestion_with_configurable_mapping(): void
    {
        $format = FormatRegistry::get('form');
        $row = $this->row($format);
        $row['Form Template Name'] = 'AG_Form';
        self::assertSame('AG', $format->suggestedScope($row));
        self::assertSame('custom', $format->suggestedScope($row, ['AG_' => 'custom']));
        self::assertNull($format->suggestedScope($row, ['FR_' => 'FR']));
    }

    public function test_unresolved_status_prefixes_are_detected_without_changing_exact_keys(): void
    {
        foreach (['form' => 'Form Template Name', 'workflow' => 'Workflow'] as $type => $column) {
            $format = FormatRegistry::get($type);
            foreach (['PHV_template' => 'PHV', 'wip-template' => 'WIP', 'Test template' => 'TEST', 'OLD' => 'OLD', 'old_template' => 'OLD'] as $name => $scope) {
                $row = $this->row($format);
                $row[$column] = $name;
                $key = $format->identity($row);
                self::assertSame($scope, $format->suggestedScope($row));
                self::assertSame($key, $format->identity($row));
            }
            foreach (['Testament', 'Oldé', 'WIP2_template', 'Shared_PHV_template'] as $name) {
                $row[$column] = $name;
                self::assertNull($format->suggestedScope($row));
            }
            $row[$column] = 'PHV_template';
            self::assertNull($format->suggestedScope($row, [], []));
            $row[$column] = 'draft-template';
            self::assertSame('DRAFT', $format->suggestedScope($row, [], ['DRAFT']));
        }
        $format = FormatRegistry::get('mric');
        $row = $this->row($format);
        $row['Name'] = 'PHV_key';
        self::assertNull($format->suggestedScope($row));
    }

    private function file(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'devconf-test-'.bin2hex(random_bytes(12)).'.csv';
        $this->files[] = $path;

        return $path;
    }

    private function csv(array $values, bool $all): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $bytes = Encoding::encode($value);
            $quoted = $all || $index % 2 === 0 || strpbrk($bytes, ";\"\r\n") !== false;
            $cells[] = $quoted ? '"'.str_replace('"', '""', $bytes).'"' : $bytes;
        }

        return implode(';', $cells)."\r\n";
    }

    private function row(FormatDefinition $format): array
    {
        $row = array_fill_keys($format->headers, 'original');
        if ($format->layout === 'pairs') {
            $row['Default Language'] = 'de_CH';
            $row['Default Label'] = 'Referenz {}';
            foreach (['it_CH', 'fr_CH', 'en_US', 'sa_IN'] as $index => $language) {
                $row['Language '.($index + 1)] = $language;
            }
            $row[$format->code === 'form' ? 'Column Name' : 'Column'] = 'label';
        }

        return $row;
    }
}
