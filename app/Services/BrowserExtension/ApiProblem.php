<?php

namespace App\Services\BrowserExtension;

use RuntimeException;

/** Only technical codes belong in exceptions on this API boundary. */
final class ApiProblem extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($errorCode);
    }
}
