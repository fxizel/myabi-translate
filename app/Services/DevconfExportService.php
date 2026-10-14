<?php

namespace App\Services;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\CsvRecord;
use App\Domain\Devconf\FormatDefinition;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/** Common catalogue selection and provenance checks for DEVCONF generation. */
class DevconfExportService
{
    /** Compatibility lookup for callers checking that a type has an applied source. */
    public function findSource(int $versionId, string $type, int $organizationId): ?ImportBatch
    {
        return ImportBatch::where('type', $type)->where('status', 'applied')->orderByDesc('catalogue_generation')->latest('id')->first();
    }

    public function assertRecipients(array $recipients): void
    {
        foreach ($recipients as $organizationId) {
            if (! Organisation::whereKey($organizationId)->where('active', true)->exists()) {
                $this->block('recipients', 'publication_recipients_required');
            }
        }
    }

    /** Plans retain source metadata only. Catalogue rows are read in batches. */
    public function plan(string $type, bool $publication = false): array
    {
        $format = FormatRegistry::get($type);
        $sources = [];
        $eligibleSources = [];
        $streams = [];
        $reader = new CsvReader;
        $count = $rowBytes = $replacementBytes = 0;
        $identities = [];
        foreach ($this->terms($type)->lazyById(config('referentiel.batch_size', 300), 'terms.id', 'id') as $term) {
            $key = json_decode($term->term_key, true, flags: JSON_THROW_ON_ERROR);
            // An index is only a candidate selector: compare the exact key too.
            $hash = hash('sha256', json_encode([$type, $key], JSON_THROW_ON_ERROR), true);
            foreach (isset($identities[$hash]) ? (array) $identities[$hash] : [] as $previousId) {
                $previous = DB::table('terms')->where('id', $previousId)->value('key');
                if (json_decode($previous, true, flags: JSON_THROW_ON_ERROR) === $key) {
                    $this->block('source', 'publication_collision');
                }
            }
            $identities[$hash] = isset($identities[$hash]) ? [...(array) $identities[$hash], (int) $term->id] : (int) $term->id;
            $validated = json_decode($term->validated ?? $term->term_validated, true, flags: JSON_THROW_ON_ERROR);
            if ($publication && ! $this->hasValidationCandidate($validated, $format)) {
                continue;
            }
            if (! $term->revision_id) {
                $this->block('source', 'publication_identity_mismatch');
            }
            $sourceId = (int) $term->source_import_id;
            if (! isset($sources[$sourceId])) {
                $source = ImportBatch::find($sourceId);
                if (! $source || $source->status !== 'applied' || $source->type !== $type) {
                    $this->block('source', 'publication_source_missing');
                }
                $sources[$sourceId] = $this->checkedSource($source, $format);
            }
            if ($publication) {
                $record = $this->sourceRecord($term, $sources[$sourceId]['import'], $reader, $streams);
                if (! $this->hasTargetValidation($validated, $format, $record->associative($format->headers))) {
                    continue;
                }
            }
            $eligibleSources[$sourceId] = true;
            $count++;
            $rowBytes += (int) $term->source_length;
            foreach ($format->attributeNames as $attribute) {
                foreach ($validated[$attribute] ?? [] as $language => $value) {
                    if (isset(FormatDefinition::LANGUAGES[$language]) && is_string($value)) {
                        $replacementBytes += strlen($value) * 2 + 2;
                    }
                }
            }
        }
        $selectedSources = array_intersect_key($sources, $eligibleSources);
        if ($selectedSources === []) {
            $source = ImportBatch::where('type', $type)->where('status', 'applied')->orderBy('id')->first();
            if (! $source) {
                $this->block('source', 'publication_source_missing');
            }
            $sources[$source->id] = $this->checkedSource($source, $format);
            $selectedSources[$source->id] = $sources[$source->id];
        }
        ksort($selectedSources, SORT_NUMERIC);

        return ['type' => $type, 'publication' => $publication, 'sources' => $sources,
            'used_sources' => array_keys($selectedSources),
            'header' => reset($selectedSources)['header'], 'records' => $count, 'source_bytes' => $rowBytes + $replacementBytes];
    }

