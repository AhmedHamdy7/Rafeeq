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
 */
final readonly class ReportChatMessageAction
{
    public function __construct(private ReportIncidentAction $reportIncident) {}

    public function execute(User $reporter, Message $message, string $reason): Incident
    {
        $booking = $message->conversation->booking;

        if ($booking === null
            || $message->sender_user_id === $reporter->id
            || SendChatMessageAction::otherParty($booking, $reporter) !== $message->sender_user_id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return DB::transaction(function () use ($reporter, $message, $booking, $reason): Incident {
            $message->forceFill(['flagged_reason' => mb_substr($reason, 0, 255)])->save();

            return $this->reportIncident->execute($reporter, [
                'category' => 'harassment',
                'bookingId' => $booking->id,
                'description' => __('notifications.chat_report', [
                    'reason' => $reason,
                    'message' => $message->body,
                ], $reporter->preferred_language ?: config('app.locale')),
            ]);
        });
    }
}
