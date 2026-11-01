<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Your driver is heading to you" (Feature 03 files §8, T-3).
 *
 * Sent to the next passenger in route order the moment the driver finishes the pickup before
 * hers — she collected that passenger, or recorded her as a no-show. Both count: either way the
 * car is now on its way to the next kerb, and the woman standing at it is the one who needs to
 * know.
 *
 * 🔴 **Route order is the itinerary's**, the same ordered stops the driver's navigation screen is
 * drawn from ({@see BuildNavigationItineraryAction::forTrip()}), so "next" here is never a
 * different person from the one the driver's own screen is pointing her at. The next pickup is the
 * first one AFTER the stop just finished whose attendance nobody has decided yet; when the stop
 * just finished is no longer on the itinerary (a no-show can take a booking off it), it is the
 * first undecided pickup on the route.
 *
 * 🔒 **Once per passenger**, claimed on her attendance row (`heading_to_you_notified_at`). A driver
 * who decides pickups out of order would otherwise tell the same woman twice.
 *
 * Called AFTER the check-in has committed, never inside it: reading the itinerary may rebuild it,
 * and a rebuild asks the routing provider — which must never happen while a row lock is held. And
 * a failure here is reported and swallowed, because a notification must never undo a check-in.
 */
final readonly class NotifyNextPickupAction
{
    public function __construct(private BuildNavigationItineraryAction $itinerary) {}

    /**
     * @return string|null the booking id that was told, if anybody was
     */
    public function after(TripSession $session, Booking $finished): ?string
    {
        try {
            return $this->notify($session, $finished);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function notify(TripSession $session, Booking $finished): ?string
    {
        $trip = ScheduledTrip::query()->find($session->scheduled_trip_id);

        if ($trip === null) {
            return null;
        }

        $pickups = $this->itinerary->forTrip($trip)
            ->filter(fn (NavigationStop $stop): bool => $stop->kind === NavigationStopKind::PassengerPickup
                && $stop->booking_id !== null)
            ->values();

        $next = $this->nextUndecided($pickups, (string) $finished->getKey());

        if ($next === null) {
            return null;
        }

        $claimed = Attendance::query()
            ->whereKey($next)
            ->whereNull('heading_to_you_notified_at')
            ->update(['heading_to_you_notified_at' => now()]);

        if ($claimed === 0) {
            return null;
        }

        $booking = Booking::query()->find($next);
        $passenger = $booking === null ? null : User::query()->find($booking->passenger_user_id);

        if ($passenger === null) {
            return null;
        }

        $driver = User::query()->find($booking->driver_profile_id);

        Notifier::send($passenger, NotificationType::DriverHeadingToYou, params: [
            'name' => $driver?->public_first_name ?? '',
        ], data: [
            'bookingId' => (string) $booking->getKey(),
            'tripId' => (string) $trip->getKey(),
        ]);

        return (string) $booking->getKey();
    }

    /**
     * @param  Collection<int, NavigationStop>  $pickups  in route order
     */
    private function nextUndecided(Collection $pickups, string $finishedBookingId): ?string
    {
        $ids = $pickups->pluck('booking_id')->map(fn ($id): string => (string) $id)->all();

        $position = array_search($finishedBookingId, $ids, true);

        $candidates = $position === false ? $ids : array_slice($ids, $position + 1);

        if ($candidates === []) {
            return null;
        }

        $pending = Attendance::query()
            ->whereIn('booking_id', $candidates)
            ->where('status', AttendanceStatus::Pending->value)
            ->pluck('booking_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $pending, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
