<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Admin\Models\ContentPage;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\ContentPageResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The texts staff write on the dashboard's Content page: help, how to reach support, terms
 * and privacy, community guidelines. Both languages come back; the app shows the one it is in.
 *
 * Signed-in tier, not active: a member whose account is on hold must still be able to read
 * the guidelines and how to reach support.
 */
final class ContentController extends Controller
{
    /**
     * GET /v1/content/{key} — one text. `key` is one of help, support_contact, terms, guidelines.
     * 404 until staff have written it.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function show(string $key): JsonResponse
    {
        $page = in_array($key, ContentPage::KEYS, true) ? ContentPage::query()->find($key) : null;

        if ($page === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return ApiResponse::success(new ContentPageResource($page));
    }
}
