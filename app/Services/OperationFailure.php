<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Technical exceptions can embed SQL bindings: log only explicitly safe metadata. */
final class OperationFailure
{
    public static function capture(Throwable $exception, Model $operation, string $code = 'ui.operation_failed'): string
    {
        $reference = (string) Str::uuid();
        $sqlstate = null;
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException) {
                $candidate = (string) ($cause->errorInfo[0] ?? $cause->getCode());
                if (preg_match('/\A[A-Z0-9]{5}\z/', $candidate)) {
                    $sqlstate = $candidate;
                    break;
                }
            }
        }
        Log::error('operation.failed', [
            'code' => $code, 'reference' => $reference, 'entity' => $operation::class,
            'id' => $operation->getKey(), 'exception_class' => $exception::class, 'sqlstate' => $sqlstate,
        ]);

        return json_encode(['myabi_error' => $code, 'reference' => $reference], JSON_THROW_ON_ERROR);
    }
}
