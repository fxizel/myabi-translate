<?php

namespace Tests\Unit;

use App\Services\BrowserExtension\TextNormalizer;
use App\Services\CatalogueSearch\Alignment;
use App\Services\CatalogueSearch\Budget;
use App\Services\CatalogueSearch\Profile;
use Tests\TestCase;

class CatalogueSearchProfileTest extends TestCase
{
    public function test_unicode_offsets_and_safe_highlighting_preserve_original_text(): void
    {
        $profile = new Profile(new TextNormalizer);
        $text = "<img> École e\u{0301}tat Straße 😀";
        self::assertSame('ecole', $profile->tokens($text)[1]['word']);
        $ranges = $profile->literalRanges($text, 'ecole');
        self::assertSame('&lt;img&gt; <mark>École</mark> état Straße 😀', (string) $profile->highlight($text, $ranges));
        self::assertSame([[18, 6]], $profile->literalRanges($text, 'strasse'));
        self::assertSame(1, $profile->distance('日本語', '日語本'));
        self::assertSame(1, $profile->distance('dossier', 'dosiser'));
        // OSA is intentionally not unrestricted Damerau-Levenshtein (CA -> ABC costs 3).
        self::assertSame(3, $profile->distance('ca', 'abc', 3));
        $large = str_repeat('abc ', 200000).'École';
        self::assertSame([[800000, 5]], $profile->literalRanges($large, 'ecole'));
        $excerpt = $profile->excerpt($large, [[800000, 5]]);
        self::assertLessThanOrEqual(302, mb_strlen($excerpt['text']));
        self::assertStringContainsString('<mark>École</mark>', (string) $profile->highlight($excerpt['text'], $excerpt['excerpt_ranges']));
    }

    public function test_length_rules_families_and_meaning_words_are_explicit(): void
    {
        $profile = app(Profile::class);
        self::assertNull($profile->cost('ab', 'ac'));
        self::assertSame(1, $profile->cost('opne', 'open'));
        self::assertNull($profile->cost('case', 'casa'));
        self::assertSame(1, $profile->cost('dosier', 'dossier'));
        self::assertNull($profile->cost('dossier13', 'dossier14', true));
        self::assertSame(['ouvrir' => 0, 'ouverture' => 1], $profile->variants('ouvrir', 'fr'));
        self::assertSame(['dossier', 'sans', 'erreur', '{field}', '13'], array_column($profile->important('le dossier sans erreur {field} 13', 'fr'), 'word'));
        self::assertSame(2, count($profile->tokens('dossier dossier')));
        self::assertSame(['sous', 'dossier', 'ref-13', 'ref.key'], array_column($profile->tokens('sous-dossier ref-13 ref.key'), 'word'));
        self::assertSame('_field', $profile->tokens('_field')[0]['word']);
        self::assertTrue($profile->tokens('_field')[0]['protected']);
        $variables = $profile->tokens('%s %1$s ${field} $field -13 +13 13');
        self::assertSame(['%s', '%1$s', '${field}', '$field', '-13', '+13', '13'], array_column($variables, 'word'));
        self::assertNotContains(false, array_column($variables, 'protected'));
    }

    public function test_assignment_never_reuses_an_occurrence_and_prefers_query_order(): void
    {
        $edge = fn ($cost, $start) => ['cost' => $cost, 'start' => $start, 'length' => 1];
        $alignment = new Alignment;
        self::assertNull($alignment->match([[0 => $edge(0, 0)], [0 => $edge(0, 0)]], new Budget));
        $ordered = $alignment->match([[1 => $edge(0, 1)], [0 => $edge(0, 0), 2 => $edge(0, 2)]], new Budget);
        self::assertSame(0, $ordered['disorder']);
        self::assertSame([[1, 1], [2, 1]], $ordered['ranges']);
        $unordered = $alignment->match([[1 => $edge(0, 1)], [0 => $edge(0, 0)]], new Budget);
        self::assertSame(1, $unordered['disorder']);
    }
}
