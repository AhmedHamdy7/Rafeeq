<?php

namespace Database\Factories;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SeatOffer>
 */
class SeatOfferFactory extends Factory
{
    protected $model = SeatOffer::class;

    /**
     * Open by default, because that is the only state a real offer is created in — every other one
     * is reached by something happening to it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheduled_trip_id' => ScheduledTrip::factory(),
            'seat_request_id' => SeatRequest::factory(),
            'status' => SeatOfferStatus::Open,
            // Its own id rather than a shared constant: two offers built by a factory are two
            // separate releases unless a test deliberately groups them.
            'release_event_id' => (string) Str::ulid(),
            'closed_at' => null,
        ];
    }

    public function takenByOther(): static
    {
        return $this->state(fn (): array => [
            'status' => SeatOfferStatus::TakenByOther,
            'closed_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => SeatOfferStatus::Accepted,
            'closed_at' => now(),
        ]);
    }
}
