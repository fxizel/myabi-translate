<?php

namespace App\Services;

use Illuminate\Support\Facades\Lang;

/** Keep background diagnostics independent of the worker's interface language. */
final class LocalizedMessage
{
    public static function store(string|array $messages): string
    {
        $messages = array_map(self::key(...), (array) $messages);

        return count($messages) === 1 ? reset($messages)
            : json_encode(['myabi_messages' => array_values($messages)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function display(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }
        $stored = json_decode($message, true);
        if (is_array($stored) && isset($stored['myabi_error'], $stored['reference'])
            && in_array($stored['myabi_error'], ['ui.operation_failed', 'ui.initial_failed', 'ui.filtered_failed'], true)
            && is_string($stored['reference']) && preg_match('/\A[0-9a-f-]{36}\z/', $stored['reference'])) {
            return __($stored['myabi_error']).' '.__('ui.failure_reference', ['reference' => $stored['reference']]);
        }
        if (is_array($stored) && array_keys($stored) === ['myabi_messages']
            && is_array($stored['myabi_messages'])
            && array_all($stored['myabi_messages'], fn ($value) => is_string($value))) {
            return implode(' ', array_map(self::display(...), $stored['myabi_messages']));
        }

        // Also recognize diagnostics already saved as German/French/Italian prose.
        $key = self::key($message);
        if ($key === 'ui.publication_source_partial') {
            return __('ui.publication_legacy_rule');
        }
        if (self::isKey($key)) {
            return __($key);
        }

        // Older workers joined diagnostics with a space. Only translate when the
        // entire value consists of recognized messages, never a partial match.
        $remaining = $message;
        $translated = [];
        $known = self::knownMessages();
        uksort($known, fn ($a, $b) => strlen($b) <=> strlen($a));
        while ($remaining !== '') {
            $matched = false;
            foreach ($known as $text => $messageKey) {
                if ($remaining === $text || str_starts_with($remaining, $text.' ')) {
                    $translated[] = self::display($messageKey);
                    $remaining = substr($remaining, strlen($text));
                    $remaining = str_starts_with($remaining, ' ') ? substr($remaining, 1) : $remaining;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return $message;
            }
        }

        return implode(' ', $translated);
    }

    private static function isKey(string $message): bool
    {
        return preg_match('/\A(?:ui|translation_checks)\.[a-z_]+\z/', $message) === 1 && Lang::has($message);
    }

    private static function key(string $message): string
    {
        if (self::isKey($message)) {
            return $message;
        }

        return self::knownMessages()[$message] ?? $message;
    }

    /** @return array<string, string> Exact historical wording to stable resource keys. */
    private static function knownMessages(): array
    {
        $messages = [];
        foreach (['de', 'fr', 'it'] as $locale) {
            foreach (['ui', 'translation_checks'] as $group) {
                foreach (Lang::get($group, [], $locale) as $key => $text) {
                    if (is_string($text) && $text !== '' && ! preg_match('/:[a-z_]+/i', $text)) {
                        $messages[$text] = $group.'.'.$key;
                    }
                }
            }
        }

        return $messages;
    }
}
