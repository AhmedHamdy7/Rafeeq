<?php

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SosEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The second half of Statistics (T03, T04): supply and demand, quality and safety, team
 * performance and money health. Same rules as {@see Statistics}: each figure is read from the
 * record that IS the fact, and every one is defined on the Definitions tab.
 */
final class StatisticsInsights
{
    /** Medians are taken over at most this many recent rows, so the page stays fast. */
    private const int SAMPLE = 2000;

    /** @return array{offers: array<string, int>, pairs: list<array<string, int|string|float|null>>} */
    public static function supply(StatisticsPeriod $period, StatisticsFilter $filter): array
    {
        $published = $filter->offers(CommuteOffer::query()->where('status', CommuteOfferStatus::Published->value));

        $waitlisted = SeatRequest::query()->where('status', SeatRequestStatus::Waitlisted->value)
            ->whereHas('commuteOffer', fn (Builder $offer) => $filter->offers($offer));

        $offers = [
            'published' => (clone $published)->count(),
            'seatsOffered' => (int) (clone $published)->sum('seats_total'),
            'unmatched' => CommuteDemand::query()->where('status', CommuteDemandStatus::Active->value)->count(),
            'waitlisted' => (clone $waitlisted)->distinct()->count('passenger_user_id'),
        ];

        // By area pair: where commutes run, how full they are, and how many wait for a seat.
        $commutes = (clone $published)->with(['locations.place'])->withCount(['seatRequests as waiting' => fn (Builder $q) => $q->where('status', SeatRequestStatus::Waitlisted->value)])->limit(1000)->get();

        $days = ScheduledTrip::query()
            ->toBase()
            ->whereIn('commute_offer_id', $commutes->pluck('id'))
            ->whereBetween('trip_date', [$period->firstDay(), $period->lastDay()])
            ->selectRaw('commute_offer_id, sum(seats_total) as offered, sum(seats_taken) as taken')
            ->groupBy('commute_offer_id')
            ->get()
            ->keyBy('commute_offer_id');

        $pairs = $commutes->groupBy(function (CommuteOffer $offer): string {
            $from = $offer->locations->firstWhere('type', CommuteLocationType::Origin)?->place?->district ?? '—';
            $to = $offer->locations->firstWhere('type', CommuteLocationType::Destination)?->place?->district ?? '—';

            return $from.' → '.$to;
        })->map(function (Collection $group, string $pair) use ($days): array {
            $offered = (int) $group->sum(fn (CommuteOffer $offer) => $days->get($offer->id)?->offered ?? 0);
            $taken = (int) $group->sum(fn (CommuteOffer $offer) => $days->get($offer->id)?->taken ?? 0);

            return [
                'pair' => $pair,
                'commutes' => $group->count(),
                'offered' => $offered,
                'taken' => $taken,
                'occupancy' => $offered > 0 ? round($taken / $offered * 100) : null,
                'waiting' => (int) $group->sum('waiting'),
            ];
        })->sortByDesc('commutes')->values()->take(15)->all();

        return ['offers' => $offers, 'pairs' => $pairs];
    }

