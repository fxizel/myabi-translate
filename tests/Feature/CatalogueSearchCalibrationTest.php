<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\User;
use App\Services\CatalogueSearch\Projection;
use App\Services\CatalogueSearch\Search;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueSearchCalibrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_frozen_calibration_and_held_out_annotations(): void
    {
        $cases = require base_path('tests/Fixtures/catalogue-search-corpus.php');
        self::assertCount(400, $cases);
        $this->verifyAnnotations($cases, 'catalogue-search-corpus.php', 200, '');
    }

    public function test_supplementary_insertions_substitutions_and_two_edits(): void
    {
        $cases = require base_path('tests/Fixtures/catalogue-search-corrections.php');
        self::assertCount(96, $cases);
        $this->verifyAnnotations($cases, 'catalogue-search-corrections.php', 48, '.supplement.json');
    }

    private function verifyAnnotations(array $cases, string $fixture, int $perSplit, string $suffix): void
    {
        $user = User::factory()->create(['roles' => ['reader']]);
        $attributes = $expected = [];
        foreach ($cases as $case) {
            foreach ($case['candidates'] as $index => $candidate) {
                $attribute = $case['secondary'] ? 'SHORTTEXT' : 'label';
                $label = $case['id'].'-'.$index;
                $reference = $candidate['origin'] === 'reference' ? $candidate['text'] : 'Allgemeine Bezeichnung';
                $validated = $candidate['origin'] === 'current' ? [$attribute => [$candidate['language'] => $candidate['text']]] : [];
                $term = Term::create(['type' => $case['secondary'] ? 'incident' : 'server', 'identity_hash' => hash('sha256', $label), 'key' => [$label], 'label' => $label,
                    'context' => $case['id'], 'source_text' => $reference, 'search_text' => $reference."\n".$candidate['text'],
                    'semantic_hash' => str_repeat('a', 64), 'source_hash' => hash('sha256', $reference), 'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
                    'validated' => $validated, 'review_needed' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);
                foreach (FormatRegistry::get($term->type)->attributeNames as $name) {
                    $attributes[$term->id][$name] = ['reference' => $name === $attribute ? $reference : 'Allgemeine Bezeichnung', 'reference_language' => 'de', 'translations' => [], 'columns' => []];
                }
                if (in_array($candidate['origin'], ['proposal', 'rejected'], true)) {
                    Proposal::create(['term_id' => $term->id, 'attribute' => $attribute, 'language' => $candidate['language'], 'value' => $candidate['text'],
                        'value_hash' => hash('sha256', $candidate['text']), 'status' => $candidate['origin'] === 'proposal' ? 'pending' : 'rejected',
                        'author_id' => $user->id, 'anomalies' => [], 'edit_history' => []]);
                }
                if ($candidate['relevant']) {
                    $expected[$case['id']][] = $term->id;
                }
            }
        }
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturnUsing(fn ($term) => $attributes[$term->id]);
        app(Projection::class)->rebuild();
        $totals = [];
        $missed = $falsePositives = [];
        foreach ($cases as $case) {
            $result = app(Search::class)->search(Term::visibleTo($user)->where('context', $case['id']), ['q' => $case['query'], 'language' => $case['language'], 'per_page' => 10], 1, hrtime(true) / 1e9);
            self::assertContains($result['status'], ['complete', 'exact_only'], $case['id']);
            $relevant = $expected[$case['id']] ?? [];
            $split = $case['split'];
            $totals[$split] ??= ['queries' => 0, 'evaluated' => 0, 'relevant' => 0];
            $totals[$split]['queries']++;
            foreach ($result['matches'] as $id => $match) {
                if ($match['approximate']) {
                    $totals[$split]['evaluated']++;
                    $totals[$split]['relevant'] += in_array($id, $relevant, true) ? 1 : 0;
                    if (! in_array($id, $relevant, true)) {
                        $falsePositives[] = $case['id'];
                    }
                }
            }
            if ($case['required'] && ! array_intersect($relevant, $result['ids']->all())) {
                $missed[] = $case['id'];
            }
        }
        if (getenv('MYABI_CALIBRATION_REPORT')) {
            file_put_contents(getenv('MYABI_CALIBRATION_REPORT').$suffix, json_encode(['corpus_version' => 1, 'annotations_sha256' => hash_file('sha256', base_path('tests/Fixtures/'.$fixture)),
                'splits' => $totals, 'missed' => $missed, 'false_positives' => $falsePositives], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $missed, 'Mandatory positive cases missed');
        foreach ($totals as $split => $counts) {
            self::assertSame($perSplit, $counts['queries']);
            self::assertGreaterThan(0, $counts['evaluated']);
            self::assertGreaterThanOrEqual(.95, $counts['relevant'] / $counts['evaluated'], $split.' precision');
        }
    }
}
