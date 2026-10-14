<?php

namespace App\Services;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Models\ImportBatch;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Exact identities are compared after candidate indexing; old revision lineages never move. */
class CommonTermTransition
{
    private array $checkedImports = [];

    public function diagnose(?callable $reportGroup = null): array
    {
        $this->checkedImports = [];
        $counts = ['terms' => $this->current()->count(), 'missing_hashes' => $this->current()->whereNull('raw_identity_hash')->count(),
            'duplicate_groups' => 0, 'compatible_groups' => 0, 'blocked_groups' => 0, 'duplicates' => 0, 'divergent_cells' => 0];
        $this->withCandidates(function () use (&$counts, $reportGroup) {
            foreach ($this->groups() as $terms) {
                $counts['duplicate_groups']++;
                $counts['duplicates'] += count($terms) - 1;
                $reasons = $this->incompatibilities($terms);
                $counts[$reasons === [] ? 'compatible_groups' : 'blocked_groups']++;
                $base = $terms[0];
                $divergences = 0;
                $divergentValues = [];
                foreach (array_slice($terms, 1) as $term) {
                    foreach ($this->json($term->validated) as $attribute => $languages) {
                        foreach ($languages as $language => $value) {
                            if ($value !== null && $value !== ($this->json($base->validated)[$attribute][$language] ?? null)) {
                                $divergences++;
                                if ($reportGroup) {
                                    $divergentValues[] = ['term_id' => $term->id, 'attribute' => $attribute, 'language' => $language,
                                        'base_value' => $this->json($base->validated)[$attribute][$language] ?? null, 'value' => $value];
                                }
                            }
                        }
                    }
                }
                $counts['divergent_cells'] += $divergences;
                if ($reportGroup) {
                    $ids = array_column($terms, 'id');
                    $reportGroup(['type' => $base->type, 'key' => $this->json($base->key), 'base_id' => $base->id,
                        'term_ids' => $ids, 'blocked_reasons' => $reasons, 'divergent_cells' => $divergences,
                        'divergent_values' => $divergentValues,
                        'references' => array_map(fn ($term) => ['term_id' => $term->id, 'source_hash' => $term->source_hash,
                            'semantic_hash' => $term->semantic_hash, 'source_import_id' => $term->source_import_id,
                            'source_offset' => $term->source_offset, 'source_length' => $term->source_length, 'revision' => $term->revision_no], $terms),
                        'links' => ['presences' => DB::table('import_rows')->whereIn('term_id', $ids)->count(),
                            'proposals' => DB::table('proposals')->whereIn('term_id', $ids)->count(),
                            'proposal_imports' => DB::table('proposal_imports')->whereIn('proposal_id', DB::table('proposals')->whereIn('term_id', $ids)->select('id'))->count(),
                            'restored_proposals' => DB::table('proposals')->whereNotNull('restored_revision_id')->whereIn('term_id', $ids)->count(),
                            'superseding_proposals' => DB::table('proposals')->whereNotNull('supersedes_id')->whereIn('term_id', $ids)->count(),
                            'revisions' => DB::table('revisions')->whereIn('term_id', $ids)->count(),
                            'audit_events' => DB::table('audit_events')->where('entity_type', Term::class)->whereIn('entity_id', $ids)->count(),
                            'publication_markers' => DB::table('terms')->whereIn('id', $ids)->whereNotNull('last_publication_id')->count(),
                            'campaign_participations' => Schema::hasTable('campaign_participations') ? DB::table('campaign_participations')->whereIn('term_id', $ids)->count() : 0]]);
                }
            }
        });

        return $counts;
    }

