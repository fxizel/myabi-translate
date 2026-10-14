<?php

namespace App\Services\BrowserExtension;

final class RequestValidator
{
    private const STRINGS = [
        'text' => 500, 'lang' => 12, 'pageUrl' => 2000, 'frameUrl' => 2000,
        'pageTitle' => 200, 'ariaLabel' => 200, 'title' => 200,
        'surroundingText' => 600, 'parentText' => 400, 'elementTag' => 40, 'elementId' => 100,
        'role' => 200, 'elementName' => 200, 'inputType' => 200, 'formLabel' => 200,
        'sectionTitle' => 200, 'dialogTitle' => 200, 'tableColumn' => 200,
    ];

    private const LISTS = [
        'elementClasses' => [20, 60], 'alternativeTexts' => [3, 300],
        'siblingTexts' => [6, 100], 'breadcrumbs' => [8, 80],
    ];

    public function search(array $input): array
    {
        $this->version($input);
        $allowed = array_merge(array_keys(self::STRINGS), array_keys(self::LISTS), [
            'contractVersion', 'textTruncated', 'searchLanguage', 'uiLanguage', 'maxResults',
            'dataAttributes', 'ariaAttributes', 'inDialog',
        ]);
        $this->knownFields($input, $allowed);
        $data = ['contractVersion' => 2];
        foreach (self::STRINGS as $field => $limit) {
            $data[$field] = $this->string($input, $field, $limit, $field === 'text');
        }
        if ((new TextNormalizer)->literal($data['text']) === '') {
            $this->invalid('text', 'required');
        }
        if (! array_key_exists('textTruncated', $input) || ! is_bool($input['textTruncated'])) {
            $this->invalid('textTruncated', 'boolean_required');
        }
        $data['textTruncated'] = $input['textTruncated'];
        foreach (self::LISTS as $field => [$count, $length]) {
            $items = array_key_exists($field, $input) ? $input[$field] : [];
            if (! is_array($items) || ! array_is_list($items) || count($items) > $count) {
                $this->invalid($field, 'list_limit');
            }
            foreach ($items as $item) {
                if (! is_string($item) || mb_strlen($item, 'UTF-8') > $length) {
                    $this->invalid($field, 'string_limit');
                }
            }
            $data[$field] = $items;
        }
        foreach (['dataAttributes' => [20, 'data'], 'ariaAttributes' => [10, 'aria']] as $field => [$count, $prefix]) {
            $items = array_key_exists($field, $input) ? $input[$field] : [];
            if (! is_array($items) || count($items) > $count) {
                $this->invalid($field, 'map_limit');
            }
            foreach ($items as $name => $value) {
                if (! is_string($name) || ! preg_match('/\A'.$prefix.'-[a-z0-9_.:-]{1,80}\z/i', $name)
                    || ! is_string($value) || mb_strlen($value, 'UTF-8') > 100) {
                    $this->invalid($field, 'invalid_entry');
                }
            }
            $data[$field] = $items;
        }
        if (array_key_exists('inDialog', $input) && ! is_bool($input['inDialog'])) {
            $this->invalid('inDialog', 'boolean');
        }
        $data['inDialog'] = $input['inDialog'] ?? false;
        $data['maxResults'] = array_key_exists('maxResults', $input) ? $input['maxResults'] : 5;
        if (! is_int($data['maxResults']) || $data['maxResults'] < 1 || $data['maxResults'] > 20) {
            $this->invalid('maxResults', 'integer_range');
        }
        $data['uiLanguage'] = array_key_exists('uiLanguage', $input) ? $input['uiLanguage'] : 'fr';
        if (! in_array($data['uiLanguage'], ['de', 'fr', 'en'], true)) {
            $this->invalid('uiLanguage', 'unsupported');
        }
        if (array_key_exists('searchLanguage', $input)) {
            if (! in_array($input['searchLanguage'], ['de', 'fr', 'it', 'en'], true)) {
                $this->invalid('searchLanguage', 'unsupported');
            }
            $data['searchLanguage'] = $input['searchLanguage'];
        }
        $language = $data['searchLanguage'] ?? explode('-', strtolower(str_replace('_', '-', $data['lang'])))[0];
        if (! in_array($language, ['de', 'fr', 'it', 'en'], true)) {
            throw new ApiProblem(422, 'search_language_required', ['lang' => 'unsupported']);
        }
        $data['searchLanguage'] = $language;
        foreach (['pageUrl', 'frameUrl'] as $field) {
            $data[$field] = $this->origin($data[$field], $field);
        }

        return $data;
    }

    public function feedback(array $input): array
    {
        $this->version($input);
        $this->knownFields($input, ['contractVersion', 'resultId', 'feedbackProof', 'verdict', 'text', 'key', 'pageUrl']);
        if (($input['verdict'] ?? null) !== 'correct') {
            $this->invalid('verdict', 'unsupported');
        }
        $data = [
            'contractVersion' => 2, 'verdict' => 'correct',
            'resultId' => $this->string($input, 'resultId', 1024, true),
            'feedbackProof' => $this->string($input, 'feedbackProof', 4096, true),
        ];
        foreach (['text' => 500, 'key' => 300, 'pageUrl' => 2000] as $field => $limit) {
            if (array_key_exists($field, $input)) {
                $this->string($input, $field, $limit);
            }
        }

        return $data;
    }

    private function version(array $input): void
    {
        if (($input['contractVersion'] ?? null) !== 2) {
            throw new ApiProblem(426, 'client_upgrade_required');
        }
    }

    private function knownFields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->invalid('request', 'unknown_field');
        }
    }

    private function string(array $input, string $field, int $limit, bool $required = false): string
    {
        $value = array_key_exists($field, $input) ? $input[$field] : '';
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit) {
            $this->invalid($field, 'string_limit');
        }
        if ($required && $value === '') {
            $this->invalid($field, 'required');
        }

        return $value;
    }

    private function origin(string $url, string $field): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            $this->invalid($field, 'url');
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function invalid(string $field, string $rule): never
    {
        throw new ApiProblem(422, 'invalid_request', [$field => $rule]);
    }
}
