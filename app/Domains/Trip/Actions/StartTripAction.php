<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Safety\Support\EscortCoverage;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\TripSettings;
use Illuminate\Support\Facades\DB;

/**
 * "Start Today's Commute" (Chapter 8's opening scene).
 *
 * 🔴 The checks are the point of this Action, not the row it writes. Chapter 8 lists
 * them in order — approved account, licence not expired, vehicle still approved, trip
 * not cancelled — and every one of them is something that was true when the commute was
 * published and may have stopped being true since. A licence expires on a date nobody
 * looks at; a vehicle is suspended by a reviewer on a Tuesday. Re-checking at the
 * moment of departure is the only check that describes the run about to happen.
 *
 * The checks are the same ones publishing uses, called from the same place, because a
 * driver who may no longer publish must not be able to drive either — and two copies of
 * that rule would eventually disagree.
 *
 * 🔒 GPS permission is in Chapter 8's list too and is deliberately NOT checked here: the
 * server cannot verify it, and a client that claimed it would be trusted for something
 * it can lie about. What the server can do is notice the absence of location points,
 * which `trip_sessions.last_location_at` exists for.
 */
final readonly class StartTripAction
{
    public function execute(ScheduledTrip $trip): TripSession
    {
        $offer = $trip->commuteOffer;

        /*
         * Re-checked at departure, not trusted from publish time. See the class note:
         * a licence that expired last week means this run must not happen, whatever the
         * commute's status says.
         */
        CreateCommuteOfferAction::assertDriverMayPublish($offer->driverProfile);
        CreateCommuteOfferAction::usableVehicle($offer->driverProfile, $offer->vehicle_id);

        $this->assertTripIsStartable($trip);
        $this->assertWithinStartWindow($trip);

        return DB::transaction(function () use ($trip): TripSession {
            /*
             * The unique index on `scheduled_trip_id` is what actually prevents two
             * sessions, not this check — two taps on a slow connection arrive as two
             * requests, and only the database sees both. This turns the constraint
             * violation into something the driver can read.
             */
            if ($trip->tripSession !== null) {
                throw DomainException::of(ErrorCode::TripAlreadyStarted);
            }

            $session = new TripSession;

            $session->fill([
                'scheduled_trip_id' => $trip->id,
                'started_at' => now(),
            ]);

            // Not fillable: a run must never be able to declare itself in progress
            // through the request that started it.
            $session->current_status = TripSessionStatus::Preparing->value;

            $session->save();

            $trip->forceFill(['status' => ScheduledTripStatus::Preparing->value])->save();

            $this->openAttendanceRows($trip);

            $this->tellThePassengers($trip);

            $this->countUnderEscort($trip);

            return $session;
        });
    }

    /**
     * Chapter 8's first line to the passenger: "Ahmed has started today's commute."
     */
    private function tellThePassengers(ScheduledTrip $trip): void
    {
        $driverName = $trip->commuteOffer->driverProfile->user->public_first_name;

        Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('status', BookingStatus::Confirmed)
            ->with('passenger')
            ->get()
            ->each(fn (Booking $booking) => Notifier::send($booking->passenger, NotificationType::TripStarted,
                ['name' => $driverName],
                ['tripId' => $trip->id],
            ));
    }

    /**
     * A run that leaves while its corridor is under night escort is counted against the window —
     * the "trips covered" the desk reads in the morning. Counted with an atomic increment, not a
     * read-and-write, because several drivers on one corridor start within the same minute.
     */
    private function countUnderEscort(ScheduledTrip $trip): void
    {
        EscortCoverage::activeFor($trip->commuteOffer->corridor_id)?->increment('trips_covered');
    }

    /**
     * One `pending` attendance row per seat, written when the run starts.
     *
     * 🔴 Created up front rather than on each check-in, and that is a deliberate
     * difference in meaning: a row that exists and says `pending` records that somebody
     * was expected and has not been marked yet. With rows created only on check-in, a
     * passenger nobody confirmed is indistinguishable from a passenger who was never on
     * the run — and telling those two apart is the whole of a no-show dispute.
     */
    private function openAttendanceRows(ScheduledTrip $trip): void
    {
        $bookings = Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('status', BookingStatus::Confirmed)
            ->get();

        foreach ($bookings as $booking) {
            /*
             * `firstOrCreate` on the booking id, because the run may be started, cancelled
             * and started again — and a second row is impossible anyway (the booking id IS
             * the primary key). Idempotent rather than guarded, so a retry is harmless.
             */
            $attendance = Attendance::query()->firstOrNew(['booking_id' => $booking->id]);

            if ($attendance->exists) {
                continue;
            }

            // Not fillable (pitfall #51): attendance is what triggers billing, so its
            // status is only ever set by the Action that decides it.
            $attendance->status = AttendanceStatus::Pending->value;
            $attendance->save();
        }
    }

    private function assertTripIsStartable(ScheduledTrip $trip): void
    {
        if ($trip->status === ScheduledTripStatus::Cancelled) {
            throw DomainException::of(ErrorCode::TripNotCancellable);
        }

        if ($trip->status !== ScheduledTripStatus::Scheduled) {
            throw DomainException::of(ErrorCode::TripAlreadyStarted);
        }
    }

    /**
     * A run may be started shortly before it leaves, and not the night before.
     *
     * 🔴 Without a window, a driver could put a run "underway" for twelve hours — and
     * two things are read off that state: the live map a passenger watches for a car
     * that is not coming yet, and the GPS trail a dispute is settled from. A trail that
     * starts at midnight proves nothing about a seven o'clock pickup.
     *
     * No lower bound: a run whose departure has passed is exactly the run a late driver
     * needs to be able to start.
     */
    private function assertWithinStartWindow(ScheduledTrip $trip): void
    {
        $minutesUntilDeparture = now()->diffInMinutes($trip->departure_at, absolute: false);

        if ($minutesUntilDeparture > TripSettings::startWindowMinutes()) {
            throw DomainException::of(ErrorCode::TripTooEarlyToStart, fields: [
                'departureAt' => [$trip->departure_at->toIso8601String()],
                'windowMinutes' => [(string) TripSettings::startWindowMinutes()],
            ]);
        }
    }
}
