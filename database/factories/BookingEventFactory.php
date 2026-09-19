<?php

namespace Database\Factories;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingEvent>
 */
class BookingEventFactory extends Factory
{
    protected $model = BookingEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $booking = Booking::factory()->create();

        return [
            'booking_id' => $booking->id,
            'event_type' => BookingEventType::Confirmed,
            'actor_type' => BookingActorType::Driver,
            'actor_id' => $booking->driver_profile_id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
        ];
    }
}