    /** Each compatible group commits once. A rerun resumes only the remaining current terms. */
    public function consolidate(): array
    {
        try {
            return app(OperationLock::class)->run(function () {
                $completed = DB::table('common_term_transition')->where('id', 1)->first();
                if ($completed?->completed_at !== null) {
                    return $this->json($completed->counts);
                }
                if (DB::table('import_batches')->where('status', 'applying')->exists()
                    || DB::table('publications')->where('status', 'building')->exists()
                    || DB::table('bulk_operations')->where('status', 'running')->exists()) {
                    throw new RuntimeException('Common catalogue transition requires unfinished writes to be completed first.');
                }
                $counts = $this->diagnose();
                if ($counts['blocked_groups'] > 0) {
                    throw new RuntimeException('Common catalogue transition blocked: '.$counts['blocked_groups'].' ambiguous identity group(s). Run referentiel:common-terms --diagnose.');
                }
                $initialCounts = $completed?->counts !== null ? $this->json($completed->counts) : $counts;
                DB::table('common_term_transition')->updateOrInsert(['id' => 1], ['counts' => json_encode($initialCounts, JSON_THROW_ON_ERROR)]);
                $this->withCandidates(function () {
                    foreach ($this->groups() as $terms) {
                        $ids = array_column($terms, 'id');
                        DB::transaction(function () use ($ids) {
                            DB::table('catalogue_state')->where('id', 1)->lockForUpdate()->first();
                            $locked = DB::table('terms')->whereIn('id', $ids)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()->all();
                            if ($this->incompatibilities($locked) !== []) {
                                throw new RuntimeException('Identity compatibility changed during common catalogue transition.');
                            }
                            $base = array_shift($locked);
                            foreach ($locked as $legacy) {
                                $this->merge($base, $legacy);
                            }
                        });
                        $this->checkpoint();
                    }
                });
                // Save historical ownership before removing the active columns. This also resumes by ID.
                DB::table('terms')->orderBy('id')->chunkById(300, function ($terms) {
                    DB::transaction(function () use ($terms) {
                        foreach ($terms as $term) {
                            if (property_exists($term, 'organization_id')) {
                                DB::table('term_legacy_scopes')->insertOrIgnore(['term_id' => $term->id,
                                    'metadata' => json_encode(['organization_id' => $term->organization_id, 'identity_hash' => $term->identity_hash,
                                        'scope_unconfirmed' => (bool) $term->scope_unconfirmed, 'scope_overridden' => (bool) $term->scope_overridden], JSON_THROW_ON_ERROR)]);
                            }
                            $hash = $this->digest($term);
                            DB::table('terms')->where('id', $term->id)->update(['raw_identity_hash' => $hash, 'identity_hash' => $hash]);
                        }
                    });
                });
                $this->transitionContextFingerprints();
                $this->transitionCampaignConfirmations();
                $counts = $initialCounts + ['current_terms' => $this->current()->count(),
                    'consolidated_terms' => DB::table('term_consolidations')->count(),
                    'recovered_values' => DB::table('common_term_recovered_values')->count()];
                DB::transaction(function () use ($counts) {
                    DB::table('catalogue_state')->where('id', 1)->increment('generation');
                    DB::table('bulk_operations')->whereIn('status', ['queued', 'ready'])->update(['status' => 'blocked', 'error' => 'Common catalogue transition invalidated this preview.']);
                    // These presences are analysis previews, never applied import/source records.
                    $stale = DB::table('import_batches')->whereIn('status', ['analyzing', 'analyzed'])->pluck('id');
                    DB::table('import_rows')->whereIn('import_id', $stale)->delete();
                    DB::table('import_batches')->whereIn('id', $stale)->update(['status' => 'queued', 'analysis_offset' => 0, 'row_count' => 0,
                        'report' => '{"duplicate_file":false,"errors":0,"warnings":0,"collisions":0}']);
                    DB::table('common_term_transition')->updateOrInsert(['id' => 1], ['completed_at' => now(), 'counts' => json_encode($counts, JSON_THROW_ON_ERROR)]);
                });

                return $counts;
            }, true);
        } catch (QueryException) {
            // SQL exception messages may include bound business values: never print them during migration.
            throw new RuntimeException('Common catalogue transition failed during a database operation. Run the private diagnostic before retrying.');
        }
    }