    /** @return array<string, mixed> */
    public static function quality(StatisticsPeriod $period, StatisticsFilter $filter): array
    {
        $inPeriod = fn (Builder $trip) => $trip
            ->whereBetween('trip_date', [$period->firstDay(), $period->lastDay()])
            ->when($filter->isActive(), fn (Builder $q) => $q->whereHas('commuteOffer', fn (Builder $offer) => $filter->offers($offer)));

        $bookings = Booking::query()->whereHas('scheduledTrip', $inPeriod);
        $completed = (clone $bookings)->where('status', BookingStatus::Completed->value)->count();
        $noShows = (clone $bookings)->where('status', BookingStatus::NoShow->value)->count();
        $runs = $inPeriod(ScheduledTrip::query());

        $between = fn (Builder $query) => $query->where('created_at', '>=', $period->fromUtc())->where('created_at', '<', $period->untilUtc());

        $reports = $between(Incident::query());
        $completedRuns = (clone $runs)->where('status', ScheduledTripStatus::Completed->value)->count();
        $appeals = $between(ReliabilityEvent::query()->whereNotNull('appealed_at'));
        $appealsFiled = (clone $appeals)->count();

        return [
            'noShowRate' => $completed + $noShows > 0 ? round($noShows / ($completed + $noShows) * 100, 1) : null,
            'noShows' => $noShows,
            'runsCancelled' => (clone $runs)->where('status', ScheduledTripStatus::Cancelled->value)->count(),
            'avgDriver' => self::average('avg_rating_as_driver'),
            'avgPassenger' => self::average('avg_rating_as_passenger'),
            'reports' => (clone $reports)->count(),
            'reportsPer100' => $completedRuns > 0 ? round((clone $reports)->count() / $completedRuns * 100, 1) : null,
            'sos' => $between(SosEvent::query())->count(),
            'appealsFiled' => $appealsFiled,
            'appealsApproved' => $appealsFiled > 0 ? round((clone $appeals)->whereNotNull('reversed_at')->count() / $appealsFiled * 100) : null,
            'byCategory' => (clone $reports)->toBase()->selectRaw('category, count(*) as total')->groupBy('category')->orderByDesc('total')->pluck('total', 'category')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function team(StatisticsPeriod $period): array
    {
        $resolved = Incident::query()->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $period->fromUtc())->where('resolved_at', '<', $period->untilUtc())
            ->latest('resolved_at')->limit(self::SAMPLE)->get(['created_at', 'resolved_at', 'sla_due_at']);

        $payments = DebtPayment::query()->whereIn('status', [DebtPaymentStatus::Confirmed->value, DebtPaymentStatus::Rejected->value])
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $period->fromUtc())->where('decided_at', '<', $period->untilUtc())
            ->latest('decided_at')->limit(self::SAMPLE)->get(['created_at', 'decided_at']);

        $verifications = AdminAction::query()->whereIn('action', ['verification.approve', 'driver.approve'])
            ->where('created_at', '>=', $period->fromUtc())->where('created_at', '<', $period->untilUtc());

        $counts = AdminAction::query()->toBase()
            ->where('created_at', '>=', $period->fromUtc())->where('created_at', '<', $period->untilUtc())
            ->selectRaw("admin_id,
                sum(case when action in ('verification.approve', 'verification.request_info', 'driver.approve', 'driver.reject') then 1 else 0 end) as verifications,
                sum(case when action in ('incident.resolve', 'incident.close') then 1 else 0 end) as reports,
                sum(case when action in ('debt.payment_confirm', 'debt.payment_reject') then 1 else 0 end) as payments,
                sum(case when action = 'sos.acknowledge' then 1 else 0 end) as sos,
                count(*) as total")
            ->groupBy('admin_id')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        $admins = AdminUser::query()->whereIn('id', $counts->pluck('admin_id'))->with('roles')->get()->keyBy('id');

        return [
            'verifications' => (clone $verifications)->count(),
            'reportsOnTime' => $resolved->isEmpty() ? null : round($resolved->filter(fn (Incident $i) => $i->sla_due_at === null || $i->resolved_at->lte($i->sla_due_at))->count() / $resolved->count() * 100),
            'reportMedianHours' => self::medianHours($resolved->map(fn (Incident $i) => $i->created_at->diffInMinutes($i->resolved_at))),
            'paymentMedianHours' => self::medianHours($payments->map(fn (DebtPayment $p) => $p->created_at->diffInMinutes($p->decided_at))),
            'people' => $counts->map(fn ($row) => [
                'name' => $admins->get($row->admin_id)?->name ?? '—',
                'role' => $admins->get($row->admin_id)?->roles->first()?->name,
                'verifications' => (int) $row->verifications,
                'reports' => (int) $row->reports,
                'payments' => (int) $row->payments,
                'sos' => (int) $row->sos,
                'total' => (int) $row->total,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> today, whatever the period: debts are a standing, not a flow */
    public static function money(): array
    {
        $biggest = DebtAccount::query()->where('balance_piastres', '>', 0)->orderByDesc('balance_piastres')->limit(10)->get();
        $users = User::query()->whereIn('id', $biggest->pluck('user_id'))->get(['id', 'full_name'])->keyBy('id');

        $confirmed = DebtPayment::query()->where('status', DebtPaymentStatus::Confirmed->value)->whereNotNull('decided_at')
            ->latest('decided_at')->limit(self::SAMPLE)->get(['created_at', 'decided_at']);

        return [
            'driversBlocked' => DebtAccount::query()->where('side', DebtSide::Driver->value)->whereNotNull('suspended_at')->count(),
            'passengersSuspended' => DebtAccount::query()->where('side', DebtSide::Passenger->value)->whereNotNull('suspended_at')->count(),
            'owing' => DebtAccount::query()->where('balance_piastres', '>', 0)->count(),
            'paymentMedianHours' => self::medianHours($confirmed->map(fn (DebtPayment $p) => $p->created_at->diffInMinutes($p->decided_at))),
            'biggest' => $biggest->map(fn (DebtAccount $account) => [
                'userId' => $account->user_id,
                'name' => $users->get($account->user_id)?->full_name ?? '—',
                'side' => $account->side instanceof \BackedEnum ? $account->side->value : (string) $account->side,
                'owed' => (int) $account->balance_piastres,
                'suspended' => $account->suspended_at !== null,
            ])->all(),
        ];
    }

    private static function average(string $column): ?float
    {
        $value = UserStat::query()->whereNotNull($column)->avg($column);

        return $value === null ? null : round((float) $value, 2);
    }

    /** @param  Collection<int, int|float>  $minutes */
    private static function medianHours(Collection $minutes): ?float
    {
        if ($minutes->isEmpty()) {
            return null;
        }

        return round((float) $minutes->median() / 60, 1);
    }
}
