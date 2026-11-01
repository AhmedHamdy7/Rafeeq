<?php

namespace Database\Factories;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $trip = ScheduledTrip::factory()->create();
        $offer = $trip->commuteOffer;
        $passenger = User::factory()->woman()->create();

        return [
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $passenger->id,
            'driver_profile_id' => $offer->driver_profile_id,
            'seats_reserved' => 1,
            // Copied from the day's automatic price, as CreateBookingAction does; the day's own
            // CHECK (seat = share + commission) keeps the booking's CHECK true — pitfall #43.
            'price_snapshot_piastres' => $trip->seat_price_piastres,
            'platform_fee_snapshot_piastres' => $trip->commission_piastres,
            'driver_amount_snapshot_piastres' => $trip->driver_share_piastres,
            'payment_type' => PaymentType::Cash,
            'status' => BookingStatus::Confirmed,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::Completed]);
    }
}
