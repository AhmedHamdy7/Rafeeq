<?php

namespace App\Domains\Admin\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The MEMBERS section: find a person, see where they stand.
 *
 * 🔒 Searching and showing are deliberately not symmetrical for phone numbers. A full number
 * typed by staff finds the account (support's first question is "which account is calling
 * me"), but the list never prints a number back — it shows `+20 10 *** 5678`. A screen of
 * full numbers is a contact list of every member, and dashboards get photographed and left
 * open; a number somebody already has costs nothing to confirm.
 *
 * The tabs are the prototype's four (All · Drivers · Riders · Flagged) plus Suspended,
 * which is the queue of promises: every hold told a member when they would hear back.
 */
final class MemberDirectory
{
    public const array TABS = ['all', 'drivers', 'riders', 'flagged', 'suspended'];

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public static function page(string $tab, string $search, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            // A deleted account is gone from the platform; it is not somebody to manage.
            ->where('account_status', '!=', AccountStatus::Deleted->value)
            ->with(['driverProfile', 'stats', 'activeSuspension'])
            ->withCount(['reportsAbout as open_reports_count' => fn (Builder $reports) => self::open($reports)]);

        self::search($query, trim($search));
        self::tab($query, $tab);

        if ($tab === 'suspended') {
            // Oldest promise first: the hold whose review is most overdue leads.
            $query->orderBy(
                AccountSuspension::query()
                    ->select('review_due_at')
                    ->whereColumn('account_suspensions.user_id', 'users.id')
                    ->whereNull('lifted_at')
                    ->orderByDesc('suspended_at')
                    ->limit(1)
            );
        } else {
            $query->latest('created_at');
        }

        return $query->orderBy('id')->paginate($perPage);
    }

    public static function overdueHolds(): int
    {
        return AccountSuspension::query()
            ->inForce()
            ->where('review_due_at', '<', now())
            ->count();
    }

    /**
     * Upcoming days a driver has passengers booked on — what a hold on this person leaves
     * stranded, shown to staff BEFORE they confirm (see SuspendMemberAction).
     */
    public static function bookedDaysAsDriver(User $member): int
    {
        return Booking::query()
            ->where('driver_profile_id', $member->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereHas('scheduledTrip', fn (Builder $trip) => $trip->where('departure_at', '>', now()))
            ->distinct()
            ->count('scheduled_trip_id');
    }

    /**
     * A full Egyptian mobile number finds that account exactly; anything else is a name.
     */
    private static function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        try {
            $phone = PhoneNumber::fromRaw($search);

            $query->where('phone_e164', $phone->e164);

            return;
        } catch (InvalidArgumentException) {
            // Not a phone number — search names.
        }

        $query->where('full_name', 'like', '%'.addcslashes($search, '%_\\').'%');
    }

    private static function tab(Builder $query, string $tab): void
    {
        $approvedDriver = fn (Builder $profile) => $profile->where('status', DriverProfileStatus::Approved->value);

        match ($tab) {
            'drivers' => $query->whereHas('driverProfile', $approvedDriver),
            'riders' => $query->whereDoesntHave('driverProfile', $approvedDriver),
            // Somebody a report is open about — the prototype's "Flagged".
            'flagged' => $query->whereHas('reportsAbout', fn (Builder $reports) => self::open($reports)),
            'suspended' => $query->where('account_status', AccountStatus::Suspended->value),
            default => null,
        };
    }

    private static function open(Builder $reports): void
    {
        $reports->whereIn('status', [
            IncidentStatus::Open->value,
            IncidentStatus::UnderReview->value,
            IncidentStatus::Escalated->value,
        ]);
    }
}
