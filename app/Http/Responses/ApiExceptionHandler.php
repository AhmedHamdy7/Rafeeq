<?php

namespace App\Http\Responses;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Keeps bootstrap/app.php thin: every API error renders through the
 * {success, data} / {success, error: {code, message, fields}} envelope
 * documented in the Engineering Bible, §3.8.
 *
 * Note there is deliberately no AuthorizationException callback: Laravel's
 * prepareException() rewrites it to an AccessDeniedHttpException *before*
 * render callbacks run, so it arrives at the HttpExceptionInterface handler
 * below as a 403 and maps to FORBIDDEN there.
 */
final class ApiExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(ErrorCode::ValidationFailed, fields: $e->errors());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(ErrorCode::Unauthenticated);
        });

        // Registered before the catch-all: render callbacks are evaluated in
        // registration order, and the first matching type wins.
        $exceptions->render(function (DomainException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                $e->errorCode,
                // The catalogue message is the default; an Action only passes
                // its own string when the case genuinely needs one.
                message: $e->getMessage() === $e->errorCode->value ? null : $e->getMessage(),
                fields: $e->fields,
                headers: $e->headers,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            return ApiResponse::error(
                ErrorCode::fromHttpStatus($status),
                status: $status,
                // Without these a 429 loses Retry-After, a 405 loses Allow,
                // and a 401 HttpException loses WWW-Authenticate.
                headers: $e->getHeaders(),
            );
        });

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            // HttpResponseException carries a response the framework is about
            // to return verbatim (abort($response), Precognition, and any
            // other short-circuit). It is a plain RuntimeException, and render
            // callbacks run before the framework unwraps it — so catching it
            // here would turn a deliberate response into a generic 500.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::ServerError,
                // Only with APP_DEBUG on. Keyed on the environment name, a server run as `staging`
                // sent internal exception text ("Latitude out of range: 1232.00015") to clients
                // the guide tells to show `error.message` to the person as written.
                message: config('app.debug') ? $e->getMessage() : null,
                status: 500,
            );
        });
    }
}
