<?php

namespace App\Domains\Admin\Support;

use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SosEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the safety desk has in front of it: live alerts, then open reports.
 *
 * One class for the page AND the rail badge AND the banner, for the same reason as
 * `VerificationQueue`: three places counting "open" three slightly different ways is how
 * a badge says 2 while the page shows 3.
 */
final class SafetyCaseQueue
{
    /**
     * Every SOS nobody has closed and the member has not taken back.
     *
     * 🔴 NOT paginated, and that is a decision rather than an oversight. A page of ten
     * means the eleventh alert is on a page nobody opened. Live alerts are few by nature;
     * if they ever are not, a longer page is the right failure and a hidden one is not.
     *
     * Ones nobody has picked up come first, oldest first: the alert that has waited
     * longest without a human is the one most likely to be going badly.
     *
     * @return Collection<int, SosEvent>
     */
    public static function liveAlerts(): Collection
    {
        return self::liveAlertQuery()
            ->with([
                'safetyEvent.user',
                'safetyEvent.tripSession.scheduledTrip.commuteOffer.vehicle',
                'safetyEvent.tripSession.scheduledTrip.commuteOffer.driverProfile.user',
                'responderAdmin',
            ])
            ->orderByRaw('first_touch_at IS NOT NULL')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Open reports, soonest deadline first.
     *
     * Ordered by `sla_due_at` and nothing else, because the deadline already IS the
     * priority: it was written from the severity when the report was filed (one hour for
     * harassment, three days for a lost item). Sorting by severity first would put a
     * critical report filed a minute ago ahead of a high one that went overdue an hour
     * ago — and the overdue one is the promise already being broken.
     *
     * @return LengthAwarePaginator<int, Incident>
     */
    public static function openReports(int $perPage): LengthAwarePaginator
    {
        return self::openReportQuery()
            ->with(['reporter', 'reportedUser', 'assignedAdmin'])
            ->withCount('evidence')
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public static function liveAlertCount(): int
    {
        return self::liveAlertQuery()->count();
    }

    /**
     * The alert that has gone longest with nobody on it, for the banner every admin page
     * shows. `null` when every live alert has a human.
     */
    public static function oldestUnacknowledged(): ?SosEvent
    {
        return self::liveAlertQuery()
            ->whereNull('first_touch_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    public static function openReportCount(): int
    {
        return self::openReportQuery()->count();
    }

    /**
     * @return Builder<SosEvent>
     */
    private static function liveAlertQuery(): Builder
    {
        return SosEvent::query()
            ->whereNull('cancelled_at')
            ->whereNull('resolution');
    }

    /**
     * @return Builder<Incident>
     */
    private static function openReportQuery(): Builder
    {
        return Incident::query()->whereIn('status', [
            IncidentStatus::Open->value,
            IncidentStatus::UnderReview->value,
            IncidentStatus::Escalated->value,
        ]);
    }
}
