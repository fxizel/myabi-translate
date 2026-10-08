<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use InvalidArgumentException;

final readonly class CsvRecord
{
    /** @param list<string> $rawCells @param list<string> $values */
    public function __construct(
        public int $number,
        public int $offset,
        public int $length,
        public array $rawCells,
        public array $values,
        public string $ending,
    ) {}

    public function raw(): string
    {
        return implode(';', $this->rawCells).$this->ending;
    }

    /** @param list<string> $headers @return array<string, string> */
    public function associative(array $headers): array
    {
        if (count($headers) !== count($this->values)) {
            throw new InvalidArgumentException("Nombre de colonnes incorrect à l’enregistrement {$this->number}.");
        }

        return array_combine($headers, $this->values);
    }
}
