<?php

namespace App\Domains\Notification\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Events\ChatTyping;
use App\Domains\Notification\Support\ChatSettings;
use App\Domains\Notification\Support\ChatWindow;
use App\Domains\Safety\Models\BlockedUser;
use Illuminate\Support\Facades\RateLimiter;

final readonly class SendChatTypingAction
{
    public function onBooking(User $sender, Booking $booking): void
    {
        $recipientId = SendChatMessageAction::otherParty($booking, $sender);

        if (! ChatWindow::isOpen($booking) || BlockedUser::existsBetween($sender->id, $recipientId)) {
            throw SendChatMessageAction::notOpen($booking);
        }

        $this->announce($sender, $recipientId, $booking->id, null);
    }

    public function onSeatRequest(User $sender, SeatRequest $request): void
    {
        $recipientId = SendChatMessageAction::otherPartyOfRequest($request, $sender);

        if (! ChatWindow::isOpenForRequest($request) || BlockedUser::existsBetween($sender->id, $recipientId)) {
            throw SendChatMessageAction::notOpenForRequest($request);
        }

        $this->announce($sender, $recipientId, null, $request->id);
    }

    private function announce(User $sender, string $recipientId, ?string $bookingId, ?string $seatRequestId): void
    {
        $key = "chat-typing:{$sender->id}:".($bookingId ?? $seatRequestId);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        RateLimiter::hit($key, ChatSettings::typingIntervalSeconds());

        ChatTyping::dispatch($recipientId, $bookingId, $seatRequestId);
    }
}
