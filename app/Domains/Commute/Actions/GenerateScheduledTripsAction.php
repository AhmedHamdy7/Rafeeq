<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a recurrence into real, bookable days.
 *
 * Two decisions shape everything here.
 *
 * **A rolling horizon, not the whole schedule.** Generating to the end date
 * would put 5,000 offers × ~110 workdays — over half a million rows — into the
 * fastest-growing table in the system on day one, most of them for dates nobody
 * will ever look at. A daily job extends the horizon instead, and
 * `generated_until` records how far it got.
 *
 * **The UTC instant is computed here, never stored ahead of time.** This is
 * pitfall #6, the most dangerous bug in the project: Egypt has observed real DST
 * by law since 2023, from the last Friday of April to the last Thursday of
 * October. A schedule says "07:05, Africa/Cairo" — a wall-clock time a person
 * reads off a clock. Converting that to a fixed UTC offset when the schedule is
 * saved means every trip after a transition departs an hour wrong, which for a
 * commuter means missing work.
 *
 * Generation is idempotent. A retry, an overlapping manual run, or a job that
 * fires twice must not produce two trips for one day — enforced by a unique
 * index on (offer, date) as well as by the query here, because only the index
 * holds under a race.
 */
final readonly class GenerateScheduledTripsAction
{
    /**
     * @return int how many trips were created
     */
    public function execute(CommuteOffer $offer, ?CarbonImmutable $horizonEnd = null): int
    {
        $schedule = $offer->schedule;

        if ($schedule === null || $offer->status !== CommuteOfferStatus::Published) {
            // Only a published offer produces bookable days. A paused one keeps
            // the trips it already had (Chapter 4: "paused commutes keep
            // history") and simply stops growing.
            return 0;
        }

        $horizonEnd ??= CarbonImmutable::today()
            ->addDays((int) config('rafeeq.commute.generation_horizon_days'));

        $from = $this->startFrom($schedule);
        $until = $this->endAt($schedule, $horizonEnd);

        if ($from->greaterThan($until)) {
            return 0;
        }

        $days = DaysMask::fromBits($schedule->days_mask);
        $existing = $this->existingDates($offer, $from, $until);

        $created = 0;

        DB::transaction(function () use ($offer, $schedule, $days, $from, $until, $existing, &$created): void {
            for ($date = $from; $date->lessThanOrEqualTo($until); $date = $date->addDay()) {
                if (! $days->includesDate($date) || in_array($date->toDateString(), $existing, true)) {
                    continue;
                }

                $this->createTrip($offer, $schedule, $date);
                $created++;
            }

            // Recorded even when nothing was created, so the next run starts
            // from here rather than walking the same empty days again.
            $schedule->forceFill(['generated_until' => $until->toDateString()])->save();
        });

        return $created;
    }

    /**
     * Never earlier than today: a past day cannot be booked, and generating one
     * would put a trip in the queue that is already over.
     */
    private function startFrom(CommuteSchedule $schedule): CarbonImmutable
    {
        $candidates = [
            CarbonImmutable::parse($schedule->start_date->toDateString()),
            CarbonImmutable::today(),
        ];

        if ($schedule->generated_until !== null) {
            $candidates[] = CarbonImmutable::parse($schedule->generated_until->toDateString())->addDay();
        }

        return max($candidates);
    }

    private function endAt(CommuteSchedule $schedule, CarbonImmutable $horizonEnd): CarbonImmutable
    {
        // The schedule's own end date always wins: Chapter 4 §4 forbids infinite
        // commutes, and the horizon must not reach past what the driver agreed to.
        return min($horizonEnd, CarbonImmutable::parse($schedule->end_date->toDateString()));
    }

    /**
     * @return array<int, string>
     */
    private function existingDates(CommuteOffer $offer, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $dates = [];

        $rows = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->whereBetween('trip_date', [$from->toDateString(), $until->toDateString()])
            ->pluck('trip_date');

        foreach ($rows as $date) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    private function createTrip(CommuteOffer $offer, CommuteSchedule $schedule, CarbonImmutable $date): void
    {
        $departureAt = DepartureTimeCalculator::toUtc(
            $date->toDateString(),
            $schedule->departure_time,
            $schedule->timezone,
        );

        $trip = new ScheduledTrip;

        $trip->fill([
            'commute_offer_id' => $offer->id,
            'commute_schedule_id' => $schedule->id,
            'trip_date' => $date->toDateString(),
            'departure_at' => $departureAt,
            'departure_local' => $date->toDateString().' '.$schedule->departure_time,
            // Snapshots, deliberately: Chapter 4 §6 says a price change affects
            // only FUTURE trips. A booked passenger's day keeps the terms they
            // agreed to, so these are copied rather than read through the offer.
            'seats_total' => $offer->seats_total,
            'price_snapshot_piastres' => $offer->price_per_seat_piastres,
            'booking_deadline_at' => $this->bookingDeadline($date, $schedule->timezone),
        ]);

        $trip->save();
    }

    /**
     * The evening before, in the driver's own timezone, so they know who is
     * coming before they sleep. Converted to UTC like every other instant.
     */
    private function bookingDeadline(CarbonImmutable $tripDate, string $timezone): CarbonImmutable
    {
        $hour = (int) config('rafeeq.commute.booking_deadline_hour');

        return DepartureTimeCalculator::toUtc(
            $tripDate->subDay()->toDateString(),
            sprintf('%02d:00:00', $hour),
            $timezone,
        );
    }
}
