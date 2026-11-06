<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Support\CommuteDefaults;
use App\Domains\Commute\Support\ComputedDeparture;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * When a commute runs (Chapter 4 §4).
 *
 * The departure time is stored as a LOCAL wall clock with its timezone beside
 * it, and never as a UTC instant. A driver saying "07:05" means the time on
 * their clock, which is a different moment in UTC in April than in December —
 * Egypt observes DST by law. Storing a converted instant here would make every
 * trip after a transition depart an hour wrong.
 *
 * A one-time commute uses the same shape: one day in the mask, start and end on
 * the same date. That keeps every consumer — generation, search, booking — free
 * of a special case.
 */
final readonly class SaveCommuteScheduleAction
{
    public function __construct(private ComputedDeparture $departure) {}

    public function execute(CommuteOffer $offer, array $input): CommuteOffer
    {
        CommuteState::assertEditable($offer);

        [$startDate, $endDate] = $this->window($offer, $input);

        $daysMask = $offer->commute_type === CommuteType::OneTime
            ? DaysMask::bitForDate($startDate)
            : (int) ($input['daysMask'] ?? 0);

        $this->assertUsable($offer, $startDate, $endDate, $daysMask);
        $this->assertDaySeatsFitVehicle($offer, $input);

        return DB::transaction(function () use ($offer, $input, $startDate, $endDate, $daysMask): CommuteOffer {
            // Replaced, not patched: a schedule is one thing, and a half-updated
            // one could describe days outside its own date range.
            $offer->schedule()->delete();

            $schedule = new CommuteSchedule;

            $schedule->fill([
                'commute_offer_id' => $offer->id,
                'days_mask' => $daysMask,
                // Provisional for an arrival-only schedule: rewritten from the weekdays below.
                'departure_time' => $input['departureTime'] ?? $input['promisedArrivalTime'] ?? '00:00:00',
                'timezone' => $input['timezone'] ?? 'Africa/Cairo',
                'start_date' => $startDate->toDateString(),
                // A one-time commute ends the day it happens.
                'end_date' => ($offer->commute_type === CommuteType::OneTime ? $startDate : $endDate)->toDateString(),
            ]);

            $schedule->save();

            $this->saveWeekdays($offer, $daysMask, $input);

            return $offer->load(['schedule', 'weekdaySchedules']);
        });
    }

    /**
     * Write one row per weekday, and make the parent's derived columns agree with them.
     *
     * 🔴 This method is the only writer of `commute_schedules.days_mask` and `departure_time`.
     * Feature 03 moves the per-day facts into their own table, but search compares the mask inside
     * the query and must not join to do it — so the mask stays on the parent as a cache, and the
     * way to keep a cache honest is to give it exactly one owner.
     *
     * `perDay` is optional and sparse. A driver who leaves at the same time every day sends
     * nothing, gets the single `departureTime` on every weekday, and inherits the commute's
     * capacity — which is precisely how this behaved before the table existed. There is no
     * per-day price: the seat price is automatic (P-1, PAY-42).
     *
     * @param  array<string, mixed>  $input
     */
    private function saveWeekdays(CommuteOffer $offer, int $daysMask, array $input): void
    {
        // Replaced, not patched, for the same reason as the schedule itself: a half-updated set
        // could describe a weekday the mask no longer contains.
        $offer->weekdaySchedules()->delete();

        /** @var array<int|string, array<string, mixed>> $perDay */
        $perDay = $input['perDay'] ?? [];

        $bits = DaysMask::fromBits($daysMask)->bits();

        foreach ($bits as $bit) {
            $day = $perDay[$bit] ?? $perDay[(string) $bit] ?? [];

            $weekday = new CommuteWeekdaySchedule;

            $weekday->commute_offer_id = $offer->id;
            $weekday->weekday_bit = $bit;
            $weekday->promised_arrival_time = $day['promisedArrivalTime'] ?? ($input['promisedArrivalTime'] ?? null);

            if ($weekday->promised_arrival_time === null) {
                throw DomainException::of(ErrorCode::CommuteArrivalRequired, fields: [
                    "perDay.{$bit}.promisedArrivalTime" => [__('validation.required', ['attribute' => 'promisedArrivalTime'])],
                ]);
            }

            $weekday->departure_is_computed = true;
            $weekday->departure_time = $this->estimatedDeparture($offer, (string) $weekday->promised_arrival_time, (string) ($offer->schedule()->value('timezone') ?? 'Africa/Cairo'));
            // Null, not a copy of the parent: null means "follow the commute", so a later change
            // reaches every day he did not deliberately set differently.
            $weekday->seats_total = $day['seatsTotal'] ?? null;

            $weekday->save();
        }

        /*
         * The derived columns, recomputed from what was just written rather than from the input —
         * so they describe the rows that exist, even if the input and the rows ever disagree.
         *
         * `departure_time` becomes the EARLIEST departure across the week. It is used for display
         * and ordering, and the earliest is the honest single answer to "when does this leave":
         * it is the time a passenger has to be ready by on her earliest day.
         */
        $saved = $offer->weekdaySchedules()->get();

        $offer->schedule()->update([
            'days_mask' => DaysMask::fromBitList($saved->pluck('weekday_bit')->all())->value,
            'departure_time' => $saved->min('departure_time'),
        ]);

        $offer->touch();
    }

    public function refreshEstimates(CommuteOffer $offer): void
    {
        $days = $offer->weekdaySchedules()
            ->where('departure_is_computed', true)
            ->whereNotNull('promised_arrival_time')
            ->get();

        if ($days->isEmpty()) {
            return;
        }

        $timezone = $offer->schedule()->value('timezone') ?? 'Africa/Cairo';
        $estimates = [];

        foreach ($days as $day) {
            $arrival = (string) $day->promised_arrival_time;
            $estimates[$arrival] ??= $this->estimatedDeparture($offer, $arrival, $timezone);
            $day->departure_time = $estimates[$arrival];
            $day->save();
        }

        $offer->schedule()->update(['departure_time' => $offer->weekdaySchedules()->min('departure_time')]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(CommuteOffer $offer, array $input): array
    {
        $saved = $offer->schedule()->first();
        $hasUsableWindow = $saved !== null && ! $saved->start_date->isPast();

        $start = CarbonImmutable::parse(
            $input['startDate'] ?? ($hasUsableWindow ? $saved->start_date->toDateString() : CommuteDefaults::startDate()),
        );

        $end = CarbonImmutable::parse(
            $input['endDate'] ?? ($hasUsableWindow ? $saved->end_date->toDateString() : CommuteDefaults::endDate($start)),
        );

        return [$start, $end];
    }

    /**
     * The departure an arrival implies on the route as saved, as a local wall clock. Before a
     * route exists there is nothing to time, so the arrival itself stands in until publishing
     * generates the real days.
     */
    private function estimatedDeparture(CommuteOffer $offer, string $arrival, string $timezone): string
    {
        $offer->loadMissing('locations');

        // Tomorrow's arrival: a provider will not time a journey in the past.
        $arriveBy = DepartureTimeCalculator::toUtc(
            CarbonImmutable::tomorrow($timezone)->toDateString(),
            $arrival,
            $timezone,
        );

        return $this->departure->arrivingBy($offer, $arriveBy)
            ?->setTimezone($timezone)->format('H:i:s') ?? $arrival;
    }

    /**
     * C-3: a weekday that offers its own seat count still cannot offer more than the car's
     * approved seats. Checked before anything is written, like the rest of the schedule.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertDaySeatsFitVehicle(CommuteOffer $offer, array $input): void
    {
        $offer->loadMissing('vehicle');

        if ($offer->vehicle?->approved_seats === null) {
            return;
        }

        foreach ((array) ($input['perDay'] ?? []) as $day) {
            if (isset($day['seatsTotal'])) {
                CreateCommuteOfferAction::assertSeatsFitVehicle($offer->vehicle, (int) $day['seatsTotal']);
            }
        }
    }

    private function assertUsable(
        CommuteOffer $offer,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        int $daysMask,
    ): void {
        // §4: "start date cannot be in the past". Compared as a local day, since
        // that is what the driver picked on a calendar.
        if ($startDate->startOfDay()->lessThan(CarbonImmutable::today())) {
            throw DomainException::of(ErrorCode::CommuteIncomplete, fields: [
                'startDate' => [__('commute.schedule.start_in_past')],
            ]);
        }

        if ($daysMask === 0) {
            throw DomainException::of(ErrorCode::CommuteIncomplete, fields: [
                'daysMask' => [__('commute.schedule.no_days_selected')],
            ]);
        }

        // §4: "no infinite commutes" — an end date is mandatory, and it is
        // bounded, so a driver cannot publish something they will never revisit.
        $months = (int) config('rafeeq.commute.max_schedule_months');

        if ($offer->commute_type !== CommuteType::OneTime
            && $endDate->greaterThan($startDate->addMonths($months))) {
            throw DomainException::of(ErrorCode::CommuteIncomplete, fields: [
                'endDate' => [__('commute.schedule.too_far_ahead', ['months' => $months])],
            ]);
        }

        // The database enforces this too; answering here gives the driver a
        // message instead of a constraint violation.
        if ($endDate->lessThan($startDate)) {
            throw DomainException::of(ErrorCode::CommuteIncomplete, fields: [
                'endDate' => [__('commute.schedule.too_far_ahead', ['months' => $months])],
            ]);
        }
    }
}
