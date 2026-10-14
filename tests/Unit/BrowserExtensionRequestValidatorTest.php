<?php

namespace Tests\Unit;

use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\RequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrowserExtensionRequestValidatorTest extends TestCase
{
    private function request(array $changes = []): array
    {
        return array_replace(['contractVersion' => 2, 'text' => 'Exemple', 'textTruncated' => false, 'lang' => 'fr-CH'], $changes);
    }

    public function test_language_is_separate_from_interface_and_urls_are_reduced_to_origins(): void
    {
        $data = (new RequestValidator)->search($this->request([
            'uiLanguage' => 'de', 'lang' => 'it_CH',
            'pageUrl' => 'https://user:secret@example.org:8443/private/123?secret=yes#record',
            'frameUrl' => 'http://localhost:5173/private?query=yes',
        ]));
        self::assertSame('it', $data['searchLanguage']);
        self::assertSame('https://example.org:8443', $data['pageUrl']);
        self::assertSame('http://localhost:5173', $data['frameUrl']);
        self::assertSame('fr', (new RequestValidator)->search($this->request(['lang' => 'pt', 'searchLanguage' => 'fr']))['searchLanguage']);
    }

    public function test_unknown_language_is_not_inferred_from_interface(): void
    {
        try {
            (new RequestValidator)->search($this->request(['lang' => '', 'uiLanguage' => 'fr']));
            self::fail('A search language is required.');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('search_language_required', $problem->errorCode);
        }
    }

    public static function invalidRequests(): array
    {
        return [
            'null text' => [['text' => null]], 'oversize text' => [['text' => str_repeat('a', 501)]],
            'invisible text' => [['text' => "\u{200B}\u{FEFF}"]], 'icon only' => [['text' => "\u{E000}"]],
            'null optional string' => [['pageTitle' => null]], 'title limit' => [['pageTitle' => str_repeat('a', 201)]],
            'not boolean' => [['textTruncated' => 0]], 'unknown field' => [['private-value' => 'SENSITIVE']],
            'float limit' => [['maxResults' => 2.0]], 'string limit' => [['maxResults' => '2']],
            'null limit' => [['maxResults' => null]], 'zero limit' => [['maxResults' => 0]],
            'too many results' => [['maxResults' => 21]], 'null UI' => [['uiLanguage' => null]],
            'wrong map prefix' => [['dataAttributes' => ['aria-label' => 'value']]],
            'wrong aria prefix' => [['ariaAttributes' => ['data-key' => 'value']]],
            'long map' => [['dataAttributes' => ['data-i18n-key' => str_repeat('k', 101)]]],
            'null map' => [['dataAttributes' => null]], 'null list' => [['breadcrumbs' => null]],
            'map instead of list' => [['alternativeTexts' => ['first' => 'value']]],
            'too many alternatives' => [['alternativeTexts' => ['a', 'b', 'c', 'd']]],
            'invalid URL scheme' => [['pageUrl' => 'file:///private/path']],
            'invalid dialog' => [['inDialog' => 'yes']],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_fields_are_rejected_without_echo(array $changes): void
    {
        try {
            (new RequestValidator)->search($this->request($changes));
            self::fail('Invalid request accepted.');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('invalid_request', $problem->getMessage());
            self::assertStringNotContainsString('SENSITIVE', json_encode($problem->fields));
        }
    }

    public function test_unicode_limits_count_code_points_and_keep_complete_identity_hints(): void
    {
        $data = (new RequestValidator)->search($this->request([
            'text' => str_repeat('𐐀', 500), 'dataAttributes' => ['data-i18n-key' => str_repeat('K', 100)],
        ]));
        self::assertSame(500, mb_strlen($data['text']));
        self::assertSame(str_repeat('K', 100), $data['dataAttributes']['data-i18n-key']);
    }

    public function test_missing_or_non_integer_contract_version_requires_upgrade(): void
    {
        foreach ([[], ['contractVersion' => 1], ['contractVersion' => '2'], ['contractVersion' => 2.0]] as $input) {
            try {
                (new RequestValidator)->search($input);
                self::fail('Old contract accepted.');
            } catch (ApiProblem $problem) {
                self::assertSame(426, $problem->status);
            }
        }
    }

    public function test_feedback_legacy_values_are_validated_then_discarded(): void
    {
        $data = (new RequestValidator)->feedback([
            'contractVersion' => 2, 'resultId' => 'cell2.id', 'feedbackProof' => 'proof.signature',
            'verdict' => 'correct', 'text' => 'SENSITIVE', 'key' => 'DISPLAY', 'pageUrl' => 'https://example.org/private',
        ]);
        self::assertSame(['contractVersion', 'verdict', 'resultId', 'feedbackProof'], array_keys($data));
        self::assertStringNotContainsString('SENSITIVE', json_encode($data));
    }
}
