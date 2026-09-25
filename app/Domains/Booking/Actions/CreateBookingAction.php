<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Booking\Support\FeeSplit;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Models\CommuteGroup;

/**
 * Turns an approved seat request into a booking for one day.
 *
 * 🔒 Three money figures are frozen here and never recomputed: the price, the
 * platform's share and the driver's share. Pitfall #42 is computing the fee at
 * collection time instead — the percentage changes from 3% to 4% and bookings
 * made last month are suddenly charged the new rate. Pitfall #13 is the same
 * shape for price: a driver raises the fare and people who already booked are
 * billed the new one.
 *
 * Called only from inside a transaction that holds a lock on the trip. It does
 * not check seat availability itself — the caller does that under the lock, and a
 * check here would either duplicate it or give false comfort.
 */
final readonly class CreateBookingAction
{
    public function execute(SeatRequest $request, ScheduledTrip $trip, ?CommuteGroup $group = null): Booking
    {
        $split = FeeSplit::forSeats($trip->price_snapshot_piastres, $request->seats);

        $booking = new Booking;

        $booking->fill([
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $request->passenger_user_id,
            // Denormalised so a driver's own bookings can be listed without
            // joining through the trip and the offer.
            'driver_profile_id' => $trip->commuteOffer->driver_profile_id,
            'commute_group_id' => $group?->id,
            'seat_request_id' => $request->id,
            'seats_reserved' => $request->seats,
            'price_snapshot_piastres' => $split['price'],
            'platform_fee_snapshot_piastres' => $split['platformFee'],
            'driver_amount_snapshot_piastres' => $split['driverAmount'],
            'payment_type' => $request->payment_type->value,
            'pickup_place_id' => $request->custom_pickup_place_id,
        ]);

        /*
         * Confirmed on creation, not pending.
         *
         * Chapter 6 shows `pending -> confirmed`, and that sequence belongs to
         * the book's simpler model where a passenger books directly. Here a
         * DRIVER has just approved a request they read — the seat is theirs, and
         * leaving it pending would invent a second approval nobody performs.
         */
        $booking->status = BookingStatus::Confirmed->value;

        // Cash is settled in the car, so nothing is owed to us yet; an online
        // payment becomes due now. Neither is collected in Phase 7.
        $booking->payment_status = $request->payment_type === PaymentType::Cash
            ? PaymentStatus::NotDue->value
            : PaymentStatus::Pending->value;

        $booking->save();

        BookingEventLog::record(
            $booking,
            BookingEventType::Confirmed,
            BookingActorType::Driver,
            $request->responded_by,
            from: null,
            metadata: ['seat_request_id' => $request->id],
        );

        return $booking;
    }
}