    /** Emit every eligible current term once, in stable catalogue order. */
    public function records(array $plan, $manifest, array &$coverage, int &$count): Generator
    {
        $format = FormatRegistry::get($plan['type']);
        $reader = new CsvReader;
        $streams = [];
        $written = 0;
        foreach (FormatDefinition::LANGUAGES as $language => $_) {
            $coverage[$language] ??= ['validated' => 0, 'fallback' => 0, 'empty' => 0, 'review' => 0];
        }
        foreach ($this->terms($plan['type'])->lazyById(config('referentiel.batch_size', 300), 'terms.id', 'id') as $term) {
            $validated = json_decode($term->validated ?? $term->term_validated, true, flags: JSON_THROW_ON_ERROR);
            if ($plan['publication'] && ! $this->hasValidationCandidate($validated, $format)) {
                continue;
            }
            $source = $plan['sources'][(int) $term->source_import_id]['import'] ?? null;
            if (! $source || ! $term->revision_id) {
                $this->block('source', 'publication_identity_mismatch');
            }
            $record = $this->sourceRecord($term, $source, $reader, $streams);
            $row = $record->associative($format->headers);
            if ($plan['publication'] && ! $this->hasTargetValidation($validated, $format, $row)) {
                continue;
            }
            $review = json_decode($term->review_needed, true, flags: JSON_THROW_ON_ERROR);
            $replacements = [];
            foreach ($format->attributes($row) as $name => $attribute) {
                foreach ($attribute['columns'] as $language => $column) {
                    $coverage[$language] ??= ['validated' => 0, 'fallback' => 0, 'empty' => 0, 'review' => 0];
                    if ($plan['publication'] && $this->isReferenceColumn($format, $column)) {
                        $coverage[$language]['fallback']++;

                        continue;
                    }
                    if (isset($validated[$name][$language])) {
                        $value = $validated[$name][$language];
                        if (! is_string($value)) {
                            throw new InvalidArgumentException('Invalid validated cell.');
                        }
                        TranslationValidator::assertValid($attribute['reference'], $value);
                        $replacements[$column] = $value;
                        $coverage[$language]['validated']++;
                        if ($review[$name][$language] ?? false) {
                            $coverage[$language]['review']++;
                        }
                    } elseif ($plan['publication']) {
                        $replacements[$column] = '';
                        $coverage[$language]['empty']++;
                    } else {
                        $coverage[$language]['fallback']++;
                    }
                }
            }
            $written++;
            if ($manifest !== null) {
                $line = json_encode(['type' => $format->code, 'record' => $written, 'term_id' => (int) $term->id,
                    'revision_id' => (int) $term->revision_id, 'revision' => (int) $term->revision_no,
                    'source_import_id' => $source->id, 'source_sha256' => $source->sha256,
                    'source_record_number' => (int) $term->record_number,
                    'source_offset' => $record->offset, 'source_length' => $record->length], JSON_THROW_ON_ERROR)."\n";
                if (fwrite($manifest, $line) !== strlen($line)) {
                    throw new RuntimeException('Cannot write revision manifest.');
                }
            }
            $count++;
            yield ['record' => $record, 'replacements' => $replacements];
        }
        if ($written !== $plan['records']) {
            $this->block('source', 'publication_source_count');
        }
    }

    public function sourceManifest(array $plan): array
    {
        return array_map(static function ($id) use ($plan) {
            $source = $plan['sources'][$id]['import'];

            return ['import_id' => $source->id, 'sha256' => $source->sha256, 'format_version' => $source->format_version];
        }, $plan['used_sources']);
    }

    private function terms(string $type)
    {
        return DB::table('terms')->leftJoin('revisions', function ($join) {
            $join->on('revisions.term_id', '=', 'terms.id')->on('revisions.number', '=', 'terms.revision_no');
        })->leftJoin('import_rows', function ($join) {
            $join->on('import_rows.import_id', '=', 'revisions.source_import_id')->on('import_rows.offset', '=', 'revisions.source_offset');
        })->where('terms.type', $type)->whereNull('terms.consolidated_into_id')
            ->select(['terms.id', 'terms.key as term_key', 'terms.validated as term_validated', 'terms.review_needed', 'terms.revision_no',
                'revisions.id as revision_id', 'revisions.validated', 'revisions.metadata',
                'revisions.source_import_id', 'revisions.source_offset', 'revisions.source_length', 'import_rows.record_number',
                'import_rows.term_id as presence_term_id', 'import_rows.length as presence_length', 'import_rows.collision']);
    }

