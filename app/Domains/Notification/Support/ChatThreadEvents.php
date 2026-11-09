<?php

namespace App\Domains\Notification\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Notification\Events\ChatThreadEventAdded;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ChatThreadEvents
{
    public const RequestSent = 'request_sent';

    public const WaitlistJoined = 'waitlist_joined';

    public const Counteroffered = 'counteroffered';

    public const BookingConfirmed = 'booking_confirmed';

    public const DriverArrived = 'driver_arrived';

    public const PickedUp = 'picked_up';

    public const RideCompleted = 'ride_completed';

    public static function forRequest(SeatRequest $request): array
    {
        return self::sorted([
            $request->isWaitlistApplication()
                ? self::item(self::WaitlistJoined, $request->created_at)
                : self::item(self::RequestSent, $request->created_at),
            self::item(self::Counteroffered, $request->counteroffered_at),
            $request->isWaitlistApplication() ? null : self::item(self::WaitlistJoined, $request->joinedWaitlistAt()),
        ]);
    }

    public static function forBooking(Booking $booking): array
    {
        $attendance = $booking->attendance;
        $rideEnded = in_array($booking->status, [BookingStatus::Completed, BookingStatus::EndedEarly], true);

        return self::sorted([
            self::item(self::BookingConfirmed, $booking->created_at),
            self::item(self::DriverArrived, $attendance?->driver_arrived_notified_at),
            self::item(self::PickedUp, $attendance?->checked_in_at),
            $rideEnded ? self::item(self::RideCompleted, $attendance?->checked_out_at) : null,
        ]);
    }

    public static function announce(Booking $booking, string $event, CarbonInterface $at): void
    {
        foreach ([$booking->passenger_user_id, $booking->driver_profile_id] as $recipientId) {
            ChatThreadEventAdded::dispatch($recipientId, $booking->id, self::item($event, $at));
        }
    }

    private static function item(string $event, mixed $at): ?array
    {
        if ($at === null) {
            return null;
        }

        return ['event' => $event, 'at' => CarbonImmutable::parse($at)->toIso8601String()];
    }

    private static function sorted(array $items): array
    {
        $items = array_values(array_filter($items));

        usort($items, fn (array $a, array $b): int => CarbonImmutable::parse($a['at'])->getTimestamp() <=> CarbonImmutable::parse($b['at'])->getTimestamp());

        return $items;
    }
}
