<?php

namespace App\Http\Middleware;

use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\RateLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

class BrowserExtensionTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/browser-extension', 'api/browser-extension/*')) {
            return $next($request);
        }
        $request->attributes->set('request_id', (string) Str::uuid());
        if (! config('browser-extension.enabled')) {
            throw new ApiProblem(503, 'service_unavailable');
        }
        if (config('browser-extension.force_https') && ! $request->isSecure()) {
            throw new ApiProblem(400, 'https_required');
        }
        $origin = $request->header('Origin');
        $allowed = config('browser-extension.allowed_origins', []);
        if ($origin && ! in_array($origin, $allowed, true)) {
            throw new ApiProblem(403, 'origin_not_allowed');
        }
        $retry = app(RateLimits::class)->consume([
            'extension:ip:'.hash('sha256', (string) $request->ip()) => config('browser-extension.limits.ip'),
        ], 'ip:'.$request->ip());
        if ($retry) {
            throw new ApiProblem(429, 'rate_limited', [], ['Retry-After' => (string) $retry]);
        }
        if ($request->isMethod('OPTIONS')) {
            if (! $origin || $request->header('Access-Control-Request-Method') !== 'POST') {
                throw new ApiProblem(403, 'origin_not_allowed');
            }
            $headers = array_filter(array_map('trim', explode(',', strtolower((string) $request->header('Access-Control-Request-Headers')))));
            if (array_diff($headers, ['authorization', 'content-type', 'accept'])) {
                throw new ApiProblem(403, 'origin_not_allowed');
            }
            $response = response('', 204);
        } else {
            if (! $request->isMethod('POST')) {
                return $this->headers($request, $next($request), $origin);
            }
            $body = $request->getContent();
            if (strlen($body) > config('browser-extension.max_request_bytes')) {
                throw new ApiProblem(413, 'payload_too_large');
            }
            if (! $request->isJson()) {
                throw new ApiProblem(415, 'unsupported_media_type');
            }
            try {
                $data = json_decode($body, true, 5, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiProblem(422, 'invalid_request');
            }
            if (! is_array($data) || ! str_starts_with(ltrim($body), '{')) {
                throw new ApiProblem(422, 'invalid_request');
            }
            $request->setJson(new InputBag($data));
            $response = $next($request);
        }
        if (strlen((string) $response->getContent()) > config('browser-extension.max_response_bytes')) {
            throw new ApiProblem(503, 'response_too_large');
        }

        return $this->headers($request, $response, $origin);
    }

    private function headers(Request $request, Response $response, ?string $origin): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
            $response->headers->set('Access-Control-Expose-Headers', 'Retry-After, X-Request-ID');
        }

        return $response;
    }
}
