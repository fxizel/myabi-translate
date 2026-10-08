<?php

namespace App\Services;

use App\Domain\Devconf\CsvRecord;
use App\Domain\Devconf\FormatDefinition;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Shared source and revision checks for DEVCONF publications and simple exports. */
class DevconfExportService
{
    public function findSource(int $versionId, string $type, int $organizationId): ?ImportBatch
    {
        $query = ImportBatch::where('version_id', $versionId)->where('type', $type)->where('status', 'applied');

        return (clone $query)->where('source_kind', 'organization')->where('organization_id', $organizationId)->orderByDesc('catalogue_generation')->latest('id')->first()
            ?? (clone $query)->where('source_kind', 'publisher')->orderByDesc('catalogue_generation')->latest('id')->first();
    }

    public function selectSources(int $versionId, array $types, array $recipients): array
    {
        $sources = [];
        foreach ($recipients as $organizationId) {
            if (! Organisation::whereKey($organizationId)->where('active', true)->exists()) {
                $this->block('recipients', 'publication_recipients_required');
            }
            foreach ($types as $type) {
                $source = $this->findSource($versionId, $type, $organizationId);
                if (! $source) {
                    $this->block('source', 'publication_source_missing');
                }
                if ($source->format_version !== FormatRegistry::get($type)->version) {
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
                $terms = DB::table('terms')->whereIn('id', (clone $rows)->select('term_id'));
                if ((clone $terms)->where('scope_unconfirmed', true)->exists()) {
                    $this->block('scope', 'publication_scope_unconfirmed');
                }
                if (CommonScope::outsideOrganization(clone $terms, $organizationId)->exists()) {
                    $this->block('scope', 'publication_foreign_scope');
                }
                // Every active term in the recipient's scope needs a source row.
                // Historical obsolescence and other organizations' private terms
                // do not add missing-row requirements.
                $knownTerms = DB::table('import_rows')->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')
                    ->join('terms as known_term', 'known_term.id', '=', 'import_rows.term_id')
                    ->where('import_batches.version_id', $versionId)->where('import_batches.type', $type)
                    ->where('import_batches.status', 'applied')->whereNotNull('import_rows.term_id')
                    ->where('known_term.obsolete', false)
                    ->whereNotIn('import_rows.term_id', (clone $rows)->select('term_id'));
                CommonScope::forOrganization($knownTerms, $organizationId, 'known_term.organization_id');
                if ($knownTerms->exists()) {
                    $this->block('source', 'publication_source_missing_rows');
                }
                $sources[$organizationId][$type] = $source;
            }
        }

        return $sources;
    }

    public function resolver(ImportBatch $source, FormatDefinition $format, $manifest, array &$coverage, int &$count): callable
    {
        $cache = [];

        return function (CsvRecord $record, array $row) use ($source, $format, $manifest, &$coverage, &$count, &$cache): array {
            if (! isset($cache[$record->number])) {
                $cache = DB::table('import_rows')->join('terms', 'terms.id', '=', 'import_rows.term_id')
                    ->leftJoin('revisions', function ($join) {
                        $join->on('revisions.term_id', '=', 'terms.id')->on('revisions.number', '=', 'terms.revision_no');
                    })
                    ->where('import_rows.import_id', $source->id)->where('import_rows.record_number', '>=', $record->number)
                    ->orderBy('import_rows.record_number')->limit(config('referentiel.batch_size', 300))
                    ->get(['import_rows.record_number', 'import_rows.offset', 'import_rows.length', 'import_rows.term_id', 'import_rows.collision',
                        'terms.key as term_key', 'terms.review_needed', 'terms.revision_no', 'revisions.id as revision_id', 'revisions.validated'])
                    ->keyBy('record_number')->all();
            }
            $presence = $cache[$record->number] ?? null;
            if (! $presence || ! $presence->revision_id || $presence->collision || (int) $presence->offset !== $record->offset
                || (int) $presence->length !== $record->length || json_decode($presence->term_key, true, flags: JSON_THROW_ON_ERROR) !== $format->identity($row)) {
                $this->block('source', 'publication_identity_mismatch');
            }
            $validated = json_decode($presence->validated, true, flags: JSON_THROW_ON_ERROR);
            $review = json_decode($presence->review_needed, true, flags: JSON_THROW_ON_ERROR);
            $replacements = [];
            foreach ($format->attributes($row) as $name => $attribute) {
                foreach ($attribute['columns'] as $language => $column) {
                    $coverage[$language] ??= ['validated' => 0, 'fallback' => 0, 'review' => 0];
                    if (array_key_exists($language, $validated[$name] ?? [])) {
                        $value = $validated[$name][$language];
                        TranslationValidator::assertValid($attribute['reference'], $value);
                        $replacements[$column] = $value;
                        $coverage[$language]['validated']++;
                        if ($review[$name][$language] ?? false) {
                            $coverage[$language]['review']++;
                        }
                    } else {
                        $coverage[$language]['fallback']++;
                    }
                }
            }
            if ($manifest !== null) {
                $line = json_encode(['type' => $format->code, 'record' => $record->number, 'term_id' => $presence->term_id,
                    'revision_id' => $presence->revision_id, 'revision' => $presence->revision_no,
                    'fallback_import_id' => $source->id, 'fallback_offset' => $record->offset, 'fallback_length' => $record->length], JSON_THROW_ON_ERROR)."\n";
                if (fwrite($manifest, $line) !== strlen($line)) {
                    throw new RuntimeException('Cannot write revision manifest.');
                }
            }
            $count++;

            return $replacements;
        };
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
