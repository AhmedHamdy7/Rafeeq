<?php

namespace App\Domains\Notification\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Message;
use App\Domains\Safety\Actions\ReportIncidentAction;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * "Abuse can be reported" (Chapter 11 §Messaging Rules).
 *
 * Reporting a message files an ordinary report through the existing incident path, so it lands
 * on the safety desk with an SLA, an owner and an answer — rather than a flag on a row that no
 * screen reads. The message is quoted in the report: the desk needs to see what was said, and
 * the reporter has already seen it.
 *
 * Only the RECIPIENT may report a message. Reporting your own is meaningless, and anybody else
 * is not in the conversation.
 *
 * 🔒 **Reportable on a seat request's thread as well as a booking's** (Feature 03 v5), and that is
 * not an afterthought: a request thread is the one place two people talk with no relationship
 * approved between them, so it is where an unwelcome message is most likely and where a woman has
 * least reason to expect protection. This used to require a booking and would have refused her.
 *
 * The incident is filed with the booking when there is one, and without one otherwise — `incidents`
 * takes a nullable booking, so the report still reaches the desk with the message quoted in it.
 */
final readonly class ReportChatMessageAction
{
    public function __construct(private ReportIncidentAction $reportIncident) {}

    public function execute(User $reporter, Message $message, string $reason): Incident
    {
        $conversation = $message->conversation;
        $booking = $conversation->booking;
        $seatRequest = $conversation->seatRequest;

        /*
         * The other party, from whichever context this thread has. Both lookups throw a 404 for
         * somebody who is in neither role, which is also the answer for a conversation that is
         * none of the reporter's business — a message must not be probeable for existence.
         */
        $otherParty = match (true) {
            $booking !== null => SendChatMessageAction::otherParty($booking, $reporter),
            $seatRequest !== null => SendChatMessageAction::otherPartyOfRequest($seatRequest, $reporter),
            default => throw DomainException::of(ErrorCode::NotFound),
        };

        // Reporting your own message is meaningless; reporting one from somebody who is not the
        // other party means this is not your conversation.
        if ($message->sender_user_id === $reporter->id || $otherParty !== $message->sender_user_id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return DB::transaction(function () use ($reporter, $message, $booking, $reason): Incident {
            $message->forceFill(['flagged_reason' => mb_substr($reason, 0, 255)])->save();

            return $this->reportIncident->execute($reporter, [
                'category' => 'harassment',
                // Null on a request thread: there is no booking yet, and the desk gets the quoted
                // message either way.
                'bookingId' => $booking?->id,
                'description' => __('notifications.chat_report', [
                    'reason' => $reason,
                    'message' => $message->body,
                ], $reporter->preferred_language ?: config('app.locale')),
            ]);
        });
    }
}
