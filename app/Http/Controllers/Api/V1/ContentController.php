<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Admin\Models\ContentPage;
use App\Domains\Admin\Support\SettingsCatalogue;
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

        if ($page === null || (trim((string) $page->body_en) === '' && trim((string) $page->body_ar) === '')) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return ApiResponse::success(new ContentPageResource($page));
    }

    public function supportContact(): JsonResponse
    {
        return ApiResponse::success([
            'email' => self::support('email'),
            'workingHours' => self::localised('working_hours'),
            'responseTime' => self::localised('response_time'),
            'chat' => null,
        ]);
    }

    private static function localised(string $name): ?array
    {
        $en = self::support("{$name}_en");
        $ar = self::support("{$name}_ar");

        return $en === null && $ar === null ? null : ['en' => $en, 'ar' => $ar];
    }

    private static function support(string $name): ?string
    {
        $value = SettingsCatalogue::text("support.{$name}") ?? config("rafeeq.support.{$name}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
