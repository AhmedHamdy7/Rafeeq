<?php

namespace App\Http\Responses;

use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * A page of a list, in the same envelope as everything else.
     *
     * The items go in `data` exactly as an unpaginated list would, and the paging
     * numbers go in `meta` — the key the envelope already reserves for them. That shape
     * is deliberate: a client reading `data` keeps working whether or not an endpoint
     * grew paging, and `meta` is where it looks to discover there is more.
     *
     * Laravel's own paginator JSON is not used, because it would put `data`, `links` and
     * `meta` at the top level and produce a second response shape for the mobile team to
     * special-case — see the contract's own note that `meta` is optional and never a
     * required null.
     *
     * `total` and `lastPage` are included, which costs a COUNT query. That is worth it
     * here: the driver's inbox and the review queue both need to say how many are
     * waiting, and a client that cannot show "page 2 of 9" ends up fetching pages until
     * one comes back short.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    public static function paginated(LengthAwarePaginator $page, mixed $data = null): JsonResponse
    {
        return self::success($data ?? $page->items(), meta: [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
            // Stated rather than left for the client to work out from three other
            // numbers, which is the sort of arithmetic every client gets wrong once.
            'hasMore' => $page->hasMorePages(),
        ]);
    }

    /**
     * How many rows one page holds, from the request, bounded.
     *
     * 🔒 `perPage` arrives from the caller, so the ceiling is the part that matters: an
     * unbounded value turns a list endpoint into a way to ask the database for
     * everything, which is both a slow query and a way to pull a whole table through one
     * request.
     */
    public static function perPage(Request $request): int
    {
        $requested = (int) $request->integer('perPage', (int) config('rafeeq.api.default_per_page'));

        return max(1, min($requested, (int) config('rafeeq.api.max_per_page')));
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
