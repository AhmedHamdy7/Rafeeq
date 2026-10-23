<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripSession;
use App\Http\Controllers\Api\V1\Trip\TripLocationController;
use Illuminate\Support\Facades\DB;

/**
 * A passenger getting out before where she agreed to (Feature 03 v5).
 *
 * > *"`POST /bookings/{id}/end-early` — Passenger safety exit, immediate location-access
 * > revocation."* And: *"Passenger pre-pickup cancel anytime; after pickup end early."*
 *
 * 🔴 **This is a safety exit, so it asks for nothing and refuses almost nothing.** No reason is
 * required, no confirmation from the driver, no window. A woman who wants out of a car is not
 * somebody to put a form in front of, and a flow that needed the driver's agreement would need it
 * from the person she may be getting away from. The driver is TOLD, because she is driving and
 * somebody just left her car, and because v5 asks her to end safely rather than be surprised.
 *
 * 🔒 **Her location access ends with it, and most of that is already structural.** The trip's live
 * position is only readable by somebody holding a pending or confirmed booking
 * ({@see TripLocationController::visible()}), so the moment this
 * booking becomes `ended_early` she can no longer watch the car — which matters because for most
 * commutes where it goes next is the driver's street. What is NOT structural is the share link she
 * may have sent a friend: it hangs off the trip session rather than her booking, so it is revoked
 * here explicitly. Leaving it would keep a third party watching a journey that is over for her.
 *
 * 🔴 Her booking becomes `ended_early`, not `completed` and not cancelled. See
 * {@see BookingStatus::EndedEarly} on why both of those would say something false about what she
 * paid for and what she is owed.
 */
final readonly class EndBookingEarlyAction
{
    /**
     * @param  string|null  $reason  optional, and offered rather than demanded — see the class note
     */
    public function execute(TripSession $session, Booking $booking, ?string $reason = null): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                'status' => [strtoupper($booking->status->value)],
            ]);
        }

        $attendance = Attendance::query()->whereKey($booking->getKey())->first();

        /*
         * She has to be in the car. Before a pickup there is nothing to leave and cancelling is the
         * right move — v5 says so: *"Passenger pre-pickup cancel anytime; after pickup end early."*
         * Routing her here instead would record a journey she never started.
         */
        $onBoard = $attendance !== null
            && ($attendance->status === AttendanceStatus::Present || $attendance->status === AttendanceStatus::Late)
            && $attendance->checked_out_at === null;

        if (! $onBoard) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'currentStatus' => [strtoupper($attendance?->status->value ?? 'MISSING')],
            ]);
        }

        return DB::transaction(function () use ($session, $booking, $attendance, $reason): Booking {
            $attendance->forceFill(['checked_out_at' => now()])->save();

            $booking->forceFill([
                'status' => BookingStatus::EndedEarly->value,
                /*
                 * The moment is recorded on the booking as well as the attendance row, because a
                 * support conversation about this starts from the booking — and because when the
                 * money path exists, what she travelled is what she is charged for.
                 */
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
                // No fee. She was carried; this is not a cancellation, whatever the column is
                // called, and charging her for leaving a car she wanted out of is unthinkable.
                'cancellation_fee_piastres' => 0,
            ])->save();

            BookingEventLog::record(
                $booking,
                BookingEventType::Completed,
                BookingActorType::Passenger,
                $booking->passenger_user_id,
                from: BookingStatus::Confirmed,
                metadata: ['ended_early' => true, 'reason' => $reason],
            );

            /*
             * 🔒 The share link she may have given somebody dies with her journey. Her own access
             * is already gone — the position is only readable on a live booking — but this one is
             * keyed to the run rather than to her seat, so it has to be closed by hand.
             */
            LiveShare::query()
                ->where('trip_session_id', $session->getKey())
                ->where('user_id', $booking->passenger_user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            // Her remaining stop is not a place the driver has to go any more.
            NavigationStop::query()
                ->where('scheduled_trip_id', $session->scheduled_trip_id)
                ->where('booking_id', $booking->getKey())
                ->where('kind', NavigationStopKind::PassengerDropoff->value)
                ->whereNull('reached_at')
                ->update(['reached_at' => now()]);

            $this->tellTheDriver($session, $booking);

            return $booking->refresh();
        });
    }

    /**
     * The driver learns somebody has left her car.
     *
     * 🔒 The reason is NOT passed on. It may be about her, and it may be the reason a woman needed
     * to get out — handing it to the person she left would make the exit something she had to
     * justify to them. Support reads it on the booking.
     */
    private function tellTheDriver(TripSession $session, Booking $booking): void
    {
        $session->loadMissing('scheduledTrip.commuteOffer.driverProfile.user');

        $driver = $session->scheduledTrip?->commuteOffer?->driverProfile?->user;

        if ($driver === null) {
            return;
        }

        Notifier::send($driver, NotificationType::PassengerEndedEarly, params: [
            'name' => $booking->passenger?->public_first_name ?? '',
        ], data: [
            'bookingId' => $booking->getKey(),
            'tripId' => (string) $session->scheduled_trip_id,
        ]);
    }
}
