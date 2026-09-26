<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;

/**
 * "Sara arrived" — the driver confirming who is actually in the car (decision D18).
 *
 * 🔴 D18 is the most consequential decision in the product, and this class is where it
 * lives. It makes the driver's tap the thing that decides whether a passenger is charged,
 * which the Master Plan (§15.6) states plainly: "one party deciding the other's bill".
 * It was chosen because it is the only method that works — a QR code fails when a phone
 * is flat, GPS fails in a basement car park, and a PIN fails when somebody is running for
 * the car.
 *
 * So the safeguards are not optional extras; they are what makes the decision acceptable,
 * and §15.6 lists them:
 *
 *   1. a 24-hour window for the passenger to say it is wrong — {@see DisputeAttendanceAction}
 *   2. GPS recorded as SUPPORTING evidence, never as proof — below
 *   3. two hours before any money moves (Phase 8)
 *   4. a driver with an unusual dispute rate flagged for review (Phase 13)
 *
 * This class owns 1's precondition and 2. Shipping D18's power without them would be the
 * failure the Master Plan wrote them down to prevent.
 */
final readonly class ConfirmAttendanceAction
{
    /**
     * How close to the agreed meeting point counts as corroboration.
     *
     * Generous on purpose: a phone's fix is routinely out by tens of metres, a gate is a
     * wide thing, and this figure is not deciding anything — it is annotating a record
     * that support may read later. A tight radius would fill the table with "not
     * corroborated" on perfectly honest mornings and make the annotation useless.
     */
    private const int CORROBORATION_RADIUS_METERS = 250;

    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * The passenger is in the car.
     *
     * @param  Coordinate|null  $at  where the driver's phone says they are, if it offered
     */
    public function present(TripSession $session, Booking $booking, string $confirmedBy, ?Coordinate $at = null): Attendance
    {
        return $this->decide($session, $booking, $this->arrivalStatus($session, $booking), $confirmedBy, $at);
    }

    /**
     * The passenger did not come.
     *
     * 🔴 An explicit act, never inferred. Completion leaves an unconfirmed row `pending`
     * precisely so that a driver who forgot to tap does not mark anybody absent — which
     * means somebody recorded as a no-show was recorded by a person who chose to.
     */
    public function noShow(TripSession $session, Booking $booking, string $confirmedBy): Attendance
    {
        return $this->decide($session, $booking, AttendanceStatus::PassengerNoShow, $confirmedBy);
    }

    private function decide(
        TripSession $session,
        Booking $booking,
        AttendanceStatus $status,
        string $confirmedBy,
        ?Coordinate $at = null,
    ): Attendance {
        /*
         * Only while the run is out on the road. A check-in on a run that has not left is
         * a claim about a car that is still parked, and one on a finished run is a record
         * being edited after the fact — which is exactly what the transition rules on
         * `AttendanceStatus` exist to prevent.
         */
        if (! $session->current_status->isUnderway()) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }

        $attendance = Attendance::query()->whereKey($booking->id)->first();

        if ($attendance === null) {
            /*
             * Every confirmed seat gets a row when the run starts, so a missing one means
             * this booking was not on the run when it set off — somebody booked after
             * departure, or the row belongs to another day.
             */
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'attendance' => ['MISSING'],
            ]);
        }

        if (! $attendance->status->canTransitionTo($status)) {
            /*
             * 🔒 Already decided. The refusal names what it already says, because the
             * driver's screen may simply be stale — and "already marked present" is a
             * different thing to be told than "that is not allowed".
             */
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'currentStatus' => [strtoupper($attendance->status->value)],
            ]);
        }

        return DB::transaction(function () use ($attendance, $booking, $status, $confirmedBy, $at): Attendance {
            $changes = [
                'status' => $status->value,
                // 'driver', per D18. A column rather than an assumption, because the day
                // a second method is added, every old row still says who decided it.
                'confirmed_by' => 'driver',
                'confirmed_by_user_id' => $confirmedBy,
                'confirmed_at' => now(),
            ];

            if ($status->travelled()) {
                $changes['checked_in_at'] = now();
            }

            if ($at !== null) {
                $confidence = $this->corroborate($booking, $at);

                /*
                 * Corroborated means the position AGREED, not that one was supplied. A
                 * driver checking somebody in from two kilometres away has given us a
                 * reading, and the reading says the opposite of corroboration — recording
                 * that as `true` would put the strongest word in the table on the weakest
                 * evidence in it, and a reviewer scanning the column would read it
                 * backwards.
                 */
                $changes['gps_corroborated'] = $confidence !== null && $confidence > 0;
                $changes['gps_confidence'] = $confidence === null
                    ? null
                    : number_format($confidence, 2, '.', '');
            }

            // Not `fill`: attendance is what triggers billing, so none of these fields is
            // mass-assignable (pitfall #51).
            $attendance->forceFill($changes)->save();

            return $attendance;
        });
    }

    /**
     * Present, or late.
     *
     * 🔴 Derived from the clock rather than asked of the driver. On screen she taps "Sara
     * arrived" — she is not classifying anybody's punctuality, and making her choose would
     * turn a neutral act into a judgement she has no reason to want to make about somebody
     * she sees every morning.
     *
     * Late means the passenger made the run wait: a timer was running for them when they
     * turned up. Until wait timers exist, nothing produces `late`, and that is honest —
     * inventing it from the departure time would mark the whole car late whenever the
     * DRIVER was, which is the opposite of what the value means.
     */
    private function arrivalStatus(TripSession $session, Booking $booking): AttendanceStatus
    {
        $waited = $session->waitTimers()
            ->where('booking_id', $booking->id)
            ->whereNull('outcome')
            ->exists();

        return $waited ? AttendanceStatus::Late : AttendanceStatus::Present;
    }

    /**
     * How well the driver's position agrees with where they were meant to meet.
     *
     * 🔒 Supporting evidence and nothing more — the Master Plan says so in as many words:
     * "not a condition for confirmation, but recorded for the dispute". A confirmation that
     * REQUIRED a good fix would fail in a basement car park and under a flyover, and the
     * driver would learn to check people in from the street to make it work.
     *
     * Null when there is nothing to compare against: a passenger meeting the driver at the
     * gate has no point of their own, and the run's origin is where the driver already is,
     * so measuring against it would corroborate everything and mean nothing.
     */
    private function corroborate(Booking $booking, Coordinate $at): ?float
    {
        $point = $booking->pickup_point;

        if ($point === null) {
            return null;
        }

        $metres = $this->geo->distanceMeters(
            $at,
            new Coordinate((float) $point->lat, (float) $point->lng),
        )->metres;

        if ($metres > self::CORROBORATION_RADIUS_METERS) {
            // Zero, not null: a reading was taken and it disagrees, which is a different
            // fact from having no reading at all — and the one a dispute turns on.
            return 0.0;
        }

        /*
         * 1.00 at the point itself, falling to 0 at the edge of the radius. A number
         * rather than a flag because a dispute is settled on how strong the evidence was,
         * and "within 250 metres" hides the difference between five metres and two hundred.
         */
        return round(1 - ($metres / self::CORROBORATION_RADIUS_METERS), 2);
    }
}
