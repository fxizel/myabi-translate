<?php

namespace App\Services\BrowserExtension;

/** Recognize browser extension origins without registering individual installations. */
final class ExtensionOrigin
{
    public static function isAllowed(?string $origin): bool
    {
        if ($origin === null) {
            return false;
        }

        return preg_match('/\Achrome-extension:\/\/[a-p]{32}\z/', $origin) === 1
            || preg_match('/\Amoz-extension:\/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $origin) === 1;
    }
}
