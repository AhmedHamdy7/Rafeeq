<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Commute\Support\SeatPriceQuote;
use App\Domains\Commute\Support\SeatPricing;
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

        /*
         * Loaded once, up front: `createTrip` reads the weekday row for every date in the horizon,
         * and strict mode would turn a lazy load there into an exception — or, worse in production,
         * into one query per generated day.
         */
        $offer->loadMissing(['weekdaySchedules', 'vehicle']);

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

        /*
         * 🔴 The automatic seat price (P-1, P-2, P-5), quoted ONCE per run at today's settings and
         * frozen onto every day this run creates (PAY-44). Days generated earlier keep the quote
         * they were born with, which is the whole of "a settings change never alters a day already
         * generated": a rate moved this afternoon reaches tomorrow's new days and nothing else.
         *
         * Never refused here. Publishing is where a car without approved seats is stopped; a
         * commute that got past it (or predates it) still has to keep generating days, so the
         * quote falls back to the minimum divisor rather than throwing in a nightly job.
         */
        $quote = SeatPricing::quote((int) $offer->route_distance_meters, $offer->vehicle);

        DB::transaction(function () use ($offer, $schedule, $days, $from, $until, $existing, $quote, &$created): void {
            for ($date = $from; $date->lessThanOrEqualTo($until); $date = $date->addDay()) {
                if (! $days->includesDate($date) || in_array($date->toDateString(), $existing, true)) {
                    continue;
                }

                $this->createTrip($offer, $schedule, $date, $quote);
                $created++;
            }

            /*
             * The commute's own copy of "what a seat costs now", shown to the driver on his
             * commute. A cache of the latest quote, never read to price anything.
             */
            $offer->forceFill([
                'seat_price_piastres' => $quote->seatPricePiastres,
                'driver_share_piastres' => $quote->driverSharePiastres,
            ])->save();

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

    private function createTrip(CommuteOffer $offer, CommuteSchedule $schedule, CarbonImmutable $date, SeatPriceQuote $quote): void
    {
        /*
         * 🔴 The weekday's own row decides the time and the capacity (Feature 03 v3). The price is
         * no longer per weekday: it comes from the route and the car (PAY-42), which are the same
         * every day.
         *
         * Falls back to the schedule's derived `departure_time` if a row is somehow missing, so a
         * commute never stops generating trips over a data gap. That fallback should be
         * unreachable: the save action writes one row per day in the mask, in the same
         * transaction.
         */
        $weekday = $offer->weekdaySchedules
            ->firstWhere('weekday_bit', DaysMask::bitForDate($date));

        $departureTime = $weekday->departure_time ?? $schedule->departure_time;

        $departureAt = DepartureTimeCalculator::toUtc(
            $date->toDateString(),
            $departureTime,
            $schedule->timezone,
        );

        $trip = new ScheduledTrip;

        $trip->fill([
            'commute_offer_id' => $offer->id,
            'commute_schedule_id' => $schedule->id,
            'trip_date' => $date->toDateString(),
            'departure_at' => $departureAt,
            'departure_local' => $date->toDateString().' '.$departureTime,
            /*
             * A snapshot, deliberately: a booked passenger's day keeps the terms she agreed to, so
             * the capacity is copied from the WEEKDAY rather than read through the offer.
             */
            'seats_total' => $weekday?->seatsFor($offer) ?? $offer->seats_total,
            'promised_arrival_at' => $weekday?->promised_arrival_time === null ? null : DepartureTimeCalculator::toUtc(
                $date->toDateString(),
                $weekday->promised_arrival_time,
                $schedule->timezone,
            ),
            'booking_deadline_at' => $this->bookingDeadline($date, $schedule->timezone),
        ]);

        // Not fillable: only a quote may price a day.
        $trip->forceFill($quote->toTripColumns());

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
