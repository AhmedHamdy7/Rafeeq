<?php

namespace App\Http\Middleware;

use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Shared\Testing\TestingFaults;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InjectTestingFaults
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TestAccounts::apiEnabled()) {
            return $next($request);
        }

        $rule = TestingFaults::claim($request);

        if ($rule === null) {
            return $next($request);
        }

        if ($rule->mode === 'delay') {
            usleep(((int) $rule->delay_ms) * 1000);

            return $next($request);
        }

        $status = (int) $rule->status;
        $code = $rule->code !== null ? (ErrorCode::tryFrom($rule->code) ?? null) : null;
        $fallback = ErrorCode::fromHttpStatus($status);
        $headers = ['X-Testing-Fault' => (string) $rule->id];

        if ($status === 429) {
            $headers['Retry-After'] = (string) max(1, (int) ($rule->retry_after_seconds ?? 30));
        }

        return response()->json([
            'success' => false,
            'error' => [
                'code' => $rule->code ?? $fallback->value,
                'message' => ($code ?? $fallback)->message(),
                'fields' => TestingFaults::fieldsOf($rule),
            ],
        ], $status, $headers);
    }
}
