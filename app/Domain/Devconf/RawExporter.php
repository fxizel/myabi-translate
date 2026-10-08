<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RawExporter
{
    public function __construct(private readonly CsvReader $reader = new CsvReader) {}

    /**
     * The caller resolves immutable validated cells, checks identity collisions and permissions.
     * A null resolver performs a complete byte-identical roundtrip, including ambiguous rows.
     * Destination must be a new private staging file. No incomplete output survives an error.
     *
     * @param  null|callable(CsvRecord, array<string,string>, FormatDefinition): array<string,string>  $replacementResolver
     * @return array{sha256: string, bytes: int, records: int, changed_cells: int, format: string, format_version: string}
     */
    public function export(string $source, string $destination, ?callable $replacementResolver = null, ?FormatDefinition $format = null): array
    {
        if (realpath($source) === realpath($destination)) {
            throw new InvalidArgumentException('L’original ne peut pas être remplacé.');
        }
        $output = @fopen($destination, 'x+b');
        if ($output === false) {
            throw new RuntimeException('La destination doit être un nouveau fichier privé accessible en écriture.');
        }
        $digest = hash_init('sha256');
        $bytes = $count = $changed = 0;
        $headers = null;

        try {
            foreach ($this->reader->records($source) as $record) {
                if ($record->number === 0) {
                    $headers = $record->values;
                    $format ??= FormatRegistry::detect($headers, basename($source));
                    $format->validateHeader($headers);
                    $raw = $record->raw();
                } else {
                    $row = $record->associative($headers);
                    $replacements = $replacementResolver === null ? [] : $replacementResolver($record, $row, $format);
                    if (! is_array($replacements)) {
                        throw new InvalidArgumentException('Les remplacements doivent être un tableau de colonnes.');
                    }
                    $cells = $record->rawCells;
                    if ($replacements !== []) {
                        $allowed = array_flip($format->editableColumns($row));
                        foreach ($replacements as $column => $value) {
                            if (! isset($allowed[$column]) || ! is_string($value)) {
                                throw new InvalidArgumentException('Modification d’une cellule technique ou d’une langue non autorisée.');
                            }
                            if ($row[$column] === $value) {
                                continue;
                            }
                            $index = array_search($column, $headers, true);
                            $encoded = Encoding::encode($value);
                            $quoted = $format->quoteAll || str_starts_with($cells[$index], '"') || strpbrk($encoded, ";\"\r\n") !== false;
                            $cells[$index] = $quoted ? '"'.str_replace('"', '""', $encoded).'"' : $encoded;
                            $changed++;
                        }
                    }
                    $raw = implode(';', $cells).$record->ending;
                    $count++;
                }
                $remaining = strlen($raw);
                $written = 0;
                while ($remaining > 0) {
                    $size = fwrite($output, substr($raw, $written));
                    if ($size === false || $size === 0) {
                        throw new RuntimeException('Écriture de la publication interrompue.');
                    }
                    $remaining -= $size;
                    $written += $size;
                }
                hash_update($digest, $raw);
                $bytes += $written;
            }
            if ($headers === null) {
                throw new InvalidArgumentException('Le fichier DEVCONF est vide.');
            }
            if (! fflush($output)) {
                throw new RuntimeException('Impossible de finaliser la publication.');
            }
            fclose($output);

            return ['sha256' => hash_final($digest), 'bytes' => $bytes, 'records' => $count, 'changed_cells' => $changed, 'format' => $format->code, 'format_version' => $format->version];
        } catch (Throwable $error) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($destination);
            throw $error;
        }
    }
}
