<?php

namespace App\Domains\Identity\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;

final class BannedRide
{
    private const array UNDERWAY = [
        ScheduledTripStatus::Preparing,
        ScheduledTripStatus::EnRoute,
        ScheduledTripStatus::InProgress,
    ];

    private const array HOME_ROUTES = [
        'GET v1/home',
        'GET v1/driver/home',
    ];

    private const array TRIP_ROUTES = [
        'GET v1/trips/{trip}',
        'POST v1/trips/{trip}/status',
        'POST v1/trips/{trip}/complete',
        'POST v1/trips/{trip}/still-driving',
        'POST v1/trips/{trip}/cancel-active',
        'GET v1/trips/{trip}/attendance',
        'POST v1/trips/{trip}/check-in',
        'POST v1/trips/{trip}/no-show',
        'POST v1/trips/{trip}/drop-off',
        'GET v1/trips/{trip}/wait-timers',
        'POST v1/trips/{trip}/wait-timers',
        'GET v1/trips/{trip}/location',
        'POST v1/trips/{trip}/location',
        'GET v1/driver/commute-occurrences/{trip}/navigation-route',
    ];

    private const array BOOKING_ROUTES = [
        'GET v1/bookings/{booking}',
        'POST v1/bookings/{booking}/end-early',
        'POST v1/bookings/{booking}/early-drop',
        'GET v1/bookings/{booking}/call',
        'GET v1/bookings/{booking}/messages',
        'POST v1/bookings/{booking}/messages',
        'POST v1/bookings/{booking}/typing',
    ];

    public static function activeTrip(User $user): ?ScheduledTrip
    {
        $asDriver = ScheduledTrip::query()
            ->whereIn('status', self::UNDERWAY)
            ->whereHas('commuteOffer', fn ($query) => $query->where('driver_profile_id', $user->id))
            ->orderBy('departure_at')
            ->first();

        if ($asDriver !== null) {
            return $asDriver;
        }

        return ScheduledTrip::query()
            ->whereIn('status', self::UNDERWAY)
            ->whereHas('bookings', fn ($query) => $query
                ->where('passenger_user_id', $user->id)
                ->where('status', BookingStatus::Confirmed))
            ->orderBy('departure_at')
            ->first();
    }

    public static function activeTripId(User $user): ?string
    {
        return $user->isBanned() ? self::activeTrip($user)?->id : null;
    }

    public static function allows(Request $request, ScheduledTrip $active): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $key = $request->method().' '.preg_replace('#^api/#', '', $route->uri());

        if (in_array($key, self::HOME_ROUTES, true)) {
            return true;
        }

        if (in_array($key, self::TRIP_ROUTES, true)) {
            return self::idOf($route->parameter('trip')) === $active->id;
        }

        if (in_array($key, self::BOOKING_ROUTES, true)) {
            $booking = $route->parameter('booking');
            $booking = $booking instanceof Booking ? $booking : Booking::query()->find($booking);

            return $booking !== null && $booking->scheduled_trip_id === $active->id;
        }

        return false;
    }

    private static function idOf(mixed $value): ?string
    {
        if ($value instanceof ScheduledTrip) {
            return $value->id;
        }

        return is_string($value) ? $value : null;
    }
}
