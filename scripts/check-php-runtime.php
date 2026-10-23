<?php

use App\Support\PhpRuntimePolicy;

// Deliberately independent of Composer/Laravel, the database and application secrets.
require_once dirname(__DIR__).'/app/Support/PhpRuntimePolicy.php';

if (! PhpRuntimePolicy::supports(PHP_VERSION)) {
    fwrite(STDERR, "Unsupported PHP runtime: PHP 8.4.27+ (8.4 branch) or 8.5.12+ (8.5 branch) required for CVE-2026-109618 / CVE-2026-109619.\n");
    exit(1);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    printf("PHP %s (%s): runtime version accepted. Verify FPM separately and restart all workers after upgrading.\n", PHP_VERSION, PHP_SAPI);
}
