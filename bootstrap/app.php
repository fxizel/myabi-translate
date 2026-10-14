<?php

use App\Http\Middleware\BrowserExtensionCors;
use App\Http\Middleware\BrowserExtensionTransport;
use App\Http\Middleware\EnsureSecureSession;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\BrowserExtension\ApiProblem;
use App\Services\BrowserExtension\ExtensionOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TrustProxies must run before HTTPS/IP checks. Skipped input transforms
        // below ensure the API body is still undecoded when transport runs.
        $middleware->append(BrowserExtensionTransport::class);
        $middleware->replace(HandleCors::class, BrowserExtensionCors::class);
        $isExtension = fn (Request $request) => $request->is('api/browser-extension', 'api/browser-extension/*');
        $middleware->trimStrings(except: [$isExtension]);
        $middleware->convertEmptyStringsToNull(except: [$isExtension]);
        $middleware->alias([
            'session.secure' => EnsureSecureSession::class,
            '2fa.required' => RequireTwoFactor::class,
            'role' => RequireRole::class,
        ]);
        $middleware->web(append: [SetLocale::class, SecurityHeaders::class]);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // SQL exceptions and request dumps can embed inspected ERP text or credentials.
        $exceptions->report(function (Throwable $error) {
            if (app()->bound('request') && request()->is('api/browser-extension', 'api/browser-extension/*')) {
                return false;
            }

            return null;
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/browser-extension', 'api/browser-extension/*')) {
                return null;
            }
            $status = $error instanceof ApiProblem ? $error->status
                : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
            $code = $error instanceof ApiProblem ? $error->errorCode : match ($status) {
                401 => 'unauthenticated', 403 => 'permission_denied', 404 => 'not_found',
                405 => 'method_not_allowed', 413 => 'payload_too_large',
                429 => 'rate_limited', 503 => 'search_index_unavailable', default => 'internal_error',
            };
            $requestId = $request->attributes->get('request_id') ?? (string) Str::uuid();
            $messages = [
                'unauthenticated' => 'Create a new personal token from your profile.',
                'permission_denied' => 'This token does not allow this operation.',
                'mfa_required' => 'Create a new token after completing two-factor authentication.',
                'feedback_not_allowed' => 'Feedback is not enabled for this token.',
                'not_found' => 'The requested resource is unavailable.',
                'method_not_allowed' => 'Use the documented request method.',
                'result_stale' => 'Run a new search before sending feedback.',
                'payload_too_large' => 'Reduce the request context.',
                'unsupported_media_type' => 'Send a JSON request.',
                'invalid_request' => 'Correct the indicated request fields.',
                'search_language_required' => 'Select the language of the inspected text.',
                'client_upgrade_required' => 'Update the browser extension.',
                'rate_limited' => 'Wait before trying again.',
                'search_index_unavailable' => 'The search index is unavailable. Try again later.',
                'internal_error' => 'The service is temporarily unavailable.',
                'service_unavailable' => 'The service is temporarily unavailable.',
                'response_too_large' => 'The result is too large to return safely.',
                'https_required' => 'Use an HTTPS API URL.',
                'origin_not_allowed' => 'This browser origin is not allowed.',
            ];
            $body = ['error' => ['code' => $code, 'message' => $messages[$code] ?? $messages['internal_error']], 'requestId' => $requestId];
            if ($error instanceof ApiProblem && $error->fields !== []) {
                $body['error']['fields'] = $error->fields;
            }
            $headers = $error instanceof ApiProblem ? $error->headers : [];
            if ($error instanceof HttpExceptionInterface && isset($error->getHeaders()['Retry-After'])) {
                $headers['Retry-After'] = $error->getHeaders()['Retry-After'];
            }
            $origin = $request->header('Origin');
            if (ExtensionOrigin::isAllowed($origin)) {
                $headers['Access-Control-Allow-Origin'] = $origin;
                $headers['Access-Control-Expose-Headers'] = 'Retry-After, X-Request-ID';
                $headers['Vary'] = 'Origin';
            }

            return response()->json($body, $status, array_merge($headers, ['Cache-Control' => 'no-store', 'X-Request-ID' => $requestId]));
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
