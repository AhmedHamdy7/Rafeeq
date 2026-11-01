<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;

/**
 * Keeps a passenger's fee line on her next ride (PAY-16, decision D-0007; Feature 03 backend file
 * L-11).
 *
 * 🔴 The line is her whole passenger balance, put on ONE booking: the next confirmed ride that has
 * not set off and is not yet paid — even a booking confirmed weeks ago as part of a recurring
 * membership. Every other unpaid booking of hers carries zero. So a cancelled ride drops the line
 * and it moves to the one after; a ride that happens moves it onto the ledger (see
 * {@see \App\Domains\Payment\Actions\SettleBookingAction}).
 *
 * Called by the ledger after every passenger-side line, and after anything that changes which ride
 * is "next" (a booking confirmed or cancelled is picked up the next time her balance moves, and on
 * every drop-off).
 *
 * A credit is a negative line — a discount on the ride — but never more than the fare: the driver
 * collects less cash, he does not hand any over.
 */
final class FeeLines
{
    public static function refresh(string $passengerUserId): void
    {
        $balance = DebtLedger::balanceOf($passengerUserId, DebtSide::Passenger);

        $next = Booking::query()
            ->select('bookings.*')
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->where('bookings.passenger_user_id', $passengerUserId)
            ->where('bookings.status', BookingStatus::Confirmed->value)
            ->where('bookings.payment_status', PaymentStatus::NotDue->value)
            ->where('scheduled_trips.status', ScheduledTripStatus::Scheduled->value)
            ->where('scheduled_trips.departure_at', '>', now())
            ->orderBy('scheduled_trips.departure_at')
            ->orderBy('bookings.id')
            ->first();

        // Every other unpaid booking of hers carries nothing: one debt, one line.
        Booking::query()
            ->where('passenger_user_id', $passengerUserId)
            ->where('payment_status', PaymentStatus::NotDue->value)
            ->where('fee_line_piastres', '!=', 0)
            ->when($next !== null, fn ($query) => $query->whereKeyNot($next->getKey()))
            ->update(['fee_line_piastres' => 0, 'fee_line_reason' => null]);

        if ($next === null) {
            return;
        }

        $line = $balance < 0 ? max($balance, -(int) $next->price_snapshot_piastres) : $balance;

        $next->forceFill([
            'fee_line_piastres' => $line,
            'fee_line_reason' => match (true) {
                $line > 0 => self::latestReason($passengerUserId),
                $line < 0 => 'credit',
                default => null,
            },
        ])->save();
    }

    /**
     * What the newest thing she owes was for — the reason the app words on the line.
     */
    private static function latestReason(string $passengerUserId): string
    {
        return (string) (DebtEntry::query()
            ->where('user_id', $passengerUserId)
            ->where('side', DebtSide::Passenger->value)
            ->where('amount_piastres', '>', 0)
            ->whereNot('type', DebtEntryType::FeeLine->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('reason_code') ?? 'fee');
    }
}
