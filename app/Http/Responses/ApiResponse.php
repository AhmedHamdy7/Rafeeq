<?php

namespace App\Http\Responses;

use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, string>  $headers  e.g. Retry-After on a 429, Allow on a 405
     */
    public static function error(
        ErrorCode $code,
        ?string $message = null,
        ?array $fields = null,
        ?int $status = null,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->message(),
                'fields' => $fields,
            ],
        ], $status ?? $code->defaultStatus(), $headers);
    }
}
