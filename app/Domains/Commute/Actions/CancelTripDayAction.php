<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * "Cancel today" (screen 23): the driver calls off ONE day and the rest of the commute stands
 * (Screen Map §8.6, decided 2026-10-06).
 *
 * Until this existed the only tools were pausing or archiving the whole commute — so a driver who
 * was ill on a Tuesday either drove anyway or quietly did not turn up, and the second is the worst
 * thing that can happen to somebody waiting at a gate at seven in the morning.
 *
 * Everything on that day ends together, under the trip's lock:
 *
 * - every live booking becomes `cancelled_by_driver`, with a booking event naming the driver as
 *   the actor — the record a future reliability measure will count;
 * - seats go back to zero, and nobody is promoted from the waitlist (there is no run to promote
 *   them onto);
 * - a pending one-day request for that date is expired rather than left for the driver to answer;
 * - every passenger is told, by name and date — and NOT the reason, which may be personal and
 *   would show on a lock screen.
 *
 * 🔒 Money: nothing is charged and nothing is refunded here, because nothing has been paid — every
 * booking is cash, settled on the day, until Phase 8 brings online payment. When it does, a
 * driver-cancelled day must refund in full; that rule belongs with the payment, not here.
 *
 * Only a day that has not started. Once the run is under way, ending it is the trip lifecycle's
 * job (`AdvanceTripAction`), with its own record of what happened on the road.
 */
final readonly class CancelTripDayAction
{
    public function execute(ScheduledTrip $trip, User $driver, ?string $reason = null): ScheduledTrip
    {
        return DB::transaction(function () use ($trip, $driver, $reason): ScheduledTrip {
            $trip = ScheduledTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($trip->status !== ScheduledTripStatus::Scheduled) {
                throw DomainException::of(ErrorCode::TripNotCancellable, fields: [
                    'status' => [strtoupper($trip->status->value)],
                ]);
            }

            $trip->forceFill([
                'status' => ScheduledTripStatus::Cancelled->value,
                'cancelled_reason' => $reason ?? 'driver_cancelled_day',
                'seats_taken' => 0,
            ])->save();

            $bookings = Booking::query()
                ->where('scheduled_trip_id', $trip->id)
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
                ->with('passenger')
                ->lockForUpdate()
                ->get();

            foreach ($bookings as $booking) {
                $from = $booking->status;

                $booking->forceFill([
                    'status' => BookingStatus::CancelledByDriver->value,
                    'cancelled_at' => now(),
                    'cancelled_reason' => 'driver_cancelled_day',
                    'cancellation_fee_piastres' => 0,
                ])->save();

                BookingEventLog::record(
                    $booking,
                    BookingEventType::Cancelled,
                    BookingActorType::Driver,
                    $driver->id,
                    from: $from,
                    metadata: ['trip_cancelled' => true, 'seats_released' => $booking->seats_reserved],
                );

                if ($booking->passenger !== null) {
                    Notifier::send($booking->passenger, NotificationType::TripDayCancelled, [
                        'name' => $driver->public_first_name,
                        'date' => $trip->trip_date->format('j/n'),
                    ], ['tripId' => $trip->id, 'bookingId' => $booking->id]);
                }
            }

            // A one-day request for this date can no longer be answered with a seat.
            SeatRequest::query()
                ->where('scheduled_trip_id', $trip->id)
                ->whereIn('status', [SeatRequestStatus::Pending->value, SeatRequestStatus::Waitlisted->value])
                ->update(['status' => SeatRequestStatus::Expired->value, 'waitlist_position' => null]);

            return $trip;
        });
    }
}
