<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Domains\Booking\Models\Booking;
use App\Domains\Notification\Actions\ReportChatMessageAction;
use App\Domains\Notification\Actions\SendChatMessageAction;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Notification\Models\Message;
use App\Domains\Notification\Support\ChatWindow;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Notification\ReportChatMessageRequest;
use App\Http\Requests\Notification\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip chat: one conversation per booking, between that day's passenger and driver
 * (Chapter 11). The designed entry point is the "Message" button on the driver's wait screen.
 *
 * 🔒 404 for anybody who is not one of the two, and the same for a booking id that does not
 * exist — a conversation must not be probeable.
 */
final class ChatController extends Controller
{
    /**
     * GET /v1/bookings/{booking}/messages — the conversation, newest first.
     *
     * Reading it marks the other person's messages as read: opening the conversation IS reading
     * it, and a separate call would be one more thing for a client to forget.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function index(Request $request, string $booking): JsonResponse
    {
        $booking = $this->participatingIn($request, $booking);
        $conversation = Conversation::query()->where('booking_id', $booking->id)->first();

        $page = Message::query()
            ->where('conversation_id', $conversation?->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        if ($conversation !== null) {
            Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_user_id', '!=', $request->user()->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $otherId = SendChatMessageAction::otherParty($booking, $request->user());

        return ApiResponse::paginated($page, ChatMessageResource::collection($page->items()), [
            // Whether a message can be sent now. False before the window, after it, and — without
            // saying so — when either of the two has blocked the other.
            'canSend' => ChatWindow::isOpen($booking) && ! BlockedUser::existsBetween($request->user()->id, $otherId),
            'opensAt' => ChatWindow::opensAt($booking)->toIso8601String(),
            'closesAt' => ChatWindow::closesAt($booking)->toIso8601String(),
        ]);
    }

    /**
     * POST /v1/bookings/{booking}/messages — send one.
     */
    #[ApiErrors(ErrorCode::ChatNotOpen, ErrorCode::NotFound)]
    public function store(SendChatMessageRequest $request, string $booking, SendChatMessageAction $action): JsonResponse
    {
        $message = $action->execute($request->user(), $this->participatingIn($request, $booking), $request->validated('body'));

        return ApiResponse::success(new ChatMessageResource($message), status: 201);
    }

    /**
     * POST /v1/messages/{message}/report — report a message you received. Files a report with
     * the safety team, quoting the message; returns its id so the app can show it in the
     * member's reports.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function report(ReportChatMessageRequest $request, string $message, ReportChatMessageAction $action): JsonResponse
    {
        $found = Message::query()->with('conversation.booking.scheduledTrip')->whereKey($message)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $incident = $action->execute($request->user(), $found, $request->validated('reason'));

        return ApiResponse::success(['incidentId' => $incident->id], status: 201);
    }

    private function participatingIn(Request $request, string $bookingId): Booking
    {
        $userId = $request->user()->id;

        return Booking::query()
            ->whereKey($bookingId)
            ->where(fn ($query) => $query->where('passenger_user_id', $userId)->orWhere('driver_profile_id', $userId))
            ->with('scheduledTrip.tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
