<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Trip\Actions\WaitTimerAction;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Throwable;

/**
 * "Your driver has arrived" — the one place it is said (Feature 03 files §8).
 *
 * 🔴 **One small method on purpose.** Today the driver's "I've arrived" for a passenger is starting
 * her wait timer ({@see WaitTimerAction::start()}), and that is where it is called from. The
 * arrival is being reworked to a per-passenger "arrived" checked within 150 m (T-1); whatever
 * records that arrival calls {@see self::send()} as well, and the passenger hears the same words
 * from either path.
 *
 * 🔒 **Once per booking**, stamped on her attendance row (`driver_arrived_notified_at`). A driver
 * who starts a second wait for the same person — or a reworked path that is retried — does not buzz
 * her phone again for an arrival she was already told about.
 *
 * Never throws: telling somebody the car is here must not be able to fail the arrival itself.
 */
final class DriverArrivalNotice
{
    /**
     * @return bool whether a notice was sent this time
     */
    public static function send(TripSession $session, Booking $booking): bool
    {
        try {
            return self::tell($session, $booking);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function tell(TripSession $session, Booking $booking): bool
    {
        $passenger = User::query()->find($booking->passenger_user_id);

        if ($passenger === null) {
            return false;
        }

        // Claim the notice atomically: of two concurrent calls, exactly one updates the row.
        $claimed = Attendance::query()
            ->whereKey($booking->getKey())
            ->whereNull('driver_arrived_notified_at')
            ->update(['driver_arrived_notified_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $driver = User::query()->find($booking->driver_profile_id);

        Notifier::send($passenger, NotificationType::DriverArrived, params: [
            'name' => $driver?->public_first_name ?? '',
        ], data: [
            'bookingId' => (string) $booking->getKey(),
            'tripId' => (string) $session->scheduled_trip_id,
        ]);

        return true;
    }
}
