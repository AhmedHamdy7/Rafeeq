<?php

namespace App\Domains\Admin\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\SettlementWeek;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers on the Statistics page (T01–T05). Every figure is read from the record that
 * IS the fact — money from the ledger, seats from completed bookings, runs from trip days —
 * never from a second count that could drift from it. Amounts are piastres.
 *
 * Definitions (shown on the page's Definitions tab, and the same words everywhere):
 * - Commission earned: commission and late-fee lines added to drivers' ledgers.
 * - Received: payment lines (InstaPay confirmed) — money in, on either side.
 * - Owed now: the sum of every positive running balance, today, whatever the period.
 * - Fees recorded: no-show, late-cancel and other fees — a deterrent, not planned income.
 * - Gross booking value: the frozen price of every booking completed on a day in the period.
 */
final class Statistics
{
    /** @return array<string, int> */
    public static function revenue(StatisticsPeriod $period): array
    {
        $sum = fn (array $types) => (int) DebtEntry::query()
            ->whereIn('type', array_map(fn (DebtEntryType $type) => $type->value, $types))
            ->where('created_at', '>=', $period->fromUtc())
            ->where('created_at', '<', $period->untilUtc())
            ->sum('amount_piastres');

        $pending = DebtPayment::query()->where('status', DebtPaymentStatus::PendingConfirmation->value);

        return [
            'commission' => $sum([DebtEntryType::Commission]),
            'lateFees' => $sum([DebtEntryType::LateFee]),
            'received' => -1 * $sum([DebtEntryType::Payment]),
            'fees' => $sum([DebtEntryType::Fee]),
            'given' => -1 * $sum([DebtEntryType::Coupon, DebtEntryType::Credit]),
            'writtenOff' => -1 * $sum([DebtEntryType::WriteOff]),
            'waiting' => (int) (clone $pending)->sum('amount_piastres'),
            'waitingCount' => (clone $pending)->count(),
            'owedNow' => (int) DebtAccount::query()->where('balance_piastres', '>', 0)->sum('balance_piastres'),
        ];
    }

    /**
     * Commission earned and money received per settlement week, oldest first, for the chart.
     *
     * @return list<array{week: string, earned: int, received: int}>
     */
    public static function weeklyMoney(int $weeks = 6): array
    {
        $current = SettlementWeek::current();
        $first = SettlementWeek::shift($current, -($weeks - 1));

        $rows = DebtEntry::query()
            ->toBase()
            ->where('week_of', '>=', $first)
            ->whereIn('type', [DebtEntryType::Commission->value, DebtEntryType::LateFee->value, DebtEntryType::Payment->value])
            ->selectRaw('week_of, type, sum(amount_piastres) as total')
            ->groupBy('week_of', 'type')
            ->get();

        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $week = SettlementWeek::shift($first, $i);
            $of = $rows->filter(fn ($row) => substr((string) $row->week_of, 0, 10) === $week);

            $out[] = [
                'week' => $week,
                'earned' => (int) $of->whereIn('type', [DebtEntryType::Commission->value, DebtEntryType::LateFee->value])->sum('total'),
                'received' => -1 * (int) $of->where('type', DebtEntryType::Payment->value)->sum('total'),
            ];
        }

        return $out;
    }

    /** @return array<string, int|float|null> */
    public static function trips(StatisticsPeriod $period, ?StatisticsFilter $filter = null): array
    {
        $filter ??= new StatisticsFilter;
        $inPeriod = fn (Builder $trip) => $trip
            ->whereBetween('trip_date', [$period->firstDay(), $period->lastDay()])
            ->when($filter->isActive(), fn (Builder $q) => $q->whereHas('commuteOffer', fn (Builder $offer) => $filter->offers($offer)));

        $completedBookings = Booking::query()
            ->where('status', BookingStatus::Completed->value)
            ->whereHas('scheduledTrip', $inPeriod);

        $days = $inPeriod(ScheduledTrip::query());
        $completedDays = (clone $days)->where('status', ScheduledTripStatus::Completed->value);

        $offered = (int) (clone $completedDays)->sum('seats_total');
        $taken = (int) (clone $completedDays)->sum('seats_taken');

        return [
            'grossValue' => (int) (clone $completedBookings)->sum('price_snapshot_piastres'),
            'driverEarnings' => (int) (clone $completedBookings)->sum('driver_amount_snapshot_piastres'),
            'platformShare' => (int) (clone $completedBookings)->sum('platform_fee_snapshot_piastres'),
            'seatsCompleted' => (int) (clone $completedBookings)->sum('seats_reserved'),
            'daysCompleted' => (clone $completedDays)->count(),
            'daysCancelled' => (clone $days)->where('status', ScheduledTripStatus::Cancelled->value)->count(),
            'noShows' => Booking::query()
                ->where('status', BookingStatus::NoShow->value)
                ->whereHas('scheduledTrip', $inPeriod)
                ->count(),
            'occupancy' => $offered > 0 ? round($taken / $offered * 100, 1) : null,
        ];
    }

    /**
     * Seats on completed bookings per Cairo day of the period, every day present (zero when
     * nothing ran), so the chart's gaps are real gaps.
     *
     * @return list<array{day: string, seats: int}>
     */
    public static function seatsPerDay(StatisticsPeriod $period, ?StatisticsFilter $filter = null): array
    {
        $filter ??= new StatisticsFilter;

        $rows = Booking::query()
            ->toBase()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->where('bookings.status', BookingStatus::Completed->value)
            ->whereBetween('scheduled_trips.trip_date', [$period->firstDay(), $period->lastDay()])
            ->when($filter->isActive(), fn ($query) => $query->whereIn('scheduled_trips.commute_offer_id', $filter->offers(CommuteOffer::query())->select('id')))
            ->selectRaw('scheduled_trips.trip_date as day, sum(bookings.seats_reserved) as seats')
            ->groupBy('scheduled_trips.trip_date')
            ->pluck('seats', 'day');

        $byDay = [];
        foreach ($rows as $day => $seats) {
            $byDay[substr((string) $day, 0, 10)] = (int) $seats;
        }

        $out = [];
        for ($day = $period->from; $day->lt($period->until); $day = $day->addDay()) {
            $out[] = ['day' => $day->toDateString(), 'seats' => $byDay[$day->toDateString()] ?? 0];
        }

        return $out;
    }

    /** @return array<string, int> */
    public static function growth(StatisticsPeriod $period): array
    {
        $between = fn (Builder $query, string $column = 'created_at') => $query
            ->where($column, '>=', $period->fromUtc())
            ->where($column, '<', $period->untilUtc());

        return [
            'newMembers' => $between(User::query())->count(),
            'membersTotal' => User::query()->count(),
            'newDrivers' => $between(DriverProfile::query()->where('status', DriverProfileStatus::Approved->value), 'updated_at')->count(),
            'driversTotal' => DriverProfile::query()->where('status', DriverProfileStatus::Approved->value)->count(),
            'newCommutes' => $between(CommuteOffer::query()->whereNotNull('published_at'), 'published_at')->count(),
            'commutesLive' => CommuteOffer::query()->where('status', CommuteOfferStatus::Published->value)->count(),
        ];
    }

    /**
     * New members per settlement week, oldest first.
     *
     * @return list<array{week: string, members: int}>
     */
    public static function weeklySignUps(int $weeks = 8): array
    {
        $first = SettlementWeek::shift(SettlementWeek::current(), -($weeks - 1));
        $out = [];

        for ($i = 0; $i < $weeks; $i++) {
            $week = SettlementWeek::shift($first, $i);
            $from = CarbonImmutable::parse($week, SettlementWeek::TIMEZONE);

            $out[] = [
                'week' => $week,
                'members' => User::query()->where('created_at', '>=', $from->utc())->where('created_at', '<', $from->addWeek()->utc())->count(),
            ];
        }

        return $out;
    }

    /** Change from the previous period, in percent, or null when there is nothing to compare with. */
    public static function change(int|float $now, int|float $before): ?float
    {
        return $before == 0 ? null : round(($now - $before) / abs($before) * 100, 1);
    }
}
