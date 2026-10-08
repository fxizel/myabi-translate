<?php

namespace App\Services;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\FormatDefinition;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ImportService
{
    private array $organizations = [];

    private array $provenanceIds = [];

    public function __construct(private StorageBudget $budget, private AuditService $audit) {}

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function receive(string $file, string $filename, array $parameters, User $user): ImportBatch
    {
        abort_unless($user->canManage(), 403);
        unset($parameters['is_complete']); // Ignore the retired option from older callers.
        $format = FormatRegistry::get($parameters['type']);
        $header = (new CsvReader)->readAt($file, 0, 0);
        $format->validateHeader($header->values);
        $hash = hash_file('sha256', $file);
        $path = 'originals/'.$hash.'.csv';
        $full = storage_path('app/private/'.$path);
        // Reimports share an immutable original by checksum. Reserve staging
        // and temporary space without counting that original a second time.
        $this->budget->ensure(filesize($file) * 2 + (is_file($full) ? 0 : filesize($file)) + 10 * 1024 * 1024);
        $permissions = new PrivateStoragePermissions;
        $permissions->ensureDirectory(dirname($full));
        if (! is_file($full)) {
            $temp = $full.'.'.bin2hex(random_bytes(5)).'.tmp';
            try {
                if (! copy($file, $temp) || hash_file('sha256', $temp) !== $hash) {
                    throw new \RuntimeException('Original storage failed');
                }
                $permissions->secureFile($temp);
                if (! rename($temp, $full)) {
                    throw new \RuntimeException('Original storage failed');
                }
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        }
        $duplicate = ImportBatch::where('sha256', $hash)->where('version_id', $parameters['version_id'])->where('type', $format->code)->exists();
        $import = ImportBatch::create([...$parameters, 'filename' => basename($filename), 'path' => $path, 'sha256' => $hash, 'size' => filesize($full), 'format_version' => $format->version,
            'status' => 'queued', 'created_by' => $user->id, 'updated_by' => $user->id, 'report' => ['duplicate_file' => $duplicate, 'errors' => 0, 'warnings' => 0, 'collisions' => 0]]);
        $this->audit->record('import.received', $import, [], ['sha256' => $hash, 'format' => $format->version], $user);

        return $import->fresh();
    }

    public function analyze(ImportBatch $import): void
    {
        $format = FormatRegistry::get($import->type);
        $this->organizations = Organisation::pluck('id', 'code')->all();
        if (hash_file('sha256', $import->originalPath()) !== $import->sha256) {
            throw new \RuntimeException('Original checksum mismatch');
        }
        $generation = (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
        if ($import->catalogue_generation !== $generation && $import->analysis_offset > 0) {
            DB::table('import_rows')->where('import_id', $import->id)->delete();
            $import->update(['analysis_offset' => 0, 'row_count' => 0, 'report' => ['duplicate_file' => data_get($import->report, 'duplicate_file', false), 'errors' => 0, 'warnings' => 0, 'collisions' => 0]]);
        }
        $import->update(['status' => 'analyzing', 'catalogue_generation' => $generation, 'error' => null]);
        $report = $import->report;
        $report['new'] = $report['new'] ?? 0;
        $report['unchanged'] = $report['unchanged'] ?? 0;
        $report['changed'] = $report['changed'] ?? 0;
        $batch = [];
        foreach ((new CsvReader)->records($import->originalPath(), $import->analysis_offset, $import->analysis_offset ? $import->row_count + 1 : 0) as $record) {
            if ($record->number === 0) {
                $format->validateHeader($record->values);

                continue;
            }
            $batch[] = $record;
            if (count($batch) >= config('referentiel.batch_size')) {
                $this->analyzeChunk($import, $format, $batch, $report);
                $batch = [];
            }
        }
        if ($batch) {
            $this->analyzeChunk($import, $format, $batch, $report);
        }
        // Keep every presence, and verify exact keys even inside a shared hash bucket.
        // Keyset reads and bounded updates avoid materializing a large duplicate group.
        $groups = DB::table('import_rows')->where('import_id', $import->id)->select('raw_identity_hash')
            ->groupBy('raw_identity_hash')->havingRaw('COUNT(*) > 1')->lazyById(300, 'raw_identity_hash');
        $report['collisions'] = 0;
        DB::table('import_rows')->where('import_id', $import->id)->where('collision', true)->update(['collision' => false]);
        foreach ($groups as $group) {
            $report['collisions'] += $this->markCollisions($import, $format, $group->raw_identity_hash);
        }
        $collisions = DB::table('import_rows')->where('import_id', $import->id)->where('collision', true);
        $report['collision_rows_total'] = (clone $collisions)->count();
        $report['collision_rows'] = $collisions->orderBy('record_number')->limit(1000)->pluck('record_number')->all();
        $report['collision_rows_truncated'] = $report['collision_rows_total'] > count($report['collision_rows']);
        $import->update(['status' => 'analyzed', 'report' => $report]);
        $this->progress($import, 'analyzed', $import->row_count);
        $this->audit->record('import.analyzed', $import, [], ['counts' => $report], $import->author);
    }

    private function markCollisions(ImportBatch $import, FormatDefinition $format, string $hash): int
    {
        $rows = DB::table('import_rows')->where('import_id', $import->id)->where('raw_identity_hash', $hash)->where('collision', false);
        $reader = new CsvReader;
        $identity = fn ($presence) => $format->identity($reader->readAt($import->originalPath(), $presence->offset, $presence->record_number, $presence->length)->associative($format->headers));
        $afterId = 0;
        $duplicates = 0;
        // Normally one exact identity fills the bucket, so this scans it once.
        // A real hash collision gets a separate pass per distinct exact identity;
        // memory stays bounded, and unrelated keys never acquire collision flags.
        while ($first = (clone $rows)->where('id', '>', $afterId)->orderBy('id')->first()) {
            $afterId = $first->id;
            $key = $identity($first);
            (clone $rows)->where('id', '>', $first->id)->chunkById(300, function ($chunk) use ($first, $key, $identity, &$duplicates) {
                $ids = [];
                foreach ($chunk as $presence) {
                    if ($identity($presence) === $key) {
                        $ids[] = $presence->id;
                    }
                }
                if ($ids) {
                    $duplicates += count($ids);
                    DB::table('import_rows')->whereIn('id', [$first->id, ...$ids])->update(['collision' => true]);
                }
            });
        }

        return $duplicates;
    }

    private function analyzeChunk(ImportBatch $import, FormatDefinition $format, array $records, array &$report): void
    {
        $prepared = [];
        foreach ($records as $record) {
            $row = $record->associative($format->headers);
            $key = $format->identity($row);
            if (implode('', $key) === '') {
                throw new \RuntimeException('Empty identity at record '.$record->number);
            }
            $owner = $this->owner($format, $row, $import);
            $rawHash = $format->identityHash($row);
            $prepared[] = [$record, $row, $rawHash, $owner];
        }
        $known = $this->known($import, array_column($prepared, 2));
        $inserts = [];
        foreach ($prepared as [$record,$row,$rawHash,$owner]) {
            $term = $this->findExact($known->get($rawHash, collect()), $format->identity($row), $owner['id'], $import, $rawHash);
            $hash = $this->identityHash($format, $row, $term ? $term->organization_id : $owner['id']);
            $category = $term ? (($term->semantic_hash === $format->semanticHash($row) && $term->source_hash === $format->sourceHash($row)) ? 'unchanged' : 'changed') : 'new';
            $report[$category]++;
            foreach ($format->attributes($row) as $attribute) {
                foreach ($attribute['translations'] as $language => $value) {
                    if ($language === 'de' || $value === '') {
                        continue;
                    }
                    $check = TranslationValidator::validate($attribute['reference'], $value);
                    if ($check['errors'] || $check['warnings']) {
                        $report['warnings']++;
                    }
                }
            }
            $inserts[] = ['import_id' => $import->id, 'record_number' => $record->number, 'offset' => $record->offset, 'length' => $record->length, 'identity_hash' => $hash, 'raw_identity_hash' => $rawHash, 'category' => $category, 'collision' => false];
        }
        $last = end($records);
        DB::transaction(function () use ($inserts, $import, $last, $report) {
            DB::table('import_rows')->insert($inserts);
            $import->update(['analysis_offset' => $last->offset + $last->length, 'row_count' => $last->number, 'report' => $report]);
        });
        $this->progress($import, 'analyzing', $last->number);
    }

    public function apply(ImportBatch $import): void
    {
        if (hash_file('sha256', $import->originalPath()) !== $import->sha256) {
            throw new \RuntimeException('Original checksum mismatch');
        }
        $generation = (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
        if ($generation !== $import->catalogue_generation) {
            $import->update(['status' => 'queued', 'error' => 'ui.analysis_stale']);

            return;
        }
        // Analysis has already allocated presence rows. Unchanged rows create
        // no term, revision or proposal; budget only their bounded updates.
        $counts = $import->report;
        $additional = ((int) ($counts['new'] ?? 0) + (int) ($counts['changed'] ?? 0)) * 1300
            + (int) ($counts['unchanged'] ?? 0) * 64 + 10 * 1024 * 1024;
        $this->budget->ensure($additional);
        $format = FormatRegistry::get($import->type);
        $this->organizations = Organisation::pluck('id', 'code')->all();
        $technical = User::where('is_technical', true)->firstOrFail();
        $import->update(['status' => 'applying', 'error' => null]);
        $report = $import->report;
        $report['proposals_created'] = 0;
        $report['revisions_created'] = 0;
        $report['obsolete'] = 0;
        $latest = MyabiVersion::whereIn('id', ImportBatch::where('status', 'applied')->select('version_id'))->max('released_at');
        $isLatest = $latest === null || $import->version->released_at->format('Y-m-d') >= Carbon::parse($latest)->format('Y-m-d');
        DB::transaction(function () use ($import, $format, $technical, &$report, $isLatest) {
            $records = (new CsvReader)->records($import->originalPath());
            $records->rewind();
            $records->next();
            DB::table('import_rows')->where('import_id', $import->id)->orderBy('id')->chunkById(config('referentiel.batch_size'), function ($rows) use ($import, $format, $technical, &$report, $isLatest, $records) {
                $this->applyChunk($import, $format, $rows, $technical, $report, $isLatest, $records);
                $this->progress($import, 'applying', $rows->last()->record_number);
            });
            // A file supplies its present rows; absence alone never changes an existing term.
            DB::table('catalogue_state')->where('id', 1)->increment('generation');
            $import->update(['status' => 'applied', 'report' => $report]);
            $this->audit->record('import.applied', $import, [], ['counts' => $report], $import->author);
        });
        try {
            $this->progress($import, 'applied', $import->row_count);
        } catch (\Throwable $error) {
            // The catalogue transaction has committed. A failed final status
            // file must never turn that applied import into a failed job.
            report($error);
        }
    }

    private function applyChunk(ImportBatch $import, FormatDefinition $format, $rows, User $technical, array &$report, bool $isLatest, \Generator $records): void
    {
        $known = $this->known($import, $rows->pluck('raw_identity_hash')->all());
        $new = [];
        $prepared = [];
        $now = now()->format('Y-m-d H:i:s');
        $reader = new CsvReader;
        foreach ($rows as $presence) {
            $record = $records->current();
            if (! $record || $record->number !== $presence->record_number || $record->offset !== $presence->offset) {
                throw new \RuntimeException('Source position mismatch');
            }
            $records->next();
            $row = $record->associative($format->headers);
            $owner = $this->owner($format, $row, $import);
            $key = $format->identity($row);
            $term = $this->findExact($known->get($presence->raw_identity_hash, collect()), $key, $owner['id'], $import, $presence->raw_identity_hash);
            $attrs = $format->attributes($row);
            $prepared[] = [$presence, $row, $owner, $attrs];
            if ($term || isset($new[$presence->identity_hash])) {
                continue;
            }
            $label = implode(' / ', $key);
            $reference = implode(' ', array_filter(array_column($attrs, 'reference'), fn ($value) => $value !== ''));
            $search = $label.' '.$reference;
            foreach ($attrs as $a) {
                $search .= ' '.implode(' ', array_filter($a['translations'], fn ($value) => $value !== ''));
            }
            $new[$presence->identity_hash] = ['type' => $format->code, 'organization_id' => $owner['id'], 'identity_hash' => $presence->identity_hash, 'raw_identity_hash' => $presence->raw_identity_hash, 'scope_overridden' => false, 'key' => $this->json($key), 'label' => $label,
                'context' => mb_substr(match ($format->code) {
                    'form' => $row['Form Template Name'],'workflow' => $row['Workflow'],'incident' => $row['GROUPTYPE'],default => ''
                }, 0, 255),
                'source_text' => $reference, 'search_text' => $search, 'semantic_hash' => $format->semanticHash($row), 'source_hash' => $format->sourceHash($row),
                'source_import_id' => $import->id, 'source_offset' => $presence->offset, 'source_length' => $presence->length, 'validated' => '{}', 'review_needed' => '{}',
                'revision_no' => 1, 'lock_version' => 1, 'obsolete' => false, 'scope_unconfirmed' => $owner['unconfirmed'], 'created_by' => $technical->id, 'updated_by' => $technical->id, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($new) {
            DB::table('terms')->insert(array_values($new));
            $known = $this->known($import, $rows->pluck('raw_identity_hash')->all());
        }
        $existingProposals = DB::table('proposals')->whereIn('term_id', $known->flatten()->pluck('id'))->where('status', 'pending')->get();
        $existingByTerm = $existingProposals->groupBy('term_id');
        $proposalKeys = [];
        foreach ($existingProposals as $p) {
            $proposalKeys[$p->term_id.'|'.$p->attribute.'|'.$p->language.'|'.$p->value_hash] = ['id' => $p->id, 'value' => $p->value];
        }
        $revisions = [];
        $proposals = [];
        $links = [];
        $presences = [];
        $newSnap = [];
        $proposalAnomalies = [];
        $searchUpdates = [];
        $searchAttributes = [];
        foreach ($prepared as [$presence,$row,$owner,$attrs]) {
            $term = $this->findExact($known->get($presence->raw_identity_hash, collect()), $format->identity($row), $owner['id'], $import, $presence->raw_identity_hash);
            if (! $term) {
                throw new \RuntimeException('Exact identity mismatch');
            }
            $created = isset($new[$presence->identity_hash]) && ! isset($newSnap[$term->id]);
            $changed = ! $created && $isLatest && ! $presence->collision && ($term->semantic_hash !== $format->semanticHash($row) || $term->source_hash !== $format->sourceHash($row));
            if ($changed) {
                $review = $term->review_needed;
                if ($term->source_hash !== $format->sourceHash($row)) {
                    $previous = $term->attributesForDisplay();
                    foreach ($attrs as $name => $attr) {
                        if (($previous[$name]['reference'] ?? null) !== $attr['reference']) {
                            foreach ($term->validated[$name] ?? [] as $language => $_) {
                                $review[$name][$language] = true;
                            }
                        }
                    }
                    foreach ($existingByTerm->get($term->id, collect()) as $pending) {
                        $attribute = $attrs[$pending->attribute] ?? null;
                        $errors = $attribute && isset($attribute['columns'][$pending->language])
                            ? TranslationValidator::validate($attribute['reference'], $pending->value)['error_codes'] : ['ui.invalid_attribute'];
                        $proposalAnomalies[$pending->id] = $this->json($errors);
                    }
                }
                $reference = implode(' ', array_filter(array_column($attrs, 'reference'), fn ($value) => $value !== ''));
                $search = $term->label.' '.$reference;
                foreach ($attrs as $a) {
                    $search .= ' '.implode(' ', array_filter($a['translations'], fn ($value) => $value !== ''));
                }
                foreach ($term->validated as $a) {
                    $search .= ' '.implode(' ', $a);
                }
                $term->fill(['source_import_id' => $import->id, 'source_offset' => $presence->offset, 'source_length' => $presence->length, 'source_text' => $reference, 'search_text' => $search,
                    'semantic_hash' => $format->semanticHash($row), 'source_hash' => $format->sourceHash($row), 'review_needed' => $review, 'revision_no' => $term->revision_no + 1, 'lock_version' => $term->lock_version + 1,
                    'updated_by' => $technical->id, 'obsolete' => false]);
                $term->save();
                $term->unsetRelation('sourceImport');
            } elseif ($isLatest && $term->obsolete) {
                $term->update(['obsolete' => false, 'revision_no' => $term->revision_no + 1, 'lock_version' => $term->lock_version + 1, 'updated_by' => $technical->id]);
                $changed = true;
            }
            if ($created || $changed) {
                if ($changed) {
                    $searchUpdates[$term->id] = $term;
                    $searchAttributes[$term->id] = $attrs;
                }
                $revisions[] = ['term_id' => $term->id, 'number' => $term->revision_no, 'version_id' => $import->version_id, 'source_import_id' => $term->source_import_id, 'source_offset' => $term->source_offset, 'source_length' => $term->source_length,
                    'validated' => $this->json($term->validated), 'metadata' => $this->json(['organization_id' => $term->organization_id, 'key' => $term->key, 'obsolete' => false, 'review_needed' => $term->review_needed]),
                    'origin' => 'import', 'origin_id' => $import->id, 'created_by' => $technical->id, 'created_at' => $now];
                $report['revisions_created']++;
                $newSnap[$term->id] = true;
            }
            $presences[] = ['id' => $presence->id, 'import_id' => $import->id, 'record_number' => $presence->record_number, 'offset' => $presence->offset, 'length' => $presence->length, 'identity_hash' => $presence->identity_hash, 'raw_identity_hash' => $presence->raw_identity_hash, 'term_id' => $term->id, 'collision' => $presence->collision, 'category' => $presence->category];
            if (! $isLatest || $presence->collision) {
                continue;
            }
            foreach ($attrs as $attribute => $attr) {
                foreach ($attr['translations'] as $language => $value) {
                    if ($language === 'de' || $value === '' || data_get($term->validated, "$attribute.$language") === $value) {
                        continue;
                    }
                    $valueHash = hash('sha256', $value);
                    $pkey = $term->id.'|'.$attribute.'|'.$language.'|'.$valueHash;
                    if (isset($proposalKeys[$pkey]) && $proposalKeys[$pkey]['value'] === $value) {
                        if ($proposalKeys[$pkey]['id']) {
                            $links[] = ['proposal_id' => $proposalKeys[$pkey]['id'], 'import_id' => $import->id];
                        }

                        continue;
                    }
                    $check = TranslationValidator::validate($attr['reference'], $value);
                    if (! $created) {
                        $searchUpdates[$term->id] = $term;
                        $searchAttributes[$term->id] = $attrs;
                    }
                    $proposals[] = ['term_id' => $term->id, 'attribute' => $attribute, 'language' => $language, 'value' => $value, 'value_hash' => $valueHash, 'status' => 'pending', 'lock_version' => 1,
                        'author_id' => $technical->id, 'import_id' => $import->id, 'organization_id' => $import->organization_id, 'claimed' => false, 'divergence' => data_get($term->validated, "$attribute.$language") !== null,
                        'anomalies' => $this->json($check['error_codes']), 'edit_history' => '[]', 'created_at' => $now, 'updated_at' => $now];
                    $proposalKeys[$pkey] = ['id' => null, 'value' => $value];
                    $report['proposals_created']++;
                }
            }
        }
        if ($revisions) {
            DB::table('revisions')->insert($revisions);
        }
        $this->refreshPendingProposals($proposalAnomalies, $now);
        foreach (array_chunk($proposals, 300) as $chunk) {
            DB::table('proposals')->insert($chunk);
        }
        if ($links) {
            DB::table('proposal_imports')->insertOrIgnore($links);
        }
        app(SearchText::class)->syncMany(collect(array_values($searchUpdates)), $searchAttributes);
        DB::table('import_rows')->upsert($presences, ['id'], ['term_id']);
    }

    private function owner(FormatDefinition $format, array $row, ImportBatch $import): array
    {
        $prefixes = [];
        foreach (config('referentiel.scope_prefixes') as $code) {
            $prefixes[$code.'_'] = $code;
        }
        $unconfirmedPrefixes = config('referentiel.unconfirmed_scope_prefixes', FormatDefinition::UNCONFIRMED_SCOPE_PREFIXES);
        $scope = $format->suggestedScope($row, $prefixes, $unconfirmedPrefixes);
        $unresolved = in_array($scope, $unconfirmedPrefixes, true);

        return ['id' => ! $unresolved && $scope ? ($this->organizations[$scope] ?? null) : null,
            'unconfirmed' => $unresolved || ($scope !== null && ! isset($this->organizations[$scope]))];
    }

    public function identityHash(FormatDefinition $format, array $row, ?int $owner): string
    {
        return hash('sha256', $this->json([$format->code, $owner, $format->identity($row)]));
    }

    private function refreshPendingProposals(array $anomalies, string $now): void
    {
        foreach (array_chunk($anomalies, 300, true) as $chunk) {
            $cases = [];
            $bindings = [];
            foreach ($chunk as $id => $value) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = $value;
            }
            $sql = 'UPDATE proposals SET anomalies = CASE id '.implode(' ', $cases).' END, lock_version = lock_version + 1, updated_at = ?'
                .' WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).') AND status = ?';
            DB::update($sql, [...$bindings, $now, ...array_keys($chunk), 'pending']);
        }
    }

    private function findExact($candidates, array $key, ?int $owner, ImportBatch $import, string $rawHash): ?Term
    {
        // A digest is only a candidate index: exact components remain authoritative.
        $exact = $candidates->filter(fn ($term) => $term->key === $key);
        if ($preferred = $exact->first(fn ($term) => $term->id === ($this->provenanceIds[$rawHash] ?? null))) {
            return $preferred;
        }
        $common = $exact->filter(fn ($term) => $term->scope_overridden && $term->organization_id === null);
        if ($common->isNotEmpty()) {
            return $this->uniqueScope($common);
        }
        if ($import->source_kind === 'organization' && $import->organization_id !== null) {
            $private = $exact->filter(fn ($term) => $term->scope_overridden && $term->organization_id === $import->organization_id);
            if ($private->isNotEmpty()) {
                return $this->uniqueScope($private);
            }
        }
        $inferred = $exact->filter(fn ($term) => $term->organization_id === $owner);

        return $inferred->isEmpty() ? null : $this->uniqueScope($inferred);
    }

    private function uniqueScope($terms): Term
    {
        if ($terms->count() !== 1) {
            throw new \RuntimeException('Conflicting exact identities in the same scope; manual resolution required.');
        }

        return $terms->first();
    }

    /** Raw identity remains stable when canton mappings or manually assigned scope change. */
    private function known(ImportBatch $import, array $hashes)
    {
        $maria = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $joinOrder = $maria ? 'STRAIGHT_JOIN ' : '';
        $prior = DB::table('import_rows')->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')
            ->where('import_batches.status', 'applied')->where('import_batches.type', $import->type)->where('import_batches.source_kind', $import->source_kind)
            ->where('import_batches.organization_id', $import->organization_id)->whereIn('import_rows.raw_identity_hash', $hashes)->whereNotNull('term_id')
            ->groupBy('import_rows.raw_identity_hash')->selectRaw($joinOrder.'import_rows.raw_identity_hash, MAX(import_batches.catalogue_generation) as generation');
        if ($maria) {
            // Fresh import statistics can underestimate a whole source as one row.
            // Always seek this chunk's hashes before looking up source metadata.
            $prior->forceIndex('import_rows_raw_identity_hash_index');
        }
        $links = DB::query()->fromSub($prior, 'latest_presence')
            ->join('import_rows', 'import_rows.raw_identity_hash', '=', 'latest_presence.raw_identity_hash')
            ->join('import_batches', fn ($join) => $join->on('import_batches.id', '=', 'import_rows.import_id')->on('import_batches.catalogue_generation', '=', 'latest_presence.generation'))
            ->where('import_batches.status', 'applied')->where('import_batches.type', $import->type)->where('import_batches.source_kind', $import->source_kind)
            ->where('import_batches.organization_id', $import->organization_id)->selectRaw($joinOrder.'import_rows.raw_identity_hash, import_rows.term_id')->get();
        $this->provenanceIds = $links->pluck('term_id', 'raw_identity_hash')->all();

        return Term::where('type', $import->type)->whereIn('raw_identity_hash', $hashes)->get()->groupBy('raw_identity_hash');
    }

    public function progress(ImportBatch $import, string $stage, int $rows): void
    {
        $dir = storage_path('app/private/progress');
        (new PrivateStoragePermissions)->ensureDirectory($dir);
        $path = $dir.'/'.$import->id.'.json';
        app(AtomicFileWriter::class)->write($path, $this->json(['stage' => $stage, 'rows' => $rows, 'total' => $import->row_count, 'last_activity' => now()->toIso8601String(), 'provisional' => $stage === 'applying']));
    }
}
