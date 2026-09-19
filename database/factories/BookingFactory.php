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

        // Derived, not independently rounded, so the CHECK constraint
        // (price = platform_fee + driver_amount) can never drift — pitfall #43.
        $platformFee = (int) round($trip->price_snapshot_piastres * 0.03);
        $driverAmount = $trip->price_snapshot_piastres - $platformFee;

        return [
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $passenger->id,
            'driver_profile_id' => $offer->driver_profile_id,
            'seats_reserved' => 1,
            'price_snapshot_piastres' => $trip->price_snapshot_piastres,
            'platform_fee_snapshot_piastres' => $platformFee,
            'driver_amount_snapshot_piastres' => $driverAmount,
            'payment_type' => PaymentType::Cash,
            'status' => BookingStatus::Confirmed,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BookingStatus::Completed]);
    }
}
