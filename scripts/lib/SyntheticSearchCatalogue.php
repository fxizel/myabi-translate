<?php

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatDefinition;
use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Term;
use App\Models\User;
use App\Services\CatalogueSearch\Projection;
use Illuminate\Support\Facades\DB;

/** Synthetic benchmark data only. Caller must verify an empty disposable schema before invoking. */
final class SyntheticSearchCatalogue
{
    public const VOLUMES = ['mric' => 38562, 'monomot' => 3867, 'server' => 941, 'form' => 98974, 'workflow' => 22830, 'law' => 147, 'incident' => 415821];

    public function seed(int $count, callable $progress): array
    {
        if (Term::exists()) {
            throw new RuntimeException('Synthetic seeding requires an empty catalogue.');
        }
        $cases = require base_path('tests/Fixtures/catalogue-search-corpus.php');
        $corpus = [];
        foreach ($cases as $case) {
            foreach ($case['candidates'] as $candidateIndex => $candidate) {
                $corpus[$case['secondary'] ? 'incident' : 'server'][] = [$case, $candidateIndex, $candidate];
            }
        }
        $password = bin2hex(random_bytes(24));
        $user = User::create(['name' => 'Synthetic reader', 'email' => 'benchmark@example.org', 'password' => $password, 'roles' => ['reader'], 'locale' => 'fr', 'active' => true]);
        $version = MyabiVersion::create(['number' => 'synthetic-1', 'released_at' => '2026-01-01', 'status' => 'current', 'created_by' => $user->id, 'updated_by' => $user->id]);
        $expected = [];
        $nextId = 1;
        $seeded = 0;
        $now = '2026-01-01 00:00:00';
        $directory = storage_path('app/private/originals');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach (FormatRegistry::all() as $type => $format) {
            $target = $type === 'incident' ? $count - $seeded : (int) floor($count * self::VOLUMES[$type] / array_sum(self::VOLUMES));
            $seeded += $target;
            $path = $directory.'/synthetic-'.$type.'.csv';
            $handle = fopen($path, 'xb');
            if (! $handle) {
                throw new RuntimeException('Synthetic source already exists. Use a new isolated storage directory.');
            }
            fwrite($handle, $this->csv($format->headers));
            $import = ImportBatch::create(['type' => $type, 'version_id' => $version->id, 'organization_id' => null, 'source_kind' => 'publisher',
                'filename' => $format->filename, 'path' => 'originals/synthetic-'.$type.'.csv', 'sha256' => str_repeat('0', 64), 'size' => 0,
                'status' => 'applied', 'format_version' => $format->version, 'row_count' => 0, 'report' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);
            $terms = $proposals = $presences = [];
            $flush = static function () use (&$terms, &$proposals, &$presences) {
                DB::transaction(function () use (&$terms, &$proposals, &$presences) {
                    if ($terms) {
                        DB::table('terms')->insert($terms);
                    }
                    foreach (array_chunk($proposals, 200) as $chunk) {
                        DB::table('proposals')->insert($chunk);
                    }
                    if ($presences) {
                        DB::table('import_rows')->insert($presences);
                    }
                });
                $terms = $proposals = $presences = [];
            };
            $extra = $corpus[$type] ?? [];
            $records = $target + count($extra);
            for ($i = 0; $i < $records; $i++) {
                $proposalOffset = count($proposals);
                $id = $nextId++;
                $key = 'synthetic-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT);
                $row = array_fill_keys($format->headers, '');
                foreach ($format->identityColumns as $column) {
                    $row[$column] = $key;
                }
                $values = $validated = [];
                foreach ($format->attributeNames as $attributeIndex => $attribute) {
                    if ($attributeIndex > 0 && ($i + $attributeIndex) % ($attributeIndex === 1 ? 2 : 20) !== 0) {
                        continue;
                    }
                    $values[$attribute]['de'] = $this->text('de', $i + $attributeIndex);
                    foreach (['fr', 'it', 'en'] as $languageIndex => $language) {
                        $rates = ['fr' => [40, 19, 31, 18, 20, 66, 13], 'it' => [34, 11, 11, 14, 22, 61, 12], 'en' => [30, 30, 30, 30, 30, 30, 30]];
                        $typeIndex = array_search($type, array_keys(self::VOLUMES), true);
                        if (($i * 37 + $attributeIndex + $languageIndex * 17) % 100 < $rates[$language][$typeIndex]) {
                            $text = $this->text($language, $i + $attributeIndex);
                            $values[$attribute][$language] = $text;
                            if ($i % 3 === 0) {
                                $validated[$attribute][$language] = $text;
                            } else {
                                $proposals[] = $this->proposal($id, $attribute, $language, $text, 'pending', $user->id, $import->id, $now);
                            }
                        }
                    }
                }
                $context = 'synthetic-area-'.($i % 200);
                if ($i >= $target) {
                    [$case, $candidateIndex, $candidate] = $extra[$i - $target];
                    // Remove any background proposals appended above for this case.
                    $proposals = array_values(array_filter($proposals, fn ($proposal) => $proposal['term_id'] !== $id));
                    $attribute = $case['secondary'] ? 'SHORTTEXT' : 'label';
                    $values = [$format->attributeNames[0] => ['de' => 'Allgemeine Bezeichnung']];
                    $validated = [];
                    $context = $case['id'];
                    if ($candidate['origin'] === 'reference') {
                        $values[$attribute]['de'] = $candidate['text'];
                    } elseif ($candidate['origin'] === 'current') {
                        $values[$attribute][$candidate['language']] = $candidate['text'];
                        $validated[$attribute][$candidate['language']] = $candidate['text'];
                    } else {
                        $proposals[] = $this->proposal($id, $attribute, $candidate['language'], $candidate['text'], $candidate['origin'] === 'proposal' ? 'pending' : 'rejected', $user->id, $import->id, $now);
                    }
                    if ($candidate['relevant']) {
                        $expected[$case['id']][] = $id;
                    }
                }
                $this->mapValues($row, $format, $values);
                $bytes = $this->csv(array_values($row));
                $offset = ftell($handle);
                fwrite($handle, $bytes);
                $attrs = $format->attributes($row);
                $source = implode("\n", array_column($attrs, 'reference'));
                $search = $key."\n".$source;
                foreach ($attrs as $attr) {
                    $search .= "\n".implode("\n", $attr['translations']);
                }
                foreach (array_slice($proposals, $proposalOffset) as $proposal) {
                    if ($proposal['term_id'] === $id) {
                        $search .= "\n".$proposal['value'];
                    }
                }
                $term = new Term(['id' => $id, 'type' => $type, 'key' => $format->identity($row), 'label' => $key, 'context' => $context,
                    'identity_hash' => $format->identityHash($row), 'raw_identity_hash' => $format->identityHash($row), 'source_hash' => $format->sourceHash($row),
                    'semantic_hash' => $format->semanticHash($row), 'source_import_id' => $import->id, 'source_offset' => $offset, 'source_length' => strlen($bytes),
                    'source_text' => $source, 'search_text' => $search, 'validated' => $validated, 'review_needed' => [], 'obsolete' => false,
                    'revision_no' => 1, 'lock_version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => $now, 'updated_at' => $now]);
                $referenceValues = [];
                foreach ($attrs as $attribute => $attr) {
                    if ($attr['reference_language'] === 'de' && $attr['reference'] !== '') {
                        $referenceValues[] = ['attribute' => $attribute, 'value' => $attr['reference']];
                    }
                }
                $term->catalogue_reference_de = json_encode(['signature' => Projection::signature($term), 'type' => $term->type, 'source_hash' => $term->source_hash,
                    'source_import_id' => $import->id, 'source_offset' => $offset, 'source_length' => strlen($bytes), 'values' => $referenceValues], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $terms[] = $term->getAttributes();
                $presences[] = ['import_id' => $import->id, 'record_number' => $i + 1, 'offset' => $offset, 'length' => strlen($bytes), 'identity_hash' => $term->identity_hash,
                    'raw_identity_hash' => $term->raw_identity_hash, 'term_id' => $id, 'collision' => false, 'category' => 'new'];
                if (count($terms) === 200) {
                    $flush();
                    if ($id % 10000 < 200) {
                        $progress($id);
                    }
                }
            }
            $flush();
            fclose($handle);
            $import->update(['sha256' => hash_file('sha256', $path), 'size' => filesize($path), 'row_count' => $records]);
        }

        return ['terms' => $nextId - 1, 'background_terms' => $count, 'user_id' => $user->id, 'email' => $user->email, 'password' => $password,
            'expected' => $expected, 'corpus_sha256' => hash_file('sha256', base_path('tests/Fixtures/catalogue-search-corpus.php'))];
    }

    private function csv(array $values): string
    {
        return Encoding::encode(implode(';', array_map(fn ($value) => '"'.str_replace('"', '""', $value).'"', $values))."\r\n");
    }

    private function proposal(int $id, string $attribute, string $language, string $text, string $status, int $userId, int $importId, string $now): array
    {
        return ['term_id' => $id, 'attribute' => $attribute, 'language' => $language, 'value' => $text, 'value_hash' => hash('sha256', $text), 'status' => $status,
            'author_id' => $userId, 'import_id' => $importId, 'lock_version' => 1, 'anomalies' => '[]', 'edit_history' => '[]', 'created_at' => $now, 'updated_at' => $now];
    }

    private function mapValues(array &$row, FormatDefinition $format, array $values): void
    {
        if ($format->layout === 'pairs') {
            $row['Default Language'] = 'de_CH';
            $row['Default Label'] = $values['label']['de'] ?? '';
            foreach (['fr', 'it', 'en'] as $index => $language) {
                $row['Language '.($index + 1)] = FormatDefinition::LANGUAGES[$language];
                $row['Translation '.($index + 1)] = $values['label'][$language] ?? '';
            }
        } else {
            foreach ($values as $attribute => $languages) {
                foreach ($languages as $language => $text) {
                    $column = match ($format->layout) {
                        'nls' => FormatDefinition::LANGUAGES[$language], 'law' => $attribute.' '.FormatDefinition::LANGUAGES[$language], default => $attribute.'_'.strtoupper($language),
                    };
                    $row[$column] = $text;
                }
            }
        }
    }

    private function text(string $language, int $index): string
    {
        $nouns = [
            'de' => ['Dossier', 'Adresse', 'Person', 'Bericht', 'Vorfall', 'Dokument', 'Fahrzeug', 'Nachricht', 'Formular', 'Entscheidung', 'Empfang', 'Bewertung', 'Kommentar', 'Organisation', 'Mitteilung', 'Datei', 'Publikation', 'Kampagne', 'Übersetzung', 'Benutzer'],
            'fr' => ['dossier', 'adresse', 'personne', 'rapport', 'incident', 'document', 'véhicule', 'message', 'formulaire', 'décision', 'réception', 'évaluation', 'commentaire', 'organisation', 'notification', 'fichier', 'publication', 'campagne', 'traduction', 'utilisateur'],
            'it' => ['fascicolo', 'indirizzo', 'persona', 'rapporto', 'incidente', 'documento', 'veicolo', 'messaggio', 'formulario', 'decisione', 'ricezione', 'valutazione', 'commento', 'organizzazione', 'notifica', 'archivio', 'pubblicazione', 'campagna', 'traduzione', 'utente'],
            'en' => ['folder', 'address', 'person', 'report', 'incident', 'document', 'vehicle', 'message', 'application', 'decision', 'reception', 'evaluation', 'comment', 'organisation', 'notification', 'archive', 'publication', 'campaign', 'translation', 'operator'],
        ];
        $verbs = ['de' => ['öffnen', 'erstellen', 'speichern', 'drucken', 'schliessen'], 'fr' => ['ouvrir', 'créer', 'enregistrer', 'imprimer', 'fermer'],
            'it' => ['aprire', 'creare', 'registrare', 'stampare', 'chiudere'], 'en' => ['open', 'create', 'save', 'print', 'close']];
        $text = $verbs[$language][intdiv($index, 20) % 5].' '.$nouns[$language][$index % 20].' — '.['de' => 'Bereich', 'fr' => 'section', 'it' => 'sezione', 'en' => 'section'][$language].' '.($index % 200);

        return $index % 500 === 0 ? $text."\n".str_repeat($text.' ', 8).' {field}' : $text;
    }
}
