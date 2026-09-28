<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\WaitTimerOutcome;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Models\TripWaitTimer;
use App\Domains\Trip\Support\TripSettings;
use Illuminate\Support\Facades\DB;

/**
 * "Sara isn't at Point 90 yet — start the 5-minute wait timer" (screen 41).
 *
 * 🔴 What this actually is: a small clock that turns an argument into a record. A passenger
 * who is two minutes away is not a no-show, and a driver who waits for everybody is late
 * for four other people. Before there was a timer, that tension was settled in the moment by
 * whoever felt strongest about it, and afterwards there was nothing to look at — the
 * passenger said she waited, the driver said she didn't, and support had two accounts and no
 * facts.
 *
 * So the timer exists to be evidence rather than to enforce. It never leaves on its own and
 * it never marks anybody absent: the driver still decides, and the record says how long she
 * actually gave.
 *
 * 🔒 Which is why `driver_left` exists separately from `no_show`. A driver may always
 * depart — she cannot be held hostage by somebody who is not coming — but departing after
 * ninety seconds of a five-minute grace is a different morning from departing after the
 * grace ran out, and the passenger marked absent is entitled to have that difference
 * recorded rather than flattened.
 */
final readonly class WaitTimerAction
{
    /**
     * Start waiting for one passenger.
     */
    public function start(TripSession $session, Booking $booking): TripWaitTimer
    {
        $this->assertUnderway($session);

        if ($this->running($session, $booking->id) !== null) {
            throw DomainException::of(ErrorCode::WaitTimerAlreadyRunning);
        }

        $timer = new TripWaitTimer;

        $timer->fill([
            'trip_session_id' => $session->id,
            'booking_id' => $booking->id,
            'started_at' => now(),
            /*
             * Copied onto the row rather than read from settings when the timer is later
             * examined. The grace is a policy number the dashboard can change, and a
             * dispute about this morning has to be settled against the promise that was
             * in force this morning — not against whatever the number became afterwards.
             */
            'grace_seconds' => TripSettings::waitGraceSeconds(),
        ]);

        /*
         * Set rather than left to the column default. The database would store 0 either
         * way, but the model handed straight back to the response would carry null — and
         * the driver's screen would render "waiting 5:00 + null".
         */
        $timer->extended_seconds = 0;

        $timer->save();

        return $timer;
    }

    /**
     * "+2 min" — the driver choosing to give somebody longer.
     *
     * Extends rather than restarts, so `started_at` still says when the waiting began and
     * `extended_seconds` says how much was given on top. A restart would erase the fact
     * that a driver waited nine minutes in total, which is the generous version of the
     * story and the one she would want on record.
     */
    public function extend(TripWaitTimer $timer): TripWaitTimer
    {
        $this->assertRunning($timer);

        /*
         * Allowed even after the grace has run out. The screen still shows the timer with
         * "grace ended" beside it, and a driver who sees somebody running towards the car
         * at 5:10 should be able to give them another two minutes — refusing would make the
         * generous act the one the app forbids.
         */
        $timer->increment('extended_seconds', TripSettings::waitExtensionSeconds());

        return $timer->refresh();
    }

    /**
     * She came. Closed by the check-in, not by its own endpoint.
     */
    public function arrived(TripSession $session, Booking $booking): ?TripWaitTimer
    {
        return $this->close($session, $booking, WaitTimerOutcome::Arrived);
    }

    /**
     * The driver is leaving without this passenger.
     *
     * 🔴 The outcome is decided by the CLOCK, not by what the driver says it is. If the
     * grace had run out it is a no-show; if it had not, the record says the driver left
     * early. Letting the caller choose would make the one fact a dispute turns on the
     * choice of the party being disputed.
     */
    public function departedWithout(TripSession $session, Booking $booking): ?TripWaitTimer
    {
        $timer = $this->running($session, $booking->id);

        if ($timer === null) {
            return null;
        }

        return $this->close(
            $session,
            $booking,
            $timer->hasExpired() ? WaitTimerOutcome::NoShow : WaitTimerOutcome::DriverLeft,
        );
    }

    /**
     * The timer still counting for this passenger, if there is one.
     */
    public function running(TripSession $session, string $bookingId): ?TripWaitTimer
    {
        return TripWaitTimer::query()
            ->where('trip_session_id', $session->id)
            ->where('booking_id', $bookingId)
            ->whereNull('outcome')
            ->latest('started_at')
            ->first();
    }

    private function close(TripSession $session, Booking $booking, WaitTimerOutcome $outcome): ?TripWaitTimer
    {
        $timer = $this->running($session, $booking->id);

        if ($timer === null) {
            // Nothing was waiting, which is the ordinary case: most passengers are at the
            // gate and no timer is ever started for them.
            return null;
        }

        return DB::transaction(function () use ($timer, $outcome): TripWaitTimer {
            $timer->forceFill(['outcome' => $outcome->value])->save();

            return $timer;
        });
    }

    private function assertRunning(TripWaitTimer $timer): void
    {
        if (! $timer->isRunning()) {
            throw DomainException::of(ErrorCode::WaitTimerNotRunning, fields: [
                'outcome' => [strtoupper($timer->outcome->value)],
            ]);
        }
    }

    private function assertUnderway(TripSession $session): void
    {
        if (! $session->current_status->isUnderway()) {
            throw DomainException::of(ErrorCode::AttendanceNotConfirmable, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }
    }
}
