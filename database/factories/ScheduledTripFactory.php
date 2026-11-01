<?php

namespace Database\Factories;

use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Commute\Support\SeatPricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTrip>
 */
class ScheduledTripFactory extends Factory
{
    protected $model = ScheduledTrip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Created eagerly: the trip's offer and its schedule must be the
        // same offer, so they can't be two independent lazy factories.
        $offer = CommuteOffer::factory()->published()->create();
        $schedule = CommuteSchedule::factory()->for($offer, 'commuteOffer')->create();

        $tripDate = now()->addDay()->toDateString();

        return [
            'commute_offer_id' => $offer->id,
            'commute_schedule_id' => $schedule->id,
            'trip_date' => $tripDate,
            'departure_at' => DepartureTimeCalculator::toUtc($tripDate, $schedule->departure_time, $schedule->timezone),
            'departure_local' => "{$tripDate} {$schedule->departure_time}",
            'seats_total' => $offer->seats_total,
            'seats_taken' => 0,
            // Priced exactly as the generator prices a day, so a factory day and a real one agree.
            ...SeatPricing::quote((int) $offer->route_distance_meters, $offer->vehicle)->toTripColumns(),
            'status' => ScheduledTripStatus::Scheduled,
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'seats_taken' => $attributes['seats_total'],
        ]);
    }
}
