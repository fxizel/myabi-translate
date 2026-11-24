<?php

namespace App\Services\BrowserExtension;

/** Recognize Chrome extension origins without registering individual installations. */
final class ExtensionOrigin
{
    public static function isAllowed(?string $origin): bool
    {
        if ($origin === null) {
            return false;
        }

        return preg_match('/\Achrome-extension:\/\/[a-p]{32}\z/', $origin) === 1;
    }
}
