<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;

/**
 * One passenger's journey ends, while the run carries on (Feature 03 v5).
 *
 * > *"`POST /driver/bookings/{id}/dropped-off` — Complete that booking; rating enabled."*
 *
 * 🔴 **Why this is not just "wait for the driver to finish the run".** `CompleteTripAction` already
 * checks everybody out at once, and for a commute where the whole car gets out at the same office
 * that is exactly right. But v5's itinerary interleaves: a passenger set down at the start of the
 * Mehwar is out of the car twenty minutes before the driver reaches the last stop, and until now
 * she could not rate her ride, her booking still read `CONFIRMED`, and the driver's remaining
 * itinerary still listed a stop she had already made. Three screens were wrong for twenty minutes,
 * on every shared journey.
 *
 * 🔴 **It does not end the run, and cannot.** The driver still has people in the car. Finishing is
 * a separate act ({@see CompleteTripAction}), and v5 gates it on every passenger being resolved —
 * which is what this produces, one passenger at a time.
 *
 * ⚠️ Only somebody who was actually in the car. A `pending` attendance row means nobody recorded
 * whether she travelled, and a no-show means she was not there — setting either of them down would
 * be inventing a journey. The refusal names which, because the driver's screen may be stale.
 */
final readonly class DropOffPassengerAction
{
    public function __construct(private RecordMemberWarningAction $warnings) {}

    public function execute(TripSession $session, Booking $booking, string $confirmedBy): Attendance
    {
        if (! $session->current_status->isUnderway()) {
            // A drop-off on a run that has not left, or one already closed, is a record being
            // written about a car that was never moving.
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }

        $attendance = Attendance::query()->whereKey($booking->getKey())->first();

        if ($attendance === null) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'attendance' => ['MISSING'],
            ]);
        }

        /*
         * 🔴 She has to have been picked up. `present` and `late` are the two states that mean she
         * got in; anything else means she did not, and a drop-off would be a record of a journey
         * that did not happen — which is the same class of mistake as turning silence into a
         * no-show.
         */
        if ($attendance->status !== AttendanceStatus::Present && $attendance->status !== AttendanceStatus::Late) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'currentStatus' => [strtoupper($attendance->status->value)],
            ]);
        }

        if ($attendance->checked_out_at !== null) {
            // Already set down. Idempotent rather than an error: a double tap on a moving car is
            // the most ordinary thing in the world, and the second one has nothing to change.
            return $attendance;
        }

        return DB::transaction(function () use ($session, $attendance, $booking, $confirmedBy): Attendance {
            $attendance->forceFill(['checked_out_at' => now()])->save();

            /*
             * 🔴 Her booking completes NOW, which is what enables her rating: `SubmitRatingAction`
             * admits a completed booking, and until this existed she could not say anything about
             * a ride she had finished until the driver closed the whole run.
             */
            if ($booking->status === BookingStatus::Confirmed) {
                $booking->forceFill(['status' => BookingStatus::Completed->value])->save();

                BookingEventLog::record(
                    $booking,
                    BookingEventType::Completed,
                    BookingActorType::Driver,
                    $confirmedBy,
                    from: BookingStatus::Confirmed,
                    metadata: ['dropped_off_during_run' => true],
                );
            }

            /*
             * The stop is behind her, so the in-progress navigation stops listing it. v5 §3: the
             * live itinerary "reflects remaining actionable stops and current position, not
             * completed stops".
             */
            NavigationStop::query()
                ->where('scheduled_trip_id', $session->scheduled_trip_id)
                ->where('booking_id', $booking->getKey())
                ->where('kind', NavigationStopKind::PassengerDropoff->value)
                ->whereNull('reached_at')
                ->update(['reached_at' => now()]);

            /*
             * She is asked to rate while the journey is fresh, rather than at the end of somebody
             * else's commute. The driver is not asked yet — she is still driving, and one request
             * per run reaches her when it ends.
             */
            if ($booking->passenger !== null) {
                Notifier::send($booking->passenger, NotificationType::RatingDue, params: [
                    'name' => $session->scheduledTrip?->commuteOffer?->driverProfile?->user?->public_first_name ?? '',
                ], data: [
                    'bookingId' => $booking->getKey(),
                ]);
            }

            /*
             * 🔴 Her journey has ended, so a lateness warning recorded at her pickup may finally be
             * delivered (Feature 03 v5 §10: *"do not show any lateness message during the trip.
             * After drop-off the passenger receives a push-style notification"*).
             *
             * Here rather than in a sweep because this is the moment v5 names, and because a sweep
             * would deliver it minutes later — after she has put her phone away and while she is
             * still, as far as she knows, being told something about the ride she just finished.
             */
            $this->warnings->deliverFor($booking);

            return $attendance;
        });
    }
}
