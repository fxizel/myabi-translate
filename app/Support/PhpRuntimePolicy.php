<?php

namespace App\Support;

final class PhpRuntimePolicy
{
    public static function supports(string $version): bool
    {
        // Keep aligned with composer.json. Unknown branches need explicit review.
        return (str_starts_with($version, '8.4.') && version_compare($version, '8.4.27', '>='))
            || (str_starts_with($version, '8.5.') && version_compare($version, '8.5.12', '>='));
    }
}
