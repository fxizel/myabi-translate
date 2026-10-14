<?php

namespace App\Services\BrowserExtension;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class RateLimits
{
    /** All buckets are consumed together while holding the shared subject lock. */
    public function consume(array $limits, string $subject): int
    {
        $cache = Cache::store(config('browser-extension.rate_limit_store'));
        $limiter = new RateLimiter($cache);

        return $cache->lock('extension:quota-lock:'.hash('sha256', $subject), 10)->block(5, function () use ($limits, $limiter) {
            $retry = 0;
            foreach ($limits as $key => $maximum) {
                if ($limiter->tooManyAttempts($key, $maximum)) {
                    $retry = max($retry, $limiter->availableIn($key));
                }
            }
            if ($retry > 0) {
                return $retry;
            }
            foreach ($limits as $key => $maximum) {
                $limiter->hit($key, 60);
            }

            return 0;
        });
    }
}
