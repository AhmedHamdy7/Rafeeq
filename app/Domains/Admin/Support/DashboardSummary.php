<?php

namespace App\Domains\Admin\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Support\EscortCoverage;
use App\Domains\Trip\Models\MemberWarning;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\UserVerification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers on the DASHBOARD's tiles.
 *
 * 🔴 Every tile counts the same way as the page it links to, through the same query classes
 * where one exists (`LiveTripBoard`, `SafetyCaseQueue`, `MemberDirectory`). A dashboard that
 * says 3 open cases above a safety page that lists 4 teaches staff to stop trusting the
 * dashboard, and then it is decoration.
 *
 * Nothing here is a trend line or a forecast. Chapter 13's analytics (demand by day,
 * occupancy, revenue) are Phase 14 and need an event pipeline that does not exist yet; the
 * prototype's "+11% vs last Sunday" would have to be invented today, so it is not shown.
 */
final class DashboardSummary
{
    /**
     * Runs on the road, split the way the prototype's tile is ("32 en route · 6 at pickup").
     *
     * @return array{total: int, byStatus: array<string, int>}
     */
    public static function trips(): array
    {
        $byStatus = TripSession::query()
            ->whereIn('current_status', array_map(fn ($status) => $status->value, LiveTripBoard::liveStatuses()))
            ->selectRaw('current_status, count(*) as aggregate')
            ->groupBy('current_status')
            ->pluck('aggregate', 'current_status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return ['total' => array_sum($byStatus), 'byStatus' => $byStatus];
    }

    /**
     * Seats taken on today's journeys — the LOCAL today, since a commute's day is Cairo's.
     */
    public static function seatsToday(): int
    {
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();

        return (int) Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->whereHas('scheduledTrip', fn (Builder $trip) => $trip->where('trip_date', $today))
            ->sum('seats_reserved');
    }

    /**
     * @return array{count: int, oldestSince: \DateTimeInterface|null}
     */
    public static function verifications(): array
    {
        $pending = UserVerification::query()->where('status', VerificationStatus::Pending->value);

        return [
            'count' => (clone $pending)->count(),
            'oldestSince' => (clone $pending)->min('submitted_at') === null
                ? null
                : CarbonImmutable::parse((clone $pending)->min('submitted_at')),
        ];
    }

    public static function driverApplications(): int
    {
        return DriverProfile::query()->where('status', DriverProfileStatus::PendingReview->value)->count();
    }

    /**
     * @return array{alerts: int, reports: int, criticalUnassigned: int}
     */
    public static function safety(): array
    {
        return [
            'alerts' => SafetyCaseQueue::liveAlertCount(),
            'reports' => SafetyCaseQueue::openReportCount(),
            // The prototype's sub-line ("1 critical, unassigned") — the number that should
            // make somebody stand up.
            'criticalUnassigned' => Incident::query()
                ->whereIn('status', IncidentStatus::openValues())
                ->where('severity', SafetySeverity::Critical->value)
                ->whereNull('assigned_admin_id')
                ->count(),
        ];
    }

    /**
     * @return array{armed: int, tripsCovered: int}
     */
    public static function escort(): array
    {
        return [
            'armed' => EscortCoverage::armedCount(),
            'tripsCovered' => EscortCoverage::tripsCoveredTonight(),
        ];
    }

    public static function overdueHolds(): int
    {
        return MemberDirectory::overdueHolds();
    }

    /**
     * Today's runs (Cairo's today), as the Home page's "Today's trip days" card counts them:
     * still to happen (scheduled, preparing, on the road), completed, and called off.
     *
     * @return array{scheduled: int, completed: int, cancelled: int}
     */
    public static function tripDays(): array
    {
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();

        // toBase(): the status column is cast to an enum, which cannot be an array key.
        $byStatus = ScheduledTrip::query()
            ->toBase()
            ->where('trip_date', $today)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $count = fn (ScheduledTripStatus ...$statuses): int => (int) collect($statuses)->sum(fn ($status) => $byStatus[$status->value] ?? 0);

        return [
            'scheduled' => $count(ScheduledTripStatus::Scheduled, ScheduledTripStatus::Preparing, ScheduledTripStatus::EnRoute, ScheduledTripStatus::InProgress),
            'completed' => $count(ScheduledTripStatus::Completed),
            'cancelled' => $count(ScheduledTripStatus::Cancelled, ScheduledTripStatus::Expired),
        ];
    }

    /**
     * InstaPay transfers a member reported and nobody has confirmed yet — the same rows, in
     * the same order, as the Payments page's first table.
     *
     * @return array{count: int, oldestSince: \DateTimeInterface|null}
     */
    public static function paymentsToConfirm(): array
    {
        $pending = DebtPayment::query()->where('status', DebtPaymentStatus::PendingConfirmation->value);
        $oldest = (clone $pending)->min('created_at');

        return [
            'count' => (clone $pending)->count(),
            'oldestSince' => $oldest === null ? null : CarbonImmutable::parse($oldest),
        ];
    }

    /**
     * Appeals waiting for a decision. Money appeals (a driver's fee, a passenger's warning)
     * are decided on the Payments page; suspension appeals on Users. Each half is counted
     * only for staff who may open the page that decides it.
     *
     * @return array{count: int, oldestSince: \DateTimeInterface|null}
     */
    public static function appeals(bool $money, bool $suspensions): array
    {
        $dates = [];
        $count = 0;

        if ($money) {
            $fees = FeeAppeal::query()->whereNull('resolution');
            $warnings = MemberWarning::query()->whereNotNull('disputed_at')->whereNull('dispute_resolution');

            $count += (clone $fees)->count() + (clone $warnings)->count();
            $dates[] = (clone $fees)->min('created_at');
            $dates[] = (clone $warnings)->min('disputed_at');
        }

        if ($suspensions) {
            $open = SuspensionAppeal::query()->whereNull('resolved_at');

            $count += (clone $open)->count();
            $dates[] = (clone $open)->min('created_at');
        }

        $dates = array_filter($dates);

        return [
            'count' => $count,
            'oldestSince' => $dates === [] ? null : CarbonImmutable::parse(min($dates)),
        ];
    }
}
