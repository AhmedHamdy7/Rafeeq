<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Carbon\CarbonImmutable;

/**
 * Closing a run the driver forgot to close, and letting her say she has not (Feature 03 v5).
 *
 * > *"Driver can finish after all passenger statuses resolved; **+15m past promised arrival** show
 * > still-driving/finish, **+5m auto-finish** only if no onboard/awaiting passengers. **Still
 * > Driving extends next check by 15m**."*
 *
 * 🔴 **The condition is the whole safety of this.** A run is closed automatically only when nobody
 * is still in the car and nobody is still waiting to be collected. Closing a run with somebody
 * aboard would end her live location — which is the one thing she is relying on — and closing one
 * with somebody still waiting would turn a driver who is simply late into a journey that never
 * happened, with a `pending` attendance row frozen for ever.
 *
 * So the sweep's job is narrow: a run whose people are all resolved, long past its promised
 * arrival, which the driver never tapped. That is forgetfulness, and forgetfulness is what it is
 * safe to tidy up.
 *
 * ⚠️ `pending` counts as **awaiting**, not as resolved. A row nobody decided is exactly the case
 * {@see CompleteTripAction} refuses to turn into a no-show, and the same reasoning applies harder
 * here: a sweep marking somebody absent is a decision nobody made at all.
 */
final readonly class AutoFinishTripsAction
{
    public function __construct(private CompleteTripAction $complete) {}

    /**
     * How long after the promised arrival the driver is first asked.
     */
    public static function graceMinutes(): int
    {
        return (int) config('rafeeq.trip.auto_finish_grace_minutes');
    }

    /**
     * How much longer "still driving" buys her.
     */
    public static function extensionMinutes(): int
    {
        return (int) config('rafeeq.trip.still_driving_extension_minutes');
    }

    /**
     * When a run that starts now should first be looked at.
     *
     * Measured from the PROMISED arrival where there is one, because that is the moment the driver
     * said the journey would be over — and from the departure plus the planned duration where there
     * is not, which is the best estimate we have rather than a guess at a clock time.
     */
    public static function firstCheckFor(TripSession $session): ?CarbonImmutable
    {
        $trip = $session->scheduledTrip;

        if ($trip === null) {
            return null;
        }

        $from = $trip->promised_arrival_at
            ?? ($trip->itinerary_duration_seconds === null
                ? null
                : CarbonImmutable::parse($trip->departure_at)->addSeconds((int) $trip->itinerary_duration_seconds));

        /*
         * No promise and no measured itinerary means no honest moment to count from, so the run is
         * never closed automatically. She closes it herself, which is the correct default: v5 only
         * auto-finishes as a convenience, never as a rule.
         */
        return $from === null ? null : CarbonImmutable::parse($from)->addMinutes(self::graceMinutes());
    }

    /**
     * The driver saying she is still on the road.
     *
     * Pushes the next look back, as many times as the road needs. There is deliberately no limit —
     * see the migration's note on why a counter would have to invent an answer for the fourth tap.
     */
    public function stillDriving(TripSession $session): TripSession
    {
        $session->forceFill([
            'auto_finish_check_at' => now()->addMinutes(self::extensionMinutes()),
            'still_driving_count' => (int) $session->still_driving_count + 1,
        ])->save();

        return $session->refresh();
    }

    /**
     * Close the runs that are plainly over.
     *
     * @return int how many were finished
     */
    public function sweep(): int
    {
        $due = TripSession::query()
            ->where('current_status', TripSessionStatus::InProgress->value)
            ->whereNotNull('auto_finish_check_at')
            ->where('auto_finish_check_at', '<=', now())
            ->with('scheduledTrip')
            ->get();

        $finished = 0;

        foreach ($due as $session) {
            if ($this->somebodyIsStillOnIt($session)) {
                continue;
            }

            $this->complete->execute($session);
            $finished++;
        }

        return $finished;
    }

    /**
     * Whether anybody is in the car or still waiting to be collected.
     */
    private function somebodyIsStillOnIt(TripSession $session): bool
    {
        $bookingIds = Booking::query()
            ->where('scheduled_trip_id', $session->scheduled_trip_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->pluck('id');

        if ($bookingIds->isEmpty()) {
            return false;
        }

        return Attendance::query()
            ->whereIn('booking_id', $bookingIds->all())
            ->where(fn ($query) => $query
                /*
                 * Aboard: picked up and not yet set down. Ending the run would cut her live
                 * location while she is in a moving car.
                 */
                ->where(fn ($aboard) => $aboard
                    ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value])
                    ->whereNull('checked_out_at'))
                /*
                 * Awaiting: nobody has decided whether she travelled. Closing on that would freeze
                 * a `pending` row for ever on a person who may have been standing on a kerb.
                 */
                ->orWhere('status', AttendanceStatus::Pending->value))
            ->exists();
    }
}
