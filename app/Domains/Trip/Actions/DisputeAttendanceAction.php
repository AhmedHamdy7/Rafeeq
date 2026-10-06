<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Support\TripSettings;
use Illuminate\Support\Facades\DB;

/**
 * "That's not right" — the passenger's button on the trip receipt.
 *
 * 🔴 This is the safeguard that makes decision D18 acceptable, not a feature beside it.
 * D18 lets the driver decide whether a passenger travelled; the Master Plan (§15.6)
 * attaches four conditions to that power, and the first is a 24-hour window in which the
 * passenger can say it is wrong. Without this class, D18 is an unchecked charge.
 *
 * What a dispute does and does not do:
 *
 * - It does NOT change the attendance status. A passenger cannot mark themselves absent
 *   any more than a driver can mark them present twice — that would just move the same
 *   unchecked power to the other side. It records that the record is contested.
 * - It stops the money. Collection is gated on an undisputed record (Phase 8), so raising
 *   one holds the charge rather than reversing it after the fact.
 * - It opens a case for somebody who is neither party. Support reads the GPS trail,
 *   which is exactly what that trail is kept for, and the resolution is written by a
 *   reviewer with their id against it.
 */
final readonly class DisputeAttendanceAction
{
    public function execute(Booking $booking, string $reason): Attendance
    {
        $attendance = Attendance::query()->whereKey($booking->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        /*
         * Nothing to dispute on a row nobody decided. A `pending` row already means "not
         * recorded", so there is no claim being made about this person to contest — and
         * allowing it would let somebody open a case about a trip that nobody said they
         * were on.
         */
        if ($attendance->status->isTerminal() === false) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'currentStatus' => [strtoupper($attendance->status->value)],
            ]);
        }

        $this->assertWithinWindow($attendance);

        if ($attendance->disputed_at !== null) {
            /*
             * Already raised. Answered as a conflict rather than silently accepted,
             * because a second reason arriving after a reviewer has started reading the
             * first would quietly replace the thing they are deciding about.
             */
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'dispute' => ['ALREADY_RAISED'],
            ]);
        }

        return DB::transaction(function () use ($attendance, $booking, $reason): Attendance {
            $attendance->forceFill([
                'disputed_at' => now(),
                // In the passenger's own words. A code would make a reviewer guess what
                // happened from a category, and "I couldn't get to the car" and "she
                // never came" are the same category and opposite cases.
                'dispute_reason' => $reason,
            ])->save();

            /*
             * Collection stops here (Master Plan §15.6). If the two-hour delay has already passed
             * and the cash was recorded, the booking still says so — staff decide what the money
             * was for, and the dispute must be visible on it until they do.
             */
            $booking->forceFill(['payment_status' => PaymentStatus::Disputed->value])->save();

            BookingEventLog::record(
                $booking,
                BookingEventType::Disputed,
                BookingActorType::Passenger,
                $booking->passenger_user_id,
                metadata: ['attendance_status' => $attendance->status->value],
            );

            return $attendance;
        });
    }

    /**
     * 🔒 Measured from when the DRIVER decided, not from when the trip ended.
     *
     * The window exists so a passenger can react to a decision about them, and the clock
     * on that has to start when the decision was made. Timed from completion, a driver who
     * marks somebody absent a day later would hand them a window that had already closed —
     * which turns the safeguard into paperwork.
     */
    private function assertWithinWindow(Attendance $attendance): void
    {
        $decidedAt = $attendance->confirmed_at ?? $attendance->updated_at;

        $closesAt = $decidedAt->copy()->addHours(TripSettings::disputeWindowHours());

        if (now()->greaterThan($closesAt)) {
            throw DomainException::of(ErrorCode::AttendanceDisputeWindowClosed, fields: [
                'closedAt' => [$closesAt->toIso8601String()],
            ]);
        }
    }
}
