<?php

namespace Tests\Support;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Revision;
use App\Models\Term;
use App\Services\SourceRecord;

class ProposalScreenshotSource extends SourceRecord
{
    public function row(Term|Revision $item): array
    {
        return array_replace(array_fill_keys(FormatRegistry::get('server')->headers, ''), ['Name' => $item->key[0] ?? 'Synthetic key', 'de_CH' => 'Synthetic reference']);
    }

    public function attributes(Term|Revision $item): array
    {
        return ['label' => ['reference' => 'Synthetic reference', 'translations' => [], 'columns' => ['fr' => 'fr_CH', 'it' => 'it_CH']]];
    }
}
