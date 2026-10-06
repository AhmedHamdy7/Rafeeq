<?php

namespace App\Domains\Notification\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Notification\Models\Message;
use App\Domains\Notification\Support\ChatWindow;
use App\Domains\Notification\Support\ContactInfoDetector;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * One message in a booking's trip chat (Chapter 11 §Trip Chat).
 *
 * The conversation is the booking's: exactly two people, that day's passenger and driver. It is
 * created on the first message rather than at confirmation — a recurring member has a booking
 * per day, and an empty conversation row for each would be thirty rows a month per member that
 * nobody wrote in.
 *
 * Refused, with the same `CHAT_NOT_OPEN`, when the window is closed AND when either person has
 * blocked the other. The second must not be distinguishable: telling somebody "you cannot
 * message her because she blocked you" is telling them she blocked them.
 *
 * 🔒 A phone number or an email is REFUSED, not delivered (product decision, 2026-10-06). A number
 * shared here moves the conversation off the platform — away from blocking, the safety desk and the
 * trip record — and that is exactly where a member who later needs protection cannot get it. It
 * used to be delivered and flagged; `contains_contact_info` stays on the row for messages from
 * that time.
 */
final readonly class SendChatMessageAction
{
    public function execute(User $sender, Booking $booking, string $body): Message
    {
        $recipientId = self::otherParty($booking, $sender);

        if (! ChatWindow::isOpen($booking) || BlockedUser::existsBetween($sender->id, $recipientId)) {
            throw self::notOpen($booking);
        }

        // After the window check: telling somebody their number is not allowed in a conversation
        // that is closed (or blocked) would answer a question they could not have asked.
        if (ContactInfoDetector::contains($body)) {
            throw DomainException::of(ErrorCode::ChatContactInfoNotAllowed, fields: [
                'body' => [__('errors.'.ErrorCode::ChatContactInfoNotAllowed->value)],
            ]);
        }

        return DB::transaction(function () use ($sender, $booking, $body, $recipientId): Message {
            // Locked so two first messages at once do not create two conversations.
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            $conversation = Conversation::query()->firstOrCreate(
                ['booking_id' => $booking->id],
                ['commute_group_id' => $booking->commute_group_id, 'opened_at' => now()],
            );

            $message = new Message;
            $message->fill([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'body' => $body,
            ]);
            // Always false from here on — a message that contains it is refused above.
            $message->contains_contact_info = false;
            $message->save();

            /*
             * Who wrote, never what. The body would appear on a lock screen — and "call me on
             * 0101…" on somebody's lock screen is the number shared with whoever is holding it.
             */
            Notifier::send(User::query()->findOrFail($recipientId), NotificationType::ChatMessage,
                ['name' => $sender->public_first_name],
                ['bookingId' => $booking->id],
            );

            return $message;
        });
    }

    /**
     * The other person on this booking. 404 for anybody who is neither — a conversation must not
     * be probeable for existence.
     */
    public static function otherParty(Booking $booking, User $caller): string
    {
        return match ($caller->id) {
            $booking->passenger_user_id => $booking->driver_profile_id,
            $booking->driver_profile_id => $booking->passenger_user_id,
            default => throw DomainException::of(ErrorCode::NotFound),
        };
    }

    public static function notOpen(Booking $booking): DomainException
    {
        return DomainException::of(ErrorCode::ChatNotOpen, fields: [
            'opensAt' => [ChatWindow::opensAt($booking)->toIso8601String()],
            'closesAt' => [ChatWindow::closesAt($booking)->toIso8601String()],
        ]);
    }
}
