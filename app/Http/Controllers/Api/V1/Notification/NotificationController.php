<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Domains\Notification\Actions\UpdateNotificationPreferencesAction;
use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\NotificationPreferences;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Notification\MarkNotificationsReadRequest;
use App\Http\Requests\Notification\UpdateNotificationPreferencesRequest;
use App\Http\Resources\NotificationResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's notification inbox and switches (Chapter 11, screen 22).
 *
 * In the `signed-in` tier, not `active`, deliberately: a member whose account was just put on
 * hold must still be able to read the notice that says so.
 */
final class NotificationController extends Controller
{
    /**
     * GET /v1/notifications — the inbox, newest first, with the unread count in `meta`.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $this->inbox($request)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated(
            $page,
            NotificationResource::collection($page->items()),
            ['unreadCount' => $this->unreadCount($request)],
        );
    }

    /**
     * PATCH /v1/notifications/read — mark some, or all, as read. Returns the new unread count.
     */
    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $this->inbox($request)
            ->whereNull('read_at')
            ->when(! $request->boolean('all'), fn (Builder $query) => $query->whereIn('id', $request->validated('ids', [])))
            ->update(['read_at' => now()]);

        return ApiResponse::success(['unreadCount' => $this->unreadCount($request)]);
    }

    /**
     * GET /v1/notifications/preferences — every switch, with whether it can be turned off.
     */
    public function preferences(Request $request): JsonResponse
    {
        $switches = [];

        foreach (NotificationCategory::cases() as $category) {
            foreach (NotificationPreferences::CHANNELS as $channel) {
                $switches[] = [
                    'category' => $category->value,
                    'channel' => $channel->value,
                    'enabled' => NotificationPreferences::allows($request->user(), $category, $channel),
                    // Safety notices cannot be switched off — show the switch on and locked.
                    'canDisable' => $category !== NotificationCategory::Safety,
                ];
            }
        }

        return ApiResponse::success($switches);
    }

    /**
     * PATCH /v1/notifications/preferences — change some switches.
     */
    #[ApiErrors(ErrorCode::NotificationCategoryLocked)]
    public function updatePreferences(UpdateNotificationPreferencesRequest $request, UpdateNotificationPreferencesAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->validated('preferences'));

        return $this->preferences($request);
    }

    /**
     * @return Builder<Notification>
     */
    private function inbox(Request $request): Builder
    {
        // In-app rows only: push rows are the delivery log, one per phone, not inbox items.
        return Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('channel', NotificationChannel::InApp->value);
    }

    private function unreadCount(Request $request): int
    {
        return $this->inbox($request)->whereNull('read_at')->count();
    }
}
