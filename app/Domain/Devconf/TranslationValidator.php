<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use App\Services\LocalizedMessage;
use Illuminate\Container\Container;
use InvalidArgumentException;

final class TranslationValidator
{
    private const FRENCH_MESSAGES = [
        'empty' => 'La proposition ne peut pas être vide.',
        'encoding' => 'La proposition contient des caractères non exportables en Windows-1252.',
        'placeholders' => 'Les placeholders et leur nombre doivent être identiques à la référence.',
        'identical' => 'La traduction est identique à la référence.',
        'maximum_length' => 'La traduction dépasse la longueur maximale indiquée.',
        'relative_length' => 'La traduction dépasse deux fois la longueur de la référence.',
    ];

    /** Optional application translation; standalone PHP retains readable French diagnostics. */
    public static function message(string $code): string
    {
        $fallback = self::FRENCH_MESSAGES[$code] ?? $code;
        if (class_exists(Container::class, false)) {
            $container = Container::getInstance();
            if ($container->bound('translator')) {
                $translator = $container->make('translator');
                $key = 'translation_checks.'.$code;
                if ($translator->has($key)) {
                    return $translator->get($key);
                }
            }
        }

        return $fallback;
    }

    /** Decode stable codes and historical translated messages at display time. */
    public static function displayMessage(string|array $diagnostic): string
    {
        if (is_array($diagnostic)) {
            $diagnostic = $diagnostic['code'] ?? $diagnostic['message'] ?? json_encode($diagnostic, JSON_UNESCAPED_UNICODE);
        }
        $code = array_key_exists($diagnostic, self::FRENCH_MESSAGES) ? $diagnostic : array_search($diagnostic, self::FRENCH_MESSAGES, true);

        if ($code !== false) {
            return self::message($code);
        }
        if (class_exists(Container::class, false) && Container::getInstance()->bound('translator')) {
            $translator = Container::getInstance()->make('translator');
            foreach (self::FRENCH_MESSAGES as $legacyCode => $legacyMessage) {
                foreach (['de', 'fr', 'it'] as $locale) {
                    if ($diagnostic === $translator->get($legacyMessage, [], $locale)) {
                        return self::message($legacyCode);
                    }
                }
            }

            return LocalizedMessage::display($diagnostic);
        }

        return $diagnostic;
    }

    /** Exact multisets retain duplicate and unnamed placeholders. */
    public static function placeholders(string $value): array
    {
        preg_match_all('/\{[^{}\r\n]*\}|\[[\p{L}_][\p{L}\p{N}_. -]*\]|(?<!%)%(?!%)(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/u', $value, $matches);
        $counts = array_count_values($matches[0] ?? []);
        ksort($counts);

        return $counts;
    }

    /** @return array{errors: list<string>, warnings: list<string>, error_codes: list<string>, warning_codes: list<string>, placeholders: array<string,int>} */
    public static function validate(string $reference, string $value, ?int $maximumLength = null): array
    {
        $errors = $warnings = [];
        if (trim($value) === '') {
            $errors[] = 'empty';
        }
        try {
            Encoding::encode($value);
        } catch (InvalidArgumentException) {
            $errors[] = 'encoding';
        }
        $placeholders = self::placeholders($reference);
        if ($placeholders !== self::placeholders($value)) {
            $errors[] = 'placeholders';
        }
        if ($value === $reference && $value !== '') {
            $warnings[] = 'identical';
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($maximumLength !== null && $length > $maximumLength) {
            $warnings[] = 'maximum_length';
        }
        if ($reference !== '' && $length > 2 * mb_strlen($reference, 'UTF-8')) {
            $warnings[] = 'relative_length';
        }

        return ['errors' => array_map(self::message(...), $errors), 'warnings' => array_map(self::message(...), $warnings),
            'error_codes' => $errors, 'warning_codes' => $warnings, 'placeholders' => $placeholders];
    }

    public static function assertValid(string $reference, string $value, ?int $maximumLength = null): void
    {
        $result = self::validate($reference, $value, $maximumLength);
        if ($result['errors'] !== []) {
            throw new InvalidArgumentException(implode(' ', $result['errors']));
        }
    }
}
