<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
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
    public function execute(CommuteOffer $offer, array $input): CommuteOffer
    {
        CommuteState::assertEditable($offer);

        $startDate = CarbonImmutable::parse($input['startDate']);
        $endDate = CarbonImmutable::parse($input['endDate']);

        $daysMask = $offer->commute_type === CommuteType::OneTime
            ? DaysMask::bitForDate($startDate)
            : (int) $input['daysMask'];

        $this->assertUsable($offer, $startDate, $endDate, $daysMask);

        return DB::transaction(function () use ($offer, $input, $startDate, $endDate, $daysMask): CommuteOffer {
            // Replaced, not patched: a schedule is one thing, and a half-updated
            // one could describe days outside its own date range.
            $offer->schedule()->delete();

            $schedule = new CommuteSchedule;

            $schedule->fill([
                'commute_offer_id' => $offer->id,
                'days_mask' => $daysMask,
                'departure_time' => $input['departureTime'],
                'timezone' => $input['timezone'] ?? 'Africa/Cairo',
                'start_date' => $startDate->toDateString(),
                // A one-time commute ends the day it happens.
                'end_date' => ($offer->commute_type === CommuteType::OneTime ? $startDate : $endDate)->toDateString(),
            ]);

            $schedule->save();

            return $offer->load('schedule');
        });
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
