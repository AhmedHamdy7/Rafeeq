<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Events\TripLocationUpdated;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\LiveLocationStore;
use App\Domains\Trip\Support\TripSettings;
use App\Domains\Trip\ValueObjects\TripPosition;

/**
 * Where the car is (Chapter 8's Live Location).
 *
 * 🔴 Pitfall #46 in one sentence: writing a row per ping is 100 inserts a second at scale
 * and the database falls over. So a position does two separate jobs here — it becomes the
 * current position immediately (cache + broadcast, so a passenger's map moves), and it joins
 * a batch that a scheduled command writes down. See {@see LiveLocationStore}.
 *
 * 🔒 And the second reason this class is careful: `trip_locations` is the most
 * privacy-sensitive table in the product. It is a minute-by-minute record of where a real
 * person was, and it exists only because a no-show dispute has no other evidence. That is
 * why the retention date is written on every row as it is created rather than worked out
 * later — a deletion policy that depends on somebody remembering to run it is not a policy.
 */
final readonly class RecordTripLocationAction
{
    public function __construct(private LiveLocationStore $store) {}

    /**
     * @param  array<int, TripPosition>  $reported
     * @return array<int, TripPosition> the points that were accepted
     */
    public function execute(TripSession $session, array $reported): array
    {
        /*
         * Only while the car is out. A position on a run that has not left is a phone sitting
         * on a kitchen table, and one on a finished run is somebody's evening — neither is
         * this trip, and the second is a privacy problem rather than a data problem.
         */
        if (! $session->current_status->isUnderway()) {
            throw DomainException::of(ErrorCode::TripNotStarted, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }

        $accepted = $this->plausible($session, $reported);

        if ($accepted === []) {
            return [];
        }

        // Newest by the DEVICE's clock, not by arrival order: a batch that came out of a
        // tunnel arrives in whatever order the client queued it.
        usort($accepted, fn (TripPosition $a, TripPosition $b) => $a->recordedAt <=> $b->recordedAt);

        $latest = end($accepted);

        $this->store->putCurrent($session->id, $latest);
        $this->store->buffer($session->id, $accepted);

        /*
         * `last_location_at` on the session is the GPS-dropout signal, and it is the one
         * thing here that goes to the database on every batch. That is affordable — one
         * indexed update per batch, not per point — and it is what lets a client say "this
         * dot is four minutes old" instead of drawing a car that stopped reporting.
         */
        $session->forceFill(['last_location_at' => $latest->recordedAt])->save();

        /*
         * Broadcast after the cache is written, so a listener that immediately re-reads gets
         * the position it was just told about rather than the one before it.
         */
        TripLocationUpdated::dispatch($session->id, $latest);

        return $accepted;
    }

    /**
     * Which of the reported points are worth keeping.
     *
     * 🔴 Every one of these filters is here because the value came from a device we do not
     * control, over a network, from an app anybody can decompile:
     *
     * - A point from the FUTURE is either a wrong clock or a fabrication, and both would
     *   poison the trail a dispute is read from. A small skew is allowed, because phone
     *   clocks are routinely a few seconds out and rejecting those would throw away honest
     *   data.
     * - A point from before this run could possibly have begun belongs to some earlier
     *   journey. Accepting it would append somebody's yesterday to this morning's evidence.
     *   Bounded by the earliest moment the run could have STARTED — the departure less the
     *   start window — and not by `started_at`, because a phone has usually had a fix for a
     *   few seconds before the driver taps the button, and those points are honest.
     * - A point with a terrible accuracy figure is a phone guessing from cell towers, which
     *   can be kilometres out. The column exists "to filter out the bad points", and drawing
     *   them puts the car in the wrong district on the passenger's map.
     *
     * Filtered rather than refused: a batch of six points where one is bad should deliver
     * five, not fail. A 422 would make the client retry the whole batch forever.
     *
     * @param  array<int, TripPosition>  $reported
     * @return array<int, TripPosition>
     */
    private function plausible(TripSession $session, array $reported): array
    {
        $ceiling = now()->addSeconds(TripSettings::locationClockSkewSeconds());

        /*
         * The earliest instant this run could have been started at all. Using `started_at`
         * instead looked right and was wrong: a phone that already had a fix reports the
         * reading it took a few seconds before the driver pressed the button, and every one
         * of those would have been thrown away.
         */
        $floor = $session->scheduledTrip->departure_at->copy()
            ->subMinutes(TripSettings::startWindowMinutes());

        $worstAccuracy = TripSettings::locationMaxAccuracyMeters();

        $accepted = [];

        foreach ($reported as $position) {
            if ($position->recordedAt->greaterThan($ceiling)) {
                continue;
            }

            if ($position->recordedAt->lessThan($floor)) {
                continue;
            }

            if ($position->accuracyMeters !== null && $position->accuracyMeters > $worstAccuracy) {
                continue;
            }

            $accepted[] = $position;
        }

        return $accepted;
    }

    /**
     * Drops the live position when a run ends.
     *
     * 🔒 Called on completion, because the current position is for a journey in progress and
     * nothing else. Left behind, it would answer "where is the car" with where it was when
     * somebody got out — which is, for most commutes, an office car park at the same time
     * every weekday.
     */
    public function clearCurrent(TripSession $session): void
    {
        $this->store->forgetCurrent($session->id);
    }

    /**
     * The newest position, or null when the run has gone quiet.
     */
    public function current(TripSession $session): ?TripPosition
    {
        return $session->current_status === TripSessionStatus::Completed
            ? null
            : $this->store->current($session->id);
    }
}
