<?php

namespace App\Services;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\FormatRegistry;
use App\Models\Revision;
use App\Models\Term;

class SourceRecord
{
    public function row(Term|Revision $item): array
    {
        $import = $item->sourceImport;
        $type = $item instanceof Term ? $item->type : $item->term->type;
        $format = FormatRegistry::get($type);

        return (new CsvReader)->readAt($import->originalPath(), $item->source_offset, 1, $item->source_length)->associative($format->headers);
    }

    public function attributes(Term|Revision $item): array
    {
        $type = $item instanceof Term ? $item->type : $item->term->type;

        return FormatRegistry::get($type)->attributes($this->row($item));
    }

    public function snapshot(Term $term, string $origin, int $originId, int $authorId, ?int $versionId = null): Revision
    {
        return Revision::create(['term_id' => $term->id, 'number' => $term->revision_no, 'version_id' => $versionId ?? $term->sourceImport->version_id,
            'source_import_id' => $term->source_import_id, 'source_offset' => $term->source_offset, 'source_length' => $term->source_length,
            'validated' => $term->validated, 'metadata' => ['organization_id' => $term->organization_id, 'key' => $term->key, 'obsolete' => $term->obsolete, 'review_needed' => $term->review_needed],
            'origin' => $origin, 'origin_id' => $originId, 'created_by' => $authorId, 'created_at' => now()]);
    }
}
