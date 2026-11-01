<?php

namespace App\Domains\Identity\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Collection;

/**
 * May she delete her account right now (PROF-31, D-0028)?
 *
 * Two conditions, both about not walking away from somebody else:
 *
 *   · **no debt** on either side of the ledger — a fee she owes or a commission he owes;
 *   · **no trip inside the fee window** — cancelling every upcoming seat she holds, and every seat on
 *     her own commutes, would cost no fee. As a passenger that is the late-cancel window
 *     (`fees.late_cancel_hours`, 12h); as a driver it is any notice `DriverFees` would charge for
 *     (under 16h). A trip already moving is inside both.
 *
 * Trips OUTSIDE the window do not block: deleting cancels them for free, which is the looser rule
 * D-0028 chose over "no upcoming bookings at all".
 *
 * The same answer serves the pre-check (`GET /account/deletion`) and the refusal, so the screen
 * that says "you can delete" and the request that then deletes cannot disagree.
 */
final class AccountDeletionCheck
{
    /**
     * @return array{canDelete: bool, debtPiastres: int, tripsInFeeWindow: list<array{bookingId: string, tripId: string, role: string, departureAt: string|null}>}
     */
    public static function for(User $user): array
    {
        $debt = DebtStanding::of($user, DebtSide::Passenger)->owedPiastres()
            + DebtStanding::of($user, DebtSide::Driver)->owedPiastres();

        // Built row by row rather than mapped, so the OpenAPI generator can read the row's shape.
        $trips = [];

        foreach (self::passengerSeatsInWindow($user) as $booking) {
            $trips[] = self::row($booking, 'passenger');
        }

        foreach (self::driverSeatsInWindow($user) as $booking) {
            $trips[] = self::row($booking, 'driver');
        }

        return [
            'canDelete' => $debt === 0 && $trips === [],
            'debtPiastres' => $debt,
            'tripsInFeeWindow' => $trips,
        ];
    }

    /**
     * Debt first: it is the one she can fix today, from the Payments screen.
     */
    public static function assertCanDelete(User $user): void
    {
        $check = self::for($user);

        if ($check['debtPiastres'] > 0) {
            throw DomainException::of(ErrorCode::AccountHasDebt, fields: [
                'debtPiastres' => [(string) $check['debtPiastres']],
            ]);
        }

        if ($check['tripsInFeeWindow'] !== []) {
            throw DomainException::of(ErrorCode::AccountHasTripInFeeWindow, fields: [
                'bookingIds' => array_column($check['tripsInFeeWindow'], 'bookingId'),
            ]);
        }
    }

    /**
     * Every confirmed seat she holds on a day that has not finished.
     *
     * @return Collection<int, Booking>
     */
    public static function upcomingPassengerSeats(User $user): Collection
    {
        return Booking::query()
            ->where('passenger_user_id', $user->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereHas('scheduledTrip', fn ($trip) => $trip->whereIn('status', self::unfinished()))
            ->with('scheduledTrip')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Booking> */
    private static function passengerSeatsInWindow(User $user): Collection
    {
        $edge = now()->addHours(FeeSettings::lateCancelHours());

        return self::upcomingPassengerSeats($user)
            ->filter(fn (Booking $b) => $b->scheduledTrip?->departure_at !== null && $b->scheduledTrip->departure_at->lt($edge))
            ->values();
    }

    /** @return Collection<int, Booking> */
    private static function driverSeatsInWindow(User $user): Collection
    {
        return Booking::query()
            ->where('driver_profile_id', $user->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereHas('scheduledTrip', fn ($trip) => $trip->whereIn('status', self::unfinished()))
            ->with('scheduledTrip')
            ->orderBy('id')
            ->get()
            ->filter(fn (Booking $b) => DriverFees::cancelPercent($b->scheduledTrip?->departure_at) > 0)
            ->values();
    }

    /** @return list<string> */
    private static function unfinished(): array
    {
        return [
            ScheduledTripStatus::Scheduled->value,
            ScheduledTripStatus::Preparing->value,
            ScheduledTripStatus::EnRoute->value,
            ScheduledTripStatus::InProgress->value,
        ];
    }

    /** @return array{bookingId: string, tripId: string, role: string, departureAt: string|null} */
    private static function row(Booking $booking, string $role): array
    {
        return [
            'bookingId' => (string) $booking->id,
            'tripId' => (string) $booking->scheduled_trip_id,
            'role' => $role,
            'departureAt' => $booking->scheduledTrip?->departure_at?->toIso8601String(),
        ];
    }
}
