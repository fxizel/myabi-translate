<?php

namespace App\Services\CatalogueSearch;

use App\Services\BrowserExtension\TextNormalizer;
use Illuminate\Support\HtmlString;

final class Profile
{
    public const VERSION = 1;

    private array $families;

    public function __construct(private TextNormalizer $normalizer)
    {
        $this->families = require resource_path('search/lexical-families.php');
    }

    public function key(string $text): string
    {
        return str_replace(['ß', 'æ', 'œ'], ['ss', 'ae', 'oe'], $this->normalizer->searchKey($text));
    }

    /** Tokens retain original codepoint offsets, including combining marks and repetitions. */
    public function tokens(string $text): array
    {
        preg_match_all('/(?<!%)%(?!%)(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]|\$?\{[^{}]*\}|\$[\p{L}_][\p{L}\p{N}_]*|\[[^\[\]]+\]|(?=[+-]?[\p{L}\p{M}\p{N}_.\/-]*[\p{N}_.\/])(?:[+-](?=\p{N}))?[\p{L}\p{N}_][\p{L}\p{M}\p{N}_.\/-]*|[\p{L}\p{N}][\p{L}\p{M}\p{N}_]*/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $tokens = [];
        foreach ($matches[0] as [$word, $offset]) {
            $key = $this->key($word);
            $tokens[] = ['word' => $key, 'start' => mb_strlen(substr($text, 0, $offset)), 'length' => mb_strlen($word),
                'protected' => preg_match('/[\p{N}{}\[\]_.\/%$+-]/u', $key) === 1];
        }

        return $tokens;
    }

    public function important(string $text, string $language): array
    {
        $ignored = [
            'fr' => ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'd', 'l', 'a', 'au', 'aux'],
            'de' => ['der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines'],
            'it' => ['il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'una', 'di', 'del', 'della'],
            'en' => ['a', 'an', 'the', 'of'],
        ];

        return array_values(array_filter($this->tokens($text), fn ($token) => $token['protected'] || ! in_array($token['word'], $ignored[$language], true)));
    }

    public function variants(string $word, string $language): array
    {
        foreach ($this->families[$language] as $family) {
            if (in_array($word, $family, true)) {
                $variants = array_fill_keys($family, 1);
                $variants[$word] = 0;

                return $variants;
            }
        }

        return [$word => 0];
    }

    public function fragments(string $word): array
    {
        $chars = mb_str_split('^'.$word.'$');
        $grams = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            $gram = $chars[$i].$chars[$i + 1];
            $grams[$gram] = ($grams[$gram] ?? 0) + 1;
        }

        return $grams;
    }

    /** Optimal string alignment distance over Unicode characters, bounded by the caller's tolerance. */
    public function distance(string $left, string $right, int $limit = 2): int
    {
        $a = mb_str_split($left);
        $b = mb_str_split($right);
        if (abs(count($a) - count($b)) > $limit) {
            return $limit + 1;
        }
        $previous = range(0, count($b));
        $before = $previous;
        for ($i = 1; $i <= count($a); $i++) {
            $row = array_fill(0, count($b) + 1, $limit + 1);
            $row[0] = $i;
            for ($j = max(1, $i - $limit); $j <= min(count($b), $i + $limit); $j++) {
                $row[$j] = min($previous[$j] + 1, $row[$j - 1] + 1, $previous[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1));
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $row[$j] = min($row[$j], $before[$j - 2] + 1);
                }
            }
            $before = $previous;
            $previous = $row;
        }

        return $previous[count($b)];
    }

    public function cost(string $query, string $candidate, bool $protected = false): ?int
    {
        if ($query === $candidate) {
            return 0;
        }
        $length = min(mb_strlen($query), mb_strlen($candidate));
        if ($protected || $length < 3) {
            return null;
        }
        if ($length <= 4) {
            $chars = mb_str_split($query);
            for ($i = 0; $i < count($chars) - 1; $i++) {
                $copy = $chars;
                [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
                if (implode('', $copy) === $candidate) {
                    return 1;
                }
            }

            return null;
        }
        $limit = $length < 8 ? 1 : 2;
        $distance = $this->distance($query, $candidate, $limit);

        return $distance <= $limit ? $distance : null;
    }

    public function highlight(string $text, array $ranges): HtmlString
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $length]) {
            $end = $start + $length;
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($end, $merged[$last][1]);
            } else {
                $merged[] = [$start, $end];
            }
        }
        $result = '';
        $cursor = 0;
        foreach ($merged as [$start, $end]) {
            $result .= e(mb_substr($text, $cursor, $start - $cursor)).'<mark>'.e(mb_substr($text, $start, $end - $start)).'</mark>';
            $cursor = $end;
        }

        return new HtmlString($result.e(mb_substr($text, $cursor)));
    }

    public function literalRanges(string $text, string $query): array
    {
        $needle = $this->fold($query);
        if ($needle === '') {
            return [];
        }
        $at = mb_strpos($this->fold($text), $needle);
        if ($at === false) {
            return [];
        }
        $until = $at + mb_strlen($needle);
        $byte = $original = $normalized = 0;
        $start = null;
        // Map only the principal hit. ASCII runs avoid a per-character map for large legal texts.
        while ($byte < strlen($text)) {
            $ascii = preg_match('/\G[\x00-\x7F]+/u', $text, $match, 0, $byte) === 1;
            if (! $ascii) {
                preg_match('/\G\X/u', $text, $match, 0, $byte);
            }
            $cluster = $match[0];
            $length = $ascii ? strlen($cluster) : mb_strlen($cluster);
            $foldedLength = $ascii ? $length : mb_strlen($this->fold($cluster));
            if ($start === null && $at < $normalized + $foldedLength) {
                $start = $original + ($ascii ? $at - $normalized : 0);
            }
            if ($until <= $normalized + $foldedLength) {
                $end = $original + ($ascii ? $until - $normalized : $length);

                return [[$start, $end - $start]];
            }
            $byte += strlen($cluster);
            $original += $length;
            $normalized += $foldedLength;
        }

        return [];
    }

    public function excerpt(string $text, array $ranges): array
    {
        $start = $ranges ? max(0, min(array_column($ranges, 0)) - 60) : 0;
        $end = min(mb_strlen($text), $start + 300);
        $prefix = $start > 0 ? '…' : '';
        $visible = $prefix.mb_substr($text, $start, $end - $start).($end < mb_strlen($text) ? '…' : '');
        $local = [];
        foreach ($ranges as [$offset, $length]) {
            $left = max($offset, $start);
            $right = min($offset + $length, $end);
            if ($left < $right) {
                $local[] = [$left - $start + mb_strlen($prefix), $right - $left];
            }
        }

        return ['text' => $visible, 'excerpt_ranges' => $local];
    }

    public function fold(string $text): string
    {
        return str_replace(['ß', 'æ', 'œ'], ['ss', 'ae', 'oe'], preg_replace('/\p{M}/u', '', \Normalizer::normalize(mb_strtolower($text), \Normalizer::FORM_D)));
    }
}
