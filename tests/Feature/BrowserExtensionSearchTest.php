<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\SearchProjection;
use App\Services\BrowserExtension\SearchService;
use App\Services\BrowserExtension\TextNormalizer;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrowserExtensionSearchTest extends TestCase
{
    use RefreshDatabase;

    private array $attributes = [];

    private User $reader;

    private ?int $failSource = null;

    private mixed $onSourceRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = User::factory()->create(['roles' => ['reader']]);
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturnUsing(function ($term) {
            if ($this->failSource === $term->id) {
                throw new \RuntimeException('Private source sentinel must not escape.');
            }
            if ($this->onSourceRead) {
                ($this->onSourceRead)($term);
            }

            return $this->attributes[$term->id];
        });
    }

    public function test_all_seven_formats_and_every_attribute_are_searchable(): void
    {
        $expected = [];
        foreach (FormatRegistry::all() as $format) {
            $term = $this->term($format->code.'-key', type: $format->code);
            foreach ($format->attributeNames as $attribute) {
                $expected[] = [$term->id, $attribute, $format->code.' '.$attribute.' français'];
            }
        }
        $this->rebuild();
        foreach ($expected as [$id, $attribute, $text]) {
            $response = $this->search($text);
            self::assertSame('strong', $response['matchState']);
            self::assertSame(1, $response['exactCandidateCount']);
            self::assertSame($id, $response['results'][0]['termId']);
            self::assertSame($attribute, $response['results'][0]['attribute']);
            self::assertSame('missing', $response['results'][0]['status']);
            self::assertSame('imported', $response['results'][0]['valueOrigin']);
        }
    }

    public function test_multiple_exacts_are_counted_before_limit_and_hint_does_not_hide_them(): void
    {
        $a = $this->term('B', imported: 'Identique');
        $b = $this->term('A', imported: 'Identique');
        $this->rebuild();
        $response = $this->search('Identique', ['maxResults' => 1, 'dataAttributes' => ['data-i18n-key' => 'B']]);
        self::assertSame('multiple', $response['matchState']);
        self::assertSame(2, $response['exactCandidateCount']);
        self::assertTrue($response['hasMore']);
        self::assertTrue($response['candidateSetComplete']);
        self::assertCount(1, $response['results']);
        self::assertSame($a->id, $response['results'][0]['termId']);
        $identity = json_decode(base64_decode(strtr(substr($response['results'][0]['id'], 6), '-_', '+/')), true);
        self::assertSame([$a->id, 'label', 'fr'], $identity);
        self::assertNotSame($b->id, $identity[0]);
    }

    public function test_best_origin_preserves_imported_exact_and_validation_remains_separate(): void
    {
        $this->term('A', imported: 'Trouvé', validated: ['label' => ['fr' => 'Autre valeur validée']]);
        $same = $this->term('B', imported: 'Même', validated: ['label' => ['fr' => 'Même']]);
        $this->rebuild();
        $result = $this->search('Trouvé')['results'][0];
        self::assertSame('imported', $result['valueOrigin']);
        self::assertSame('Autre valeur validée', $result['currentTranslation']);
        self::assertSame('validated', $result['status']);
        $response = $this->search('Même');
        self::assertSame(1, $response['exactCandidateCount']);
        self::assertSame($same->id, $response['results'][0]['termId']);
        self::assertSame('validated', $response['results'][0]['valueOrigin']);
    }

    public function test_unknown_reference_and_truncated_query_never_certify_exact(): void
    {
        $term = $this->term('unknown', imported: 'Autre');
        $this->attributes[$term->id]['label']['reference'] = 'Inconnue';
        $this->attributes[$term->id]['label']['reference_language'] = null;
        $this->rebuild();
        $response = $this->search('Inconnue');
        self::assertSame('noExact', $response['matchState']);
        self::assertSame(0, $response['exactCandidateCount']);
        self::assertFalse($response['results'][0]['exact']);
        self::assertSame('', $response['results'][0]['sourceLanguage']);
        self::assertEquals(0.88, $response['results'][0]['score']);
        $response = $this->search('Autre', ['textTruncated' => true]);
        self::assertFalse($response['candidateSetComplete']);
        self::assertFalse($response['results'][0]['exact']);
        self::assertSame(0, $response['exactCandidateCount']);
    }

    public function test_prefix_tokens_accents_alternatives_and_short_text(): void
    {
        $this->term('prefix', imported: 'enregistrer');
        $this->term('literal', imported: 'Été');
        $this->term('normalized', imported: 'Ete');
        $this->term('tokens', imported: 'grand bouton vert');
        $this->rebuild();
        $prefix = $this->search('enregis');
        self::assertSame('approximate', $prefix['results'][0]['matchReason']);
        self::assertFalse($prefix['results'][0]['exact']);
        $response = $this->search('Été');
        self::assertSame('multiple', $response['matchState']);
        self::assertSame(1, $response['exactCandidateCount']);
        self::assertSame('normalized', $response['results'][1]['matchReason']);
        self::assertSame('approximate', $this->search('bouton vert')['results'][0]['matchReason']);
        self::assertSame('alternative', $this->search('absent', ['alternativeTexts' => ['enregistrer']])['results'][0]['matchReason']);
        self::assertSame('none', $this->search('en')['matchState']);
    }

    public function test_proposals_are_not_corpus_and_pending_is_counted_without_selection(): void
    {
        $term = $this->term('A', imported: 'Courante');
        foreach (['pending', 'pending', 'rejected'] as $status) {
            Proposal::create(['term_id' => $term->id, 'attribute' => 'label', 'language' => 'fr', 'value' => 'Seulement proposition',
                'value_hash' => hash('sha256', 'Seulement proposition'), 'status' => $status, 'author_id' => $this->reader->id, 'anomalies' => [], 'edit_history' => []]);
        }
        $this->rebuild();
        self::assertSame('none', $this->search('Seulement proposition')['matchState']);
        $result = $this->search('Courante')['results'][0];
        self::assertSame(2, $result['pendingCount']);
        self::assertSame('', $result['proposedTranslation']);
    }

    public function test_bulk_mutation_fails_closed_and_sync_is_transactional(): void
    {
        $term = $this->term('A', imported: 'Importée');
        $this->rebuild();
        DB::beginTransaction();
        DB::table('terms')->where('id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Décision']]), 'revision_no' => 2]);
        try {
            $this->search('Importée');
            self::fail('Dirty projection must fail closed.');
        } catch (ApiProblem $e) {
            self::assertSame(503, $e->status);
        }
        app(SearchProjection::class)->syncMany(collect([$term->fresh()]), $this->attributes);
        self::assertSame('validated', $this->search('Décision')['results'][0]['valueOrigin']);
        DB::rollBack();
        self::assertSame('none', $this->search('Décision')['matchState']);
        self::assertSame('strong', $this->search('Importée')['matchState']);
    }

    public function test_fuzzy_cap_does_not_lose_exacts_and_historical_terms_are_excluded(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->term('prefix-'.$i, imported: 'mot partagé '.$i);
        }
        $exact = $this->term('last', imported: 'mot partagé');
        $obsolete = $this->term('obsolete', imported: 'mot partagé');
        $legacy = $this->term('legacy', imported: 'mot partagé');
        DB::table('terms')->where('id', $obsolete->id)->update(['obsolete' => true]);
        DB::table('terms')->where('id', $legacy->id)->update(['consolidated_into_id' => $exact->id]);
        $this->rebuild();
        $response = $this->search('mot partagé', ['maxResults' => 1]);
        self::assertSame(1, $response['exactCandidateCount']);
        self::assertSame($exact->id, $response['results'][0]['termId']);
        self::assertSame('multiple', $response['matchState']);
        self::assertFalse($response['candidateSetComplete']);
        self::assertTrue($response['hasMore']);
    }

    public function test_hash_collision_cannot_create_false_exact_and_rebuild_is_repeatable(): void
    {
        $term = $this->term('A', imported: 'Vraie valeur');
        $this->rebuild();
        DB::table(SearchProjection::VALUES)->where('term_id', $term->id)->update(['literal_hash' => hash('sha256', 'Fausse'), 'normalized_hash' => hash('sha256', 'fausse')]);
        self::assertSame('none', $this->search('Fausse')['matchState']);
        $before = DB::table(SearchProjection::VALUES)->count();
        $this->rebuild();
        self::assertSame($before, DB::table(SearchProjection::VALUES)->count());
        self::assertSame('strong', $this->search('Vraie valeur')['matchState']);
    }

    public function test_failed_source_resumes_after_committed_cursor_and_never_activates_partial_generation(): void
    {
        for ($i = 0; $i < 201; $i++) {
            $last = $this->term('resume-'.$i, imported: 'Reprise '.$i);
        }
        $this->failSource = $last->id;
        try {
            $this->rebuild();
            self::fail('Partial generation must not activate.');
        } catch (ApiProblem $e) {
            self::assertSame('search_index_unavailable', $e->getMessage());
            self::assertStringNotContainsString('sentinel', $e->getMessage());
        }
        $state = DB::table(SearchProjection::STATE)->first();
        self::assertSame($last->id - 1, $state->cursor);
        self::assertFalse((bool) $state->ready);
        $generation = $state->build_generation;
        $this->failSource = null;
        $this->rebuild();
        self::assertSame($generation, DB::table(SearchProjection::STATE)->value('active_generation'));
        $response = $this->search('Reprise 200');
        self::assertSame(1, $response['exactCandidateCount']);
        self::assertSame($last->id, $response['results'][0]['termId']);
    }

    public function test_mutation_during_rebuild_restarts_instead_of_certifying_missing_cell(): void
    {
        $term = $this->term('mutation', imported: 'Importée');
        $this->onSourceRead = function () use ($term) {
            $this->onSourceRead = null;
            DB::table('terms')->where('id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Nouvelle décision']]), 'revision_no' => 2]);
        };
        try {
            $this->rebuild();
            self::fail('Changed catalogue must restart its build.');
        } catch (ApiProblem $e) {
            self::assertSame(503, $e->status);
        }
        self::assertFalse((bool) DB::table(SearchProjection::STATE)->value('ready'));
        $this->rebuild();
        self::assertSame('validated', $this->search('Nouvelle décision')['results'][0]['valueOrigin']);
    }

    public function test_reference_languages_missing_columns_and_output_previews_are_independent(): void
    {
        $term = $this->term('reference', imported: 'Autre', validated: ['label' => ['it' => 'Italiano']]);
        $this->attributes[$term->id]['label']['reference'] = 'Référence française';
        $this->attributes[$term->id]['label']['reference_language'] = 'fr';
        $long = $this->term('long', imported: 'Libellé court');
        $this->attributes[$long->id]['label']['reference'] = str_repeat('x', 1500);
        $this->rebuild();
        $result = $this->search('Référence française')['results'][0];
        self::assertSame('reference', $result['valueOrigin']);
        self::assertSame('fr', $result['sourceLanguage']);
        self::assertTrue($result['exact']);
        $result = $this->search('Italiano', ['searchLanguage' => 'it', 'lang' => 'de-CH'])['results'][0];
        self::assertSame('validated', $result['valueOrigin']);
        self::assertSame('', $result['importedTranslation']);
        $response = $this->search('Libellé court', ['lang' => 'fr_CH']);
        self::assertSame('strong', $response['matchState']);
        self::assertTrue($response['results'][0]['previewTruncated']);
        self::assertSame(1000, mb_strlen($response['results'][0]['sourceText']));
    }

    public function test_migration_rollback_keeps_existing_projection_triggers(): void
    {
        $allNames = fn () => in_array(DB::getDriverName(), ['mysql', 'mariadb'])
            ? DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->pluck('TRIGGER_NAME')
            : DB::table('sqlite_master')->where('type', 'trigger')->pluck('name');
        $names = fn () => $allNames()->filter(fn ($name) => ! str_starts_with($name, 'be_search_'))->sort()->values()->all();
        $before = $names();
        $migration = require database_path('migrations/2026_10_14_130100_create_browser_extension_search.php');
        $migration->down();
        self::assertSame($before, $names());
        $migration->up();
        self::assertSame($before, $names());
        self::assertSame(3, $allNames()->filter(fn ($name) => str_starts_with($name, 'be_search_'))->count());
    }

    public function test_normalization_matches_client_unicode_and_literal_symbols(): void
    {
        $normalizer = app(TextNormalizer::class);
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/browser-extension-text.json')), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(TextNormalizer::VERSION, $fixtures['normalizationVersion']);
        foreach ($fixtures['cases'] as $case) {
            self::assertSame($case['literal'], $normalizer->literal($case['input']), $case['name']);
            self::assertSame($case['searchKey'], $normalizer->searchKey($case['input']), $case['name']);
        }
        self::assertSame('Été test %_!', $normalizer->literal(" E\u{0301}té\u{200B}\u{E001}\t test %_! "));
        self::assertSame("l'ete", $normalizer->searchKey('L’Été'));
        $this->term('spaces / identity', imported: '50%_! résultat');
        $this->rebuild();
        self::assertSame('strong', $this->search('50%_! résultat')['matchState']);
        self::assertSame('none', $this->search('50XYZ résultat')['matchState']);
    }

    public function test_ecmascript_non_spaces_do_not_certify_a_false_exact(): void
    {
        $this->term('next-line', imported: "A\u{0085}B");
        $this->term('mongolian', imported: "A\u{180E}B");
        $this->rebuild();
        $response = $this->search('A B');
        self::assertSame(0, $response['exactCandidateCount']);
        foreach ($response['results'] as $result) {
            self::assertFalse($result['exact']);
        }
    }

    public function test_old_normalization_generation_fails_before_an_unmatched_query(): void
    {
        $this->term('A', imported: 'Trouvable');
        $this->rebuild();
        DB::table(SearchProjection::STATE)->where('id', 1)->update(['normalization_version' => 1]);
        $this->expectException(ApiProblem::class);
        $this->expectExceptionMessage('search_index_unavailable');
        $this->search('absent');
    }

    public function test_benchmark_refuses_existing_business_data_without_writes(): void
    {
        $before = User::count();
        $this->assertDatabaseCount('terms', 0);
        $previous = getenv('MYABI_ISOLATED_TESTS');
        putenv('MYABI_ISOLATED_TESTS=1');
        try {
            $this->artisan('referentiel:extension-search-benchmark', ['--terms' => 1000, '--samples' => 20])
                ->expectsOutput('Requires an empty isolated local MariaDB testing schema and MYABI_ISOLATED_TESTS=1.')
                ->assertFailed();
        } finally {
            putenv($previous === false ? 'MYABI_ISOLATED_TESTS' : 'MYABI_ISOLATED_TESTS='.$previous);
        }
        self::assertSame($before, User::count());
        $this->assertDatabaseCount('terms', 0);
    }

    private function rebuild(): void
    {
        app(SearchProjection::class)->rebuild();
    }

    private function search(string $text, array $extra = []): array
    {
        return app(SearchService::class)->search($this->reader, [...['text' => $text, 'lang' => 'fr', 'textTruncated' => false, 'maxResults' => 5], ...$extra]);
    }

    private function term(string $key, string $type = 'server', ?string $imported = null, array $validated = []): Term
    {
        $term = Term::create(['type' => $type, 'identity_hash' => hash('sha256', $type.$key), 'key' => [$key], 'label' => $key, 'context' => '',
            'source_text' => 'Reference', 'search_text' => 'Reference', 'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1, 'validated' => $validated, 'review_needed' => [],
            'revision_no' => 1, 'lock_version' => 1, 'created_by' => $this->reader->id, 'updated_by' => $this->reader->id]);
        foreach (FormatRegistry::get($type)->attributeNames as $attribute) {
            $this->attributes[$term->id][$attribute] = ['reference' => 'German '.$key.' '.$attribute, 'reference_language' => 'de',
                'translations' => ['fr' => $imported ?? $type.' '.$attribute.' français'], 'columns' => ['fr' => 'fr_CH']];
        }

        return $term;
    }
}
