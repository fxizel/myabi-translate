<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/** Streaming CSV parser retaining byte offsets and the exact spelling of every cell. */
final class CsvReader
{
    public function __construct(private readonly int $maxRecordBytes = 16_777_216) {}

    public function readAt(string $path, int $offset, int $number = 1, ?int $expectedLength = null): CsvRecord
    {
        foreach ($this->records($path, $offset, $number) as $record) {
            if ($expectedLength !== null && $record->length !== $expectedLength) {
                throw new InvalidArgumentException('La position conservée ne correspond plus au fichier source.');
            }

            return $record;
        }

        throw new InvalidArgumentException('Aucun enregistrement à cette position.');
    }

    /**
     * Resume only at an offset emitted by a previous completed record.
     * Record 0 is the header; data records start at 1.
     *
     * @return Generator<int, CsvRecord>
     */
    public function records(string $path, int $offset = 0, int $startRecord = 0): Generator
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Impossible de lire le fichier DEVCONF.');
        }

        try {
            if ($offset < 0 || $startRecord < 0 || fseek($stream, $offset) !== 0) {
                throw new InvalidArgumentException('Position de reprise invalide.');
            }
            $state = 0; // start, unquoted, quoted, closed quote, CR outside quotes
            $cell = '';
            $cells = [];
            $length = 0;
            $number = $startRecord;
            $recordOffset = $offset;

            while (($chunk = fgets($stream, 65_536)) !== false) {
                if ($recordOffset === 0 && $length === 0 && str_starts_with($chunk, "\xEF\xBB\xBF")) {
                    throw new InvalidArgumentException('Un fichier Windows-1252 sans BOM est requis.');
                }
                for ($cursor = 0, $size = strlen($chunk); $cursor < $size;) {
                    if ($state !== 3 && $state !== 4) {
                        $run = strcspn($chunk, $state === 2 ? '"' : ";\"\r\n", $cursor);
                        if ($run > 0) {
                            $cell .= substr($chunk, $cursor, $run);
                            $cursor += $run;
                            $length += $run;
                            $state = $state === 0 ? 1 : $state;
                            $this->checkLength($length, $number);
                            if ($cursor === $size) {
                                break;
                            }
                        }
                    }
                    $char = $chunk[$cursor++];
                    $length++;
                    $this->checkLength($length, $number);

                    if ($state === 4) {
                        if ($char !== "\n") {
                            throw new InvalidArgumentException("Fin de ligne CR isolée à l’enregistrement {$number}.");
                        }
                        $cells[] = $cell;
                        yield $number => $this->record($number, $recordOffset, $length, $cells, "\r\n");
                        $recordOffset += $length;
                        $number++;
                        $length = 0;
                        $state = 0;
                        $cell = '';
                        $cells = [];
                    } elseif ($state === 2) {
                        $cell .= $char;
                        $state = 3; // strcspn stopped at a quote
                    } elseif ($char === '"') {
                        if ($state === 1) {
                            throw new InvalidArgumentException("Guillemet inattendu à l’enregistrement {$number}.");
                        }
                        $cell .= $char;
                        $state = 2;
                    } elseif ($char === ';') {
                        $cells[] = $cell;
                        $cell = '';
                        $state = 0;
                    } elseif ($char === "\r") {
                        $state = 4;
                    } elseif ($char === "\n") {
                        $cells[] = $cell;
                        yield $number => $this->record($number, $recordOffset, $length, $cells, "\n");
                        $recordOffset += $length;
                        $number++;
                        $length = 0;
                        $state = 0;
                        $cell = '';
                        $cells = [];
                    } else {
                        throw new InvalidArgumentException("Caractère après un guillemet fermé à l’enregistrement {$number}.");
                    }
                }
            }
            if (! feof($stream)) {
                throw new RuntimeException('Lecture du fichier DEVCONF interrompue.');
            }
            if ($state === 2 || $state === 4) {
                throw new InvalidArgumentException("Enregistrement {$number} incomplet.");
            }
            if ($length > 0) {
                $cells[] = $cell;
                yield $number => $this->record($number, $recordOffset, $length, $cells, '');
            }
        } finally {
            fclose($stream);
        }
    }

    private function checkLength(int $length, int $number): void
    {
        if ($length > $this->maxRecordBytes) {
            throw new InvalidArgumentException("Enregistrement {$number} trop volumineux.");
        }
    }

    private function record(int $number, int $offset, int $length, array $cells, string $ending): CsvRecord
    {
        $values = [];
        foreach ($cells as $cell) {
            if (str_starts_with($cell, '"')) {
                $cell = str_replace('""', '"', substr($cell, 1, -1));
            }
            $values[] = Encoding::decode($cell);
        }

        return new CsvRecord($number, $offset, $length, $cells, $values, $ending);
    }
}
