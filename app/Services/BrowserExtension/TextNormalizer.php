<?php

namespace App\Services\BrowserExtension;

use Normalizer;

final class TextNormalizer
{
    public const VERSION = 2;

    public function literal(?string $value): string
    {
        $value = Normalizer::normalize($value ?? '', Normalizer::FORM_C);
        $value = preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{2060}\x{202A}-\x{202E}\x{FEFF}]/u', '', $value);
        $value = preg_replace('/[\x{E000}-\x{F8FF}]/u', ' ', $value);

        // ECMAScript whitespace differs from PCRE \s (notably U+0085/U+180E).
        // Trim only the spaces produced here; PHP trim would also remove NUL.
        return trim(preg_replace('/[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $value), ' ');
    }

    public function searchKey(?string $value): string
    {
        $value = Normalizer::normalize(mb_strtolower($this->literal($value), 'UTF-8'), Normalizer::FORM_D);
        $value = preg_replace('/\p{M}/u', '', $value);

        return str_replace(['‘', '’', '‛', '′', '`', '´'], "'", $value);
    }

    public function tokens(string $normalized): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);

        return array_values(array_unique($matches[0]));
    }
}
