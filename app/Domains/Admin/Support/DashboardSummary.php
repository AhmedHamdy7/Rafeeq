<?php

namespace App\Domains\Admin\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Support\EscortCoverage;
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
                ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::UnderReview->value, IncidentStatus::Escalated->value])
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
}
