<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use InvalidArgumentException;

final readonly class FormatDefinition
{
    public const VERSION = '2026-09-21.1';

    public const LANGUAGES = ['de' => 'de_CH', 'fr' => 'fr_CH', 'it' => 'it_CH', 'en' => 'en_US'];

    public const UNCONFIRMED_SCOPE_PREFIXES = ['PHV', 'WIP', 'TEST', 'OLD'];

    public function __construct(
        public string $code,
        public string $label,
        public string $filename,
        public array $headers,
        public array $identityColumns,
        public array $attributeNames,
        public string $layout,
        public bool $quoteAll = true,
        public bool $visibleInMyabi = true,
        public string $version = self::VERSION,
    ) {}

    public function validateHeader(array $headers): void
    {
        if ($headers !== $this->headers) {
            throw new InvalidArgumentException("L’en-tête et l’ordre des colonnes ne correspondent pas au format {$this->label} ({$this->version}).");
        }
    }

    /** Exact composite identity: no lowercasing, trimming or lossy separator join. */
    public function identity(array $row): array
    {
        $identity = [];
        foreach ($this->identityColumns as $column) {
            if (! array_key_exists($column, $row)) {
                throw new InvalidArgumentException('Colonne d’identité absente.');
            }
            $identity[] = $row[$column];
        }

        return $identity;
    }

    public function identityHash(array $row): string
    {
        return hash('sha256', json_encode([$this->code, $this->identity($row)], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{reference: string, reference_language: ?string, translations: array<string,string>, columns: array<string,string>}> */
    public function attributes(array $row): array
    {
        $attributes = [];
        foreach ($this->attributeNames as $attribute) {
            $columns = [];
            $referenceLanguage = 'de';
            if ($this->layout === 'nls') {
                $columns = self::LANGUAGES;
                $reference = $row['de_CH'] !== '' ? $row['de_CH'] : ($row['Default'] !== '' ? $row['Default'] : $row['Caption']);
                $referenceLanguage = $row['de_CH'] !== '' ? 'de' : null;
            } elseif ($this->layout === 'pairs') {
                $reference = $row['Default Label'];
                $referenceLanguage = array_search($row['Default Language'], self::LANGUAGES, true);
                $referenceLanguage = $referenceLanguage === false ? null : $referenceLanguage;
                if ($referenceLanguage !== null) {
                    $columns[$referenceLanguage] = 'Default Label';
                }
                for ($index = 1; $index <= 4; $index++) {
                    $language = array_search($row["Language {$index}"], self::LANGUAGES, true);
                    if ($language !== false) {
                        if (isset($columns[$language])) {
                            throw new InvalidArgumentException('Plusieurs colonnes désignent la même langue.');
                        }
                        $columns[$language] = "Translation {$index}";
                    }
                }
            } elseif ($this->layout === 'law') {
                foreach (self::LANGUAGES as $language => $external) {
                    $columns[$language] = "{$attribute} {$external}";
                }
                $reference = $row["{$attribute} de_CH"] !== '' ? $row["{$attribute} de_CH"] : $row["{$attribute} Default"];
                $referenceLanguage = $row["{$attribute} de_CH"] !== '' ? 'de' : null;
            } else {
                foreach (self::LANGUAGES as $language => $_) {
                    $columns[$language] = $attribute.'_'.strtoupper($language);
                }
                $reference = $row[$attribute.'_DE'];
            }
            $translations = [];
            foreach ($columns as $language => $column) {
                $translations[$language] = $row[$column];
            }
            $attributes[$attribute] = [
                'reference' => $reference,
                'reference_language' => $referenceLanguage,
                'translations' => $translations,
                'columns' => $columns,
            ];
        }

        return $attributes;
    }

    /** Only known language-value cells can ever be replaced, never sa_IN or technical cells. */
    public function editableColumns(array $row): array
    {
        $columns = [];
        foreach ($this->attributes($row) as $attribute) {
            $columns = [...$columns, ...array_values($attribute['columns'])];
        }

        return array_values(array_unique($columns));
    }

    public function sourceHash(array $row): string
    {
        $references = [];
        foreach ($this->attributes($row) as $name => $attribute) {
            $references[$name] = [$attribute['reference'], $attribute['reference_language']];
        }

        return hash('sha256', json_encode($references, JSON_THROW_ON_ERROR));
    }

    /** Business metadata fingerprint; translation values and import audit columns are excluded. */
    public function semanticHash(array $row): string
    {
        $exclude = $this->editableColumns($row);
        foreach ($row as $column => $_) {
            $normalized = strtolower(str_replace([' ', '_'], '', $column));
            if (in_array($normalized, ['createtime', 'createuser', 'createdt', 'updatetime', 'updateuser', 'updatedt'], true)) {
                $exclude[] = $column;
            }
        }

        return hash('sha256', json_encode(array_diff_key($row, array_flip($exclude)), JSON_THROW_ON_ERROR));
    }

    /** Suggestion only: callers retain the explicit, editable scope of existing terms. */
    public function suggestedScope(array $row, array $prefixes = [], array $unconfirmedPrefixes = self::UNCONFIRMED_SCOPE_PREFIXES): ?string
    {
        $value = match ($this->code) {
            'form' => $row['Form Template Name'] ?? '',
            'workflow' => $row['Workflow'] ?? '',
            default => '',
        };
        // These status markers have no agreed scope (functional question 15.8).
        // Recognizing their spelling never normalizes the exact business key.
        foreach ($unconfirmedPrefixes as $prefix) {
            if (preg_match('/\A'.preg_quote($prefix, '/').'(?:[^\p{L}\p{N}]|$)/iu', $value) === 1) {
                return $prefix;
            }
        }
        if ($prefixes === []) {
            $prefixes = array_combine(['AG_', 'AI_', 'AR_', 'GR_', 'LU_', 'SG_', 'ZG_'], ['AG', 'AI', 'AR', 'GR', 'LU', 'SG', 'ZG']);
        }
        foreach ($prefixes as $prefix => $scope) {
            if (str_starts_with($value, $prefix)) {
                return $scope;
            }
        }

        return null;
    }
}
