<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a booking, from either side (Chapter 6's cancellation section).
 *
 * The seat is released under the same lock that takes it. Decrementing without
 * the lock is pitfall #2 in reverse: two cancellations racing would each read the
 * old count and write back one less, losing a seat that should have been freed
 * twice — and the seat would stay sold to nobody.
 *
 * `decrement()` is used for the same reason `increment()` is on the way in: one
 * atomic statement rather than a read and a write with a gap.
 *
 * 🔒 Money is NOT touched. Chapter 6 says nothing about fees and MASTER_PLAN §19
 * lists the cancellation policy as open question #7, deferred to Phase 8 — the
 * chapter itself is called "very general" on it there. So a cancellation releases
 * the seat and records what happened, and charges nothing. Inventing a fee would
 * take money from a real person on the strength of an assumption.
 */
final readonly class CancelBookingAction
{
    public function __construct(private PromoteFromWaitlistAction $promote) {}

    public function byPassenger(Booking $booking, ?string $reason = null): Booking
    {
        return $this->cancel(
            $booking,
            BookingStatus::CancelledByPassenger,
            BookingActorType::Passenger,
            $booking->passenger_user_id,
            $reason,
        );
    }

    /**
     * A driver cancelling one person's seat. Cancelling the whole trip is a
     * different operation — it belongs with the trip lifecycle, and every
     * passenger on it has to be told.
     */
    public function byDriver(Booking $booking, string $driverUserId, ?string $reason = null): Booking
    {
        return $this->cancel(
            $booking,
            BookingStatus::CancelledByDriver,
            BookingActorType::Driver,
            $driverUserId,
            $reason,
        );
    }

    private function cancel(
        Booking $booking,
        BookingStatus $to,
        BookingActorType $actorType,
        string $actorId,
        ?string $reason,
    ): Booking {
        // The state machine decides, not this method: a completed or already
        // cancelled booking has no transition to cancelled, and asking the enum
        // keeps that rule in one place.
        if (! $booking->status->canTransitionTo($to)) {
            throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                'status' => [strtoupper($booking->status->value)],
            ]);
        }

        return DB::transaction(function () use ($booking, $to, $actorType, $actorId, $reason): Booking {
            $from = $booking->status;

            // Locked for the same reason as taking a seat: the count must not be
            // read by anyone else between this read and the write.
            $trip = ScheduledTrip::query()->lockForUpdate()->findOrFail($booking->scheduled_trip_id);

            $booking->forceFill([
                'status' => $to->value,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
                // Zero until the policy exists. See the class note.
                'cancellation_fee_piastres' => (int) config('rafeeq.booking.cancellation_fee_piastres'),
            ])->save();

            // Never below zero, however the data got there: a negative seat count
            // would make the trip look bookable past its capacity.
            $release = min($booking->seats_reserved, $trip->seats_taken);

            if ($release > 0) {
                $trip->decrement('seats_taken', $release);

                /*
                 * The seat that just came free reaches the person who was waiting
                 * for it. Inside this transaction on purpose: if the cancellation
                 * rolls back, the seat never freed, and somebody would have been
                 * moved to the front of a queue for nothing.
                 *
                 * Promotion moves them into the driver's inbox — it does not seat
                 * them. See {@see PromoteFromWaitlistAction}.
                 */
                $this->promote->forTrip($trip, $release);
            }

            BookingEventLog::record(
                $booking,
                BookingEventType::Cancelled,
                $actorType,
                $actorId,
                from: $from,
                metadata: ['reason' => $reason, 'seats_released' => $release],
            );

            return $booking;
        });
    }
}