    protected function checkpoint(): void {}

    private function merge(object $base, object $legacy): void
    {
        if (DB::table('term_consolidations')->where('legacy_term_id', $legacy->id)->exists()) {
            return;
        }
        $metadata = ['organization_id' => $legacy->organization_id ?? null, 'source_import_id' => $legacy->source_import_id,
            'revision_no' => $legacy->revision_no, 'validated' => $this->json($legacy->validated)];
        DB::table('term_consolidations')->insert(['legacy_term_id' => $legacy->id, 'common_term_id' => $base->id,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        DB::table('proposals')->where('term_id', $legacy->id)->whereNull('legacy_term_id')->update(['legacy_term_id' => $legacy->id]);
        DB::table('proposals')->where('term_id', $legacy->id)->update(['term_id' => $base->id]);
        foreach ($this->json($legacy->validated) as $attribute => $languages) {
            foreach ($languages as $language => $value) {
                if ($value === null || $value === ($this->json($base->validated)[$attribute][$language] ?? null)) {
                    continue;
                }
                $pending = DB::table('proposals')->where('term_id', $base->id)->where('attribute', $attribute)->where('language', $language)
                    ->where('status', 'pending')->where('value_hash', hash('sha256', $value))->orderBy('id')->get()
                    ->first(fn ($proposal) => $proposal->value === $value);
                $decision = DB::table('proposals')->where('legacy_term_id', $legacy->id)->where('attribute', $attribute)
                    ->where('language', $language)->where('status', 'validated')->orderByDesc('decided_at')->orderByDesc('id')->get()
                    ->first(fn ($proposal) => $proposal->value === $value);
                if (! $pending) {
                    $attributes = FormatRegistry::get($base->type)->attributes($this->sourceRow($base));
                    $diagnostics = TranslationValidator::validate($attributes[$attribute]['reference'], $value);
                    $proposalId = DB::table('proposals')->insertGetId(['term_id' => $base->id, 'legacy_term_id' => $legacy->id,
                        'attribute' => $attribute, 'language' => $language, 'value' => $value, 'value_hash' => hash('sha256', $value),
                        'status' => 'pending', 'lock_version' => 1, 'author_id' => $decision?->author_id ?? $legacy->created_by,
                        'organization_id' => $decision ? $decision->organization_id : ImportBatch::find($legacy->source_import_id)?->organization_id,
                        'import_id' => $decision ? $decision->import_id : $legacy->source_import_id,
                        'claimed' => $decision ? $decision->claimed : false, 'divergence' => true,
                        'anomalies' => json_encode([...$diagnostics['error_codes'], ...$diagnostics['warning_codes']], JSON_THROW_ON_ERROR),
                        'context_fingerprint' => app(CampaignService::class)->contextFingerprint(Term::findOrFail($base->id), $attribute, $language, $attributes),
                        'edit_history' => json_encode([['origin' => 'common_term_consolidation', 'legacy_term_id' => $legacy->id,
                            'legacy_proposal_id' => $decision?->id, 'legacy_revision' => $legacy->revision_no]], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $proposalId = $pending->id;
                }
                if (! $decision) {
                    DB::table('proposal_imports')->insertOrIgnore(['proposal_id' => $proposalId, 'import_id' => $legacy->source_import_id]);
                }
                if ($decision) {
                    if ($decision->import_id !== null) {
                        DB::table('proposal_imports')->insertOrIgnore(['proposal_id' => $proposalId, 'import_id' => $decision->import_id]);
                    }
                    foreach (DB::table('proposal_imports')->where('proposal_id', $decision->id)->cursor() as $link) {
                        DB::table('proposal_imports')->insertOrIgnore(['proposal_id' => $proposalId, 'import_id' => $link->import_id]);
                    }
                }
                DB::table('common_term_recovered_values')->insertOrIgnore(['legacy_term_id' => $legacy->id, 'attribute' => $attribute,
                    'language' => $language, 'proposal_id' => $proposalId, 'legacy_proposal_id' => $decision?->id]);
            }
        }
        DB::table('import_rows')->where('term_id', $legacy->id)->update(['term_id' => $base->id]);
        // Campaign rows retain their historical IDs/events; unique active counterparts are transferred.
        if (Schema::hasTable('campaign_participations')) {
            foreach (DB::table('campaign_participations')->where('term_id', $legacy->id)->get() as $participation) {
                if (DB::table('translation_campaigns')->where('id', $participation->translation_campaign_id)->value('status') === 'completed') {
                    continue;
                }
                $counterpart = DB::table('campaign_participations')->where('translation_campaign_id', $participation->translation_campaign_id)->where('term_id', $base->id)->first();
                if ($counterpart) {
                    DB::table('campaign_participations')->where('id', $participation->id)->update(['active' => false, 'removed_at' => now(), 'reason' => 'common_term_consolidation']);
                } else {
                    DB::table('campaign_participations')->where('id', $participation->id)->update(['term_id' => $base->id,
                        'confirmation_fingerprint' => null, 'confirmed_at' => null, 'confirmed_by' => null, 'confirmed_revision' => null, 'status' => 'to_process']);
                }
            }
        }
        if (property_exists($base, 'term_group_id') && $base->term_group_id === null && $legacy->term_group_id !== null) {
            DB::table('terms')->where('id', $base->id)->update(['term_group_id' => $legacy->term_group_id]);
            $base->term_group_id = $legacy->term_group_id;
        }
        DB::table('terms')->where('id', $legacy->id)->update(['consolidated_into_id' => $base->id]);
        $base->search_text .= "\n".$legacy->search_text;
        DB::table('terms')->where('id', $base->id)->update(['search_text' => $base->search_text]);
        // Archived lineages are excluded from live projections, but their business rows remain intact.
        DB::table('term_search')->where('term_id', $legacy->id)->delete();
        DB::table('term_states')->where('id', $legacy->id)->delete();
    }

    /** Only an exactly matching old context can shed ownership; stale fingerprints stay stale. */
    private function transitionContextFingerprints(): void
    {
        $campaigns = app(CampaignService::class);
        $this->current()->whereIn('id', DB::table('proposals')->where('status', 'pending')->whereNotNull('context_fingerprint')->select('term_id'))
            ->orderBy('id')->chunkById(300, function ($terms) use ($campaigns) {
                foreach ($terms as $row) {
                    try {
                        $source = $this->sourceRow($row);
                        $attributes = FormatRegistry::get($row->type)->attributes($source);
                    } catch (\Throwable) {
                        // An unverifiable reference never acquires a new relevance assertion.
                        continue;
                    }
                    $term = Term::findOrFail($row->id);
                    DB::transaction(function () use ($term, $source, $attributes, $campaigns) {
                        foreach (DB::table('proposals')->where('term_id', $term->id)->where('status', 'pending')->whereNotNull('context_fingerprint')->get() as $proposal) {
                            $lineageId = $proposal->legacy_term_id ?? $term->id;
                            $historical = DB::table('term_legacy_scopes')->where('term_id', $lineageId)->value('metadata');
                            if ($historical === null) {
                                continue;
                            }
                            $organizationId = $this->json($historical)['organization_id'];
                            $old = $campaigns->historicalContextFingerprint($term, $proposal->attribute, $proposal->language, $organizationId, $attributes, $source);
                            if (hash_equals($old, $proposal->context_fingerprint)) {
                                DB::table('proposals')->where('id', $proposal->id)->update(['context_fingerprint' => $campaigns->contextFingerprint($term, $proposal->attribute, $proposal->language, $attributes, $source)]);
                            }
                        }
                    });
                }
            });
    }

    private function transitionCampaignConfirmations(): void
    {
        if (! Schema::hasTable('campaign_participations')) {
            return;
        }
        $campaigns = app(CampaignService::class);
        DB::table('campaign_participations as p')->join('translation_campaigns as c', 'c.id', '=', 'p.translation_campaign_id')
            ->where('c.status', '!=', 'completed')->where('p.active', true)->where('p.status', 'confirmed')
            ->select('p.*')->lazyById(300, 'p.id', 'id')->each(function ($participation) use ($campaigns) {
                $term = Term::find($participation->term_id);
                $matching = false;
                $new = null;
                try {
                    if ($term) {
                        $source = $this->sourceRow((object) $term->getAttributes());
                        $attributes = FormatRegistry::get($term->type)->attributes($source);
                        $metadata = $this->json(DB::table('term_legacy_scopes')->where('term_id', $term->id)->value('metadata'));
                        $language = DB::table('translation_campaigns')->where('id', $participation->translation_campaign_id)->value('language');
                        $oldContext = $campaigns->historicalContextFingerprint($term, $participation->attribute, $language, $metadata['organization_id'] ?? null, $attributes, $source);
                        $newContext = $campaigns->contextFingerprint($term, $participation->attribute, $language, $attributes, $source);
                        $value = json_encode($term->validated[$participation->attribute][$language] ?? null, JSON_THROW_ON_ERROR);
                        $old = hash('sha256', $oldContext.'\0'.$value);
                        $new = hash('sha256', $newContext.'\0'.$value);
                        $matching = $participation->confirmation_fingerprint === $old || $participation->confirmation_fingerprint === $new;
                    }
                } catch (\Throwable) {
                    // Unverifiable confirmations are explicitly returned for examination.
                }
                DB::transaction(function () use ($participation, $matching, $new) {
                    if ($matching) {
                        DB::table('campaign_participations')->where('id', $participation->id)->update(['confirmation_fingerprint' => $new]);
                    } else {
                        DB::table('campaign_events')->insert(['translation_campaign_id' => $participation->translation_campaign_id,
                            'campaign_participation_id' => $participation->id, 'action' => 'automatic_reexamination',
                            'reason' => 'La traduction examinée ou sa référence a changé.',
                            'payload' => json_encode(['origin' => 'common_term_transition', 'previous_confirmation' => (array) $participation], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                        DB::table('campaign_participations')->where('id', $participation->id)->update(['status' => 'to_process',
                            'confirmed_at' => null, 'confirmed_by' => null, 'confirmed_revision' => null, 'confirmation_fingerprint' => null]);
                    }
                });
            });
    }

    private function incompatibilities(array $terms): array
    {
        $reasons = [];
        $fingerprint = null;
        $groups = [];
        foreach ($terms as $term) {
            try {
                $format = FormatRegistry::get($term->type);
                $row = $this->sourceRow($term);
                if ($format->identity($row) !== $this->json($term->key) || $format->sourceHash($row) !== $term->source_hash
                    || $format->semanticHash($row) !== $term->semantic_hash) {
                    $reasons[] = 'source_identity_or_fingerprint';
                }
                $references = [];
                foreach ($format->attributes($row) as $name => $attribute) {
                    $references[$name] = [$attribute['reference'], $attribute['reference_language']];
                }
                $current = [$term->type, $this->json($term->key), $references, $format->semanticHash($row)];
                if ($fingerprint !== null && $current !== $fingerprint) {
                    $reasons[] = 'incompatible_reference_or_meaning';
                }
                $fingerprint ??= $current;
                if (property_exists($term, 'term_group_id') && $term->term_group_id !== null) {
                    $groups[] = $term->term_group_id;
                }
                if (DB::table('import_rows')->where('term_id', $term->id)->where('collision', true)->exists()) {
                    $reasons[] = 'ambiguous_import_presence';
                }
                $revision = DB::table('revisions')->where('term_id', $term->id)->where('number', $term->revision_no)->first();
                if (! $revision) {
                    $reasons[] = 'missing_current_revision';
                } elseif ($revision->source_import_id !== $term->source_import_id || $revision->source_offset !== $term->source_offset
                    || $revision->source_length !== $term->source_length || $this->json($revision->validated) != $this->json($term->validated)
                    || ($this->json($revision->metadata)['key'] ?? null) !== $this->json($term->key)
                    || ($this->json($revision->metadata)['context'] ?? null) !== $term->context) {
                    $reasons[] = 'inconsistent_current_revision';
                }
            } catch (\Throwable) {
                $reasons[] = 'unverifiable_source';
            }
        }
        if (count(array_unique($groups)) > 1) {
            $reasons[] = 'incompatible_group_links';
        }

        return array_values(array_unique($reasons));
    }

    private function sourceRow(object $term): array
    {
        $import = ImportBatch::find($term->source_import_id);
        if (! $import || $import->status !== 'applied' || $import->type !== $term->type) {
            throw new RuntimeException('Unverifiable source.');
        }
        $format = FormatRegistry::get($term->type);
        if (! isset($this->checkedImports[$import->id])) {
            $path = $import->originalPath();
            if ($import->format_version !== $format->version || ! is_file($path)
                || filesize($path) !== (int) $import->size || hash_file('sha256', $path) !== $import->sha256) {
                throw new RuntimeException('Unverifiable original.');
            }
            $header = (new CsvReader)->readAt($path, 0, 0);
            $format->validateHeader($header->values);
            $this->checkedImports[$import->id] = $path;
        }
        $presence = DB::table('import_rows')->where('import_id', $import->id)->where('term_id', $term->id)
            ->where('offset', $term->source_offset)->where('length', $term->source_length)->where('collision', false)->first();
        if (! $presence) {
            throw new RuntimeException('Unverifiable position.');
        }

        return (new CsvReader)->readAt($this->checkedImports[$import->id], $term->source_offset, $presence->record_number, $term->source_length)->associative($format->headers);
    }

    private function current()
    {
        $query = DB::table('terms');
        if (Schema::hasColumn('terms', 'consolidated_into_id')) {
            $query->whereNull('consolidated_into_id');
        }

        return $query;
    }

    private function digest(object $term): string
    {
        return hash('sha256', json_encode([$term->type, $this->json($term->key)], JSON_THROW_ON_ERROR));
    }

    private function json(?string $value): array
    {
        return $value === null ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function withCandidates(callable $callback): mixed
    {
        // A connection-local scratch table bounds memory and does not change catalogue data.
        $maria = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $index = $maria ? ', INDEX common_term_candidates_digest (digest)' : '';
        DB::statement('CREATE TEMPORARY TABLE common_term_candidates (term_id BIGINT PRIMARY KEY, digest CHAR(64) NOT NULL'.$index.')');
        try {
            if (! $maria) {
                DB::statement('CREATE INDEX common_term_candidates_digest ON common_term_candidates (digest)');
            }
            $this->current()->orderBy('id')->chunkById(300, function ($terms) {
                DB::table('common_term_candidates')->insert($terms->map(fn ($term) => ['term_id' => $term->id, 'digest' => $this->digest($term)])->all());
            });

            return $callback();
        } finally {
            DB::statement('DROP '.($maria ? 'TEMPORARY ' : '').'TABLE common_term_candidates');
        }
    }

    private function groups(): \Generator
    {
        $digests = DB::table('common_term_candidates')->select('digest')->groupBy('digest')->havingRaw('COUNT(*) > 1')->lazyById(300, 'digest');
        foreach ($digests as $candidateDigest) {
            $digest = $candidateDigest->digest;
            $candidates = DB::table('terms')->join('common_term_candidates as c', 'terms.id', '=', 'c.term_id')
                ->where('c.digest', $digest)->orderBy('terms.created_at')->orderBy('terms.id')->select('terms.*')->get();
            $exact = [];
            foreach ($candidates as $term) {
                $exact[json_encode([$term->type, $this->json($term->key)], JSON_THROW_ON_ERROR)][] = $term;
            }
            foreach ($exact as $terms) {
                if (count($terms) > 1) {
                    yield $terms;
                }
            }
        }
    }
}