    private function checkedSource(ImportBatch $source, FormatDefinition $format): array
    {
        if ($source->format_version !== $format->version) {
            $this->block('source', 'publication_format_changed');
        }
        if (! is_file($source->originalPath()) || hash_file('sha256', $source->originalPath()) !== $source->sha256) {
            $this->block('source', 'publication_source_checksum');
        }
        $rows = DB::table('import_rows')->where('import_id', $source->id);
        if ((clone $rows)->where('collision', true)->exists()) {
            $this->block('source', 'publication_collision');
        }
        if ((clone $rows)->whereNull('term_id')->exists() || (clone $rows)->count() !== $source->row_count) {
            $this->block('source', 'publication_source_count');
        }
        $header = null;
        $actualCount = 0;
        foreach ((new CsvReader)->records($source->originalPath()) as $record) {
            if ($record->number === 0) {
                $header = $record;
                $format->validateHeader($header->values);
            } else {
                $record->associative($format->headers);
                $actualCount++;
            }
        }
        if ($header === null || $actualCount !== $source->row_count) {
            $this->block('source', 'publication_source_count');
        }

        return ['import' => $source, 'header' => $header];
    }

    private function hasValidationCandidate(array $validated, FormatDefinition $format): bool
    {
        foreach ($format->attributeNames as $attribute) {
            $languages = $validated[$attribute] ?? [];
            foreach ($languages as $language => $value) {
                if ($value !== null && isset(FormatDefinition::LANGUAGES[$language]) && ($language !== 'de' || $format->layout === 'pairs')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasTargetValidation(array $validated, FormatDefinition $format, array $row): bool
    {
        foreach ($format->attributes($row) as $name => $attribute) {
            foreach ($attribute['columns'] as $language => $column) {
                if (! $this->isReferenceColumn($format, $column) && isset($validated[$name][$language])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isReferenceColumn(FormatDefinition $format, string $column): bool
    {
        return match ($format->layout) {
            'pairs' => $column === 'Default Label',
            'nls' => $column === 'de_CH',
            'law' => str_ends_with($column, ' de_CH'),
            default => str_ends_with($column, '_DE'),
        };
    }

    private function sourceRecord(object $term, ImportBatch $source, CsvReader $reader, array &$streams): CsvRecord
    {
        $metadata = json_decode($term->metadata, true, flags: JSON_THROW_ON_ERROR);
        $key = json_decode($term->term_key, true, flags: JSON_THROW_ON_ERROR);
        if (! $term->record_number || $term->collision || (int) $term->presence_term_id !== (int) $term->id
            || (int) $term->presence_length !== (int) $term->source_length || ($metadata['key'] ?? null) !== $key) {
            $this->block('source', 'publication_identity_mismatch');
        }
        // Keep a bounded set of readers rather than opening an original per row.
        $stream = $streams[$source->id] ?? null;
        unset($streams[$source->id]);
        if ($stream === null || ! $stream->valid() || $stream->current()->number > (int) $term->record_number) {
            $stream = $reader->records($source->originalPath());
        }
        while ($stream->valid() && $stream->current()->number < (int) $term->record_number) {
            $stream->next();
        }
        if (! $stream->valid()) {
            $this->block('source', 'publication_identity_mismatch');
        }
        $record = $stream->current();
        $format = FormatRegistry::get($source->type);
        if ($record->offset !== (int) $term->source_offset || $record->length !== (int) $term->source_length
            || $key !== $format->identity($record->associative($format->headers))) {
            $this->block('source', 'publication_identity_mismatch');
        }
        $streams[$source->id] = $stream;
        if (count($streams) > 16) {
            unset($streams[array_key_first($streams)]);
        }

        return $record;
    }

    public function assertCurrentVersion(MyabiVersion $version): void
    {
        $latest = MyabiVersion::whereIn('id', ImportBatch::where('status', 'applied')->select('version_id'))->max('released_at');
        if ($version->status === 'archived' || ($latest && $version->released_at->format('Y-m-d') < Carbon::parse($latest)->format('Y-m-d'))) {
            $this->block('version_id', 'publication_old_version');
        }
    }

    private function block(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('ui.'.$key)]);
    }
}
