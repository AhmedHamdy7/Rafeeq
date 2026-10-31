<?php

namespace Database\Factories;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatRequest>
 */
class SeatRequestFactory extends Factory
{
    protected $model = SeatRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passenger_user_id' => User::factory()->woman(),
            'commute_offer_id' => CommuteOffer::factory()->published(),
            'commitment' => SeatRequestCommitment::Trial,
            'seats' => 1,
            'meeting_preference' => MeetingPreference::Gate,

            /*
             * Both points, because Feature 03 v5 §2 makes them required and the columns are NOT
             * NULL. The coordinates sit along the corridor the commute factories publish, and are
             * marked `custom_map` — a factory row stands for a passenger who chose her own pins,
             * which is the case with nothing else attached to it.
             */
            'requested_pickup_point' => new Coordinate(30.0624, 31.3614),
            'requested_pickup_lat' => 30.0624,
            'requested_pickup_lng' => 31.3614,
            'requested_pickup_label' => 'Ring Road, Rehab exit',
            'requested_pickup_source' => PointSource::CustomMap,

            'requested_dropoff_point' => new Coordinate(30.0684, 31.1015),
            'requested_dropoff_lat' => 30.0684,
            'requested_dropoff_lng' => 31.1015,
            'requested_dropoff_label' => 'Mehwar, Sheikh Zayed',
            'requested_dropoff_source' => PointSource::CustomMap,

            'intro_message' => 'أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.',
            'agreed_to_rules_at' => now(),
            'payment_type' => PaymentType::Cash,
            'status' => SeatRequestStatus::Pending,
            'kind' => SeatRequestKind::SeatRequest,
            // Requests never expire (Feature 03 R-4); only a during-ride one carries a deadline.
            'expires_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeatRequestStatus::Approved,
            'responded_at' => now(),
        ]);
    }

    public function waitlisted(int $position = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeatRequestStatus::Waitlisted,
            'waitlist_position' => $position,
        ]);
    }
}
