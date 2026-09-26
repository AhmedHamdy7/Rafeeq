<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Complete Trip" (Chapter 8's Completion section).
 *
 * 🔴 The Bible describes completion as a PIPELINE of separate, idempotent jobs rather
 * than one operation, and explains why: "if the server falls over halfway, you get a trip
 * that finished but the money was never taken or the rating was never asked for". That
 * reasoning is right, and two of its steps belong to phases that do not exist yet —
 * releasing the driver's earnings (Phase 8) and asking both sides for a rating
 * (Phase 10).
 *
 * What is here is the part that must be true the instant the driver taps the button, and
 * it is all one transaction because all of it is one fact: the run is over. Splitting
 * THAT across jobs would mean a window where the trip is complete and its bookings are
 * not, which every list in the product reads.
 *
 * What is deliberately NOT here:
 *
 * - **Money.** No earnings are released and no fee is collected, and not only because
 *   Phase 8 is unbuilt: the Master Plan's §15.6 puts a two-hour delay between attendance
 *   being confirmed and anything being charged, precisely so a driver who tapped the
 *   wrong name can notice. Collecting here would be the mistake that safeguard exists
 *   to prevent.
 * - **Deciding who did not travel.** See `finaliseAttendance()`.
 */
final readonly class CompleteTripAction
{
    public function execute(TripSession $session): TripSession
    {
        AdvanceTripAction::assertCanTransitionTo($session, TripSessionStatus::Completed);

        return DB::transaction(function () use ($session): TripSession {
            $trip = $session->scheduledTrip;

            $session->forceFill([
                'current_status' => TripSessionStatus::Completed->value,
                'completed_at' => now(),
                /*
                 * From when the car moved, not from when the driver opened the app: a
                 * driver who starts the app twenty minutes early did not drive for
                 * twenty extra minutes.
                 *
                 * `distance_travelled_meters` stays null until there are GPS points to
                 * measure it from. Null rather than the route's planned distance, which
                 * would be a figure we did not observe presented as one we did.
                 */
                'duration_seconds' => $session->departed_at === null
                    ? null
                    : (int) $session->departed_at->diffInSeconds(now()),
            ])->save();

            $bookings = $this->finaliseAttendance($trip->id);

            $this->completeBookings($bookings);
            $this->recordStatistics($session, $trip->commute_offer_id, $bookings);

            $trip->forceFill(['status' => ScheduledTripStatus::Completed->value])->save();

            return $session;
        });
    }

    /**
     * Freezes what the attendance rows say, and decides nothing they do not.
     *
     * 🔴 A row still `pending` when the driver taps "complete" stays `pending`. It is
     * tempting to call it a no-show — the run finished and nobody confirmed them — and it
     * would be wrong. Decision D18 lets the DRIVER decide whether a passenger travelled,
     * which the Master Plan (§15.6) itself flags as "one party deciding the other's
     * bill". Turning the driver's SILENCE into a no-show would extend that power to
     * things she did not even do: a driver who forgets to tap four names would mark four
     * people absent, and each of them would carry it on a record strangers read.
     *
     * So `pending` means exactly what it says — nobody recorded whether this person
     * travelled. No money moves on it (Phase 8 collects on `present`), and no absence is
     * held against anybody. A no-show has to be an act: the wait timer's outcome, or the
     * driver marking it.
     *
     * @return Collection<int, Booking>
     */
    private function finaliseAttendance(string $tripId): Collection
    {
        $bookings = Booking::query()
            ->where('scheduled_trip_id', $tripId)
            ->where('status', BookingStatus::Confirmed)
            ->get();

        $checkedOut = Attendance::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
            ->whereNull('checked_out_at')
            ->get();

        foreach ($checkedOut as $attendance) {
            // The journey ended for everybody who was on it at the same moment.
            $attendance->forceFill(['checked_out_at' => now()])->save();
        }

        return $bookings;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function completeBookings(Collection $bookings): void
    {
        foreach ($bookings as $booking) {
            /*
             * Every confirmed booking is completed, including ones whose attendance was
             * never confirmed — because these two say different things. A booking's
             * status is about the booking's life ending; attendance is the record of who
             * actually travelled. That is why they are separate tables, and collapsing
             * them would leave a day in the past with bookings still saying "confirmed"
             * forever.
             */
            $booking->forceFill(['status' => BookingStatus::Completed->value])->save();

            BookingEventLog::record(
                $booking,
                BookingEventType::Completed,
                BookingActorType::Driver,
                $booking->driver_profile_id,
                from: BookingStatus::Confirmed,
            );
        }
    }

    /**
     * The counters three screens read.
     *
     * 🔴 Recomputed from rows rather than incremented, for `on_time_rate` — a percentage
     * kept by increment drifts the first time a job runs twice, and the wrong value
     * sticks forever with nothing to compare it against. The trip COUNTS are incremented,
     * because those have an obvious source to recount from if they ever look wrong.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    private function recordStatistics(TripSession $session, string $offerId, Collection $bookings): void
    {
        $profile = DriverProfile::query()->whereKey($session->scheduledTrip->commuteOffer->driver_profile_id)->first();

        if ($profile !== null) {
            $profile->increment('completed_trips_count');

            $this->recomputeOnTimeRate($profile);

            $this->bumpUserStat($profile->user_id, 'completed_trips_as_driver');
        }

        foreach ($bookings as $booking) {
            $this->bumpUserStat($booking->passenger_user_id, 'completed_trips_as_passenger');
        }

        $group = CommuteGroup::query()->where('commute_offer_id', $offerId)->first();

        // How many mornings this group has actually shared, which is the number the group
        // screen shows instead of "founded 3 weeks ago".
        $group?->increment('rides_together_count');
    }

    /**
     * What share of this driver's completed runs set off on time.
     *
     * Counted over runs that RECORDED a departure. A run completed without one cannot be
     * judged either way, and counting it as late would penalise a driver for a gap in our
     * own data.
     */
    private function recomputeOnTimeRate(DriverProfile $profile): void
    {
        $threshold = (int) config('rafeeq.trip.on_time_threshold_minutes');

        $sessions = TripSession::query()
            ->whereNotNull('departed_at')
            ->where('current_status', TripSessionStatus::Completed)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->whereIn('commute_offer_id', CommuteOffer::query()
                    ->where('driver_profile_id', $profile->user_id)
                    ->select('id'))
                ->select('id'))
            ->with('scheduledTrip')
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $onTime = $sessions->filter(
            fn (TripSession $session) => $session->scheduledTrip->departure_at
                ->diffInMinutes($session->departed_at, absolute: false) <= $threshold,
        )->count();

        $profile->forceFill([
            'on_time_rate' => round($onTime / $sessions->count() * 100, 2),
        ])->save();
    }

    /**
     * `user_stats` has no `$fillable` on purpose (it is written by a recompute job, not
     * by requests), so the row is built attribute by attribute the way that job would.
     */
    private function bumpUserStat(string $userId, string $column): void
    {
        $stat = UserStat::query()->whereKey($userId)->first();

        if ($stat === null) {
            // Attribute by attribute, NOT `firstOrNew(['user_id' => ...])`: that array is
            // a mass assignment, and this model deliberately has no `$fillable` because
            // it is written by a recompute job rather than by any request.
            $stat = new UserStat;
            $stat->user_id = $userId;
            $stat->{$column} = 0;
            $stat->save();
        }

        $stat->increment($column);
    }

    /**
     * The run the caller means, refusing if it never started.
     */
    public static function assertStarted(?TripSession $session): TripSession
    {
        return $session ?? throw DomainException::of(ErrorCode::TripNotStarted);
    }
}
