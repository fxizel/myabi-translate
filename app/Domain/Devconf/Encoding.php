<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use InvalidArgumentException;

final class Encoding
{
    public static function decode(string $bytes): string
    {
        if (strpbrk($bytes, "\x81\x8D\x8F\x90\x9D") !== false) {
            throw new InvalidArgumentException('Le fichier contient des octets Windows-1252 invalides.');
        }
        $value = @iconv('Windows-1252', 'UTF-8', $bytes);
        if ($value === false) {
            throw new InvalidArgumentException('Le fichier contient des octets Windows-1252 invalides.');
        }

        return $value;
    }

    /** Conversion is deliberately strict: no //IGNORE, //TRANSLIT or substitution. */
    public static function encode(string $value): string
    {
        // Validate before iconv: undefined characters otherwise emit platform notices even
        // with error suppression. This is the exact Unicode repertoire of Windows-1252.
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[^\x{0000}-\x{007F}\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]/u', $value)) {
            throw new InvalidArgumentException('La valeur contient des caractères non exportables en Windows-1252.');
        }
        $bytes = @iconv('UTF-8', 'Windows-1252', $value);
        if ($bytes === false || self::decode($bytes) !== $value) {
            throw new InvalidArgumentException('La valeur contient des caractères non exportables en Windows-1252.');
        }

        return $bytes;
    }
}
