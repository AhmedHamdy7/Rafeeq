<?php

namespace App\Domains\Notification\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
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
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One message in a chat (Chapter 11 §Trip Chat, extended by Feature 03 v5).
 *
 * Two contexts, both of which are exactly two people:
 *
 *   - **a booking** — that day's passenger and driver. The conversation is created on the first
 *     message rather than at confirmation: a recurring member has a booking per day, and an empty
 *     conversation row for each would be thirty rows a month per member nobody wrote in.
 *   - **a seat request** — the woman asking and the driver deciding, *before* any seat exists
 *     (v5: *"chat scoped to request / booking / waiting-list"*). Without it the one exchange that
 *     decides a booking is out of reach: a driver cannot ask "could you be at the second gate?"
 *     and a passenger cannot ask anything at all about the car she is about to get into.
 *
 * 🔴 **One set of protections, two entry points, and that shape is the point.** A second Action for
 * the request thread would eventually drift, and the half that drifted would be the half that
 * delivers a phone number or a message from somebody who has been blocked. So both paths meet in
 * {@see deliver()}, and everything that guards a conversation lives there.
 *
 * Refused, with the same `CHAT_NOT_OPEN`, when the window is closed AND when either person has
 * blocked the other. The second must not be distinguishable: telling somebody "you cannot message
 * her because she blocked you" is telling them she blocked them.
 *
 * 🔒 A phone number or an email is REFUSED, not delivered (product decision, 2026-10-06). A number
 * shared here moves the conversation off the platform — away from blocking, the safety desk and the
 * trip record — and that is exactly where a member who later needs protection cannot get it. It
 * used to be delivered and flagged; `contains_contact_info` stays on the row for messages from
 * that time.
 *
 * ⚠️ **A request thread is not consent.** v5 §2: "chat is not consent". Two people agreeing a
 * street corner in messages changes nothing — the meeting point moves only through a counteroffer,
 * which is structured, recorded, and what a dispute is settled against.
 */
final readonly class SendChatMessageAction
{
    /**
     * A message on a confirmed booking.
     */
    public function onBooking(User $sender, Booking $booking, string $body): Message
    {
        $recipientId = self::otherParty($booking, $sender);

        if (! ChatWindow::isOpen($booking)) {
            throw self::notOpen($booking);
        }

        return $this->deliver(
            $sender,
            $recipientId,
            $body,
            ['booking_id' => $booking->id],
            ['commute_group_id' => $booking->commute_group_id],
            ['bookingId' => $booking->id],
            $booking,
            fn (): DomainException => self::notOpen($booking),
        );
    }

    /**
     * A message on a seat request nobody has answered yet.
     *
     * 🔒 Open only while the request is LIVE — see {@see ChatWindow::isOpenForRequest()}. A rejected
     * or withdrawn request closes its thread, because a driver who said no has said everything she
     * owes, and a channel that outlived her refusal would be a way to keep talking to somebody who
     * declined to carry you.
     */
    public function onSeatRequest(User $sender, SeatRequest $request, string $body): Message
    {
        $recipientId = self::otherPartyOfRequest($request, $sender);

        if (! ChatWindow::isOpenForRequest($request)) {
            throw self::notOpenForRequest($request);
        }

        return $this->deliver(
            $sender,
            $recipientId,
            $body,
            ['seat_request_id' => $request->id],
            [],
            ['seatRequestId' => $request->id, 'commuteId' => $request->commute_offer_id],
            $request,
            fn (): DomainException => self::notOpenForRequest($request),
        );
    }

    /**
     * Everything that is true of every message, whatever it is attached to.
     *
     * @param  array<string, mixed>  $context  the one column that identifies this thread
     * @param  array<string, mixed>  $extra  anything else to set when the thread is first created
     * @param  array<string, mixed>  $data  the deep link for the recipient's notification
     * @param  Model  $lock  the row locked while the thread is created, so two first
     *                       messages at once cannot make two conversations
     * @param  Closure(): DomainException  $refusal  the context's own "not open" answer
     */
    private function deliver(
        User $sender,
        string $recipientId,
        string $body,
        array $context,
        array $extra,
        array $data,
        Model $lock,
        Closure $refusal,
    ): Message {
        /*
         * 🔒 Blocked in either direction, answered with the caller's OWN `CHAT_NOT_OPEN` — byte for
         * byte what a closed window answers, which is why the refusal is passed in rather than
         * raised here. A bare `ChatNotOpen` beside a detailed one would make the two
         * distinguishable, and "you cannot message her because she blocked you" is telling
         * somebody she blocked them.
         */
        if (BlockedUser::existsBetween($sender->id, $recipientId)) {
            throw $refusal();
        }

        // After the window and block checks: telling somebody their number is not allowed in a
        // conversation that is closed would answer a question they could not have asked.
        if (ContactInfoDetector::contains($body)) {
            throw DomainException::of(ErrorCode::ChatContactInfoNotAllowed, fields: [
                'body' => [__('errors.'.ErrorCode::ChatContactInfoNotAllowed->value)],
            ]);
        }

        return DB::transaction(function () use (
            $sender, $recipientId, $body, $context, $extra, $data, $lock
        ): Message {
            // Locked so two first messages at once do not create two conversations.
            $lock->newQuery()->whereKey($lock->getKey())->lockForUpdate()->first();

            $conversation = Conversation::query()->firstOrCreate(
                $context,
                $extra + ['opened_at' => now()],
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
            Notifier::send(
                User::query()->findOrFail($recipientId),
                NotificationType::ChatMessage,
                ['name' => $sender->public_first_name],
                $data,
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

    /**
     * The other person on this seat request: the passenger who asked, or the driver she asked.
     *
     * 🔒 The driver is found through the commute rather than taken from the request, which carries
     * no driver column — and `CommuteOffer::driver_profile_id` is the authority on whose commute it
     * is everywhere else, so it is the authority here too.
     */
    public static function otherPartyOfRequest(SeatRequest $request, User $caller): string
    {
        $driverId = (string) CommuteOffer::query()
            ->whereKey($request->commute_offer_id)
            ->value('driver_profile_id');

        return match ($caller->id) {
            $request->passenger_user_id => $driverId,
            $driverId => $request->passenger_user_id,
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

    /**
     * Why a request's thread is shut: its status, which is the only reason it ever is.
     *
     * The status rather than a pair of timestamps, because a request thread has no window — it is
     * open for exactly as long as the request is a live question. A client showing "the driver has
     * answered" is showing the truth; a client showing a closing time would be inventing one.
     */
    public static function notOpenForRequest(SeatRequest $request): DomainException
    {
        return DomainException::of(ErrorCode::ChatNotOpen, fields: [
            'status' => [$request->status->value],
        ]);
    }
}
