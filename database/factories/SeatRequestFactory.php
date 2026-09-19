<?php

namespace Database\Factories;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
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
            'intro_message' => 'أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.',
            'agreed_to_rules_at' => now(),
            'payment_type' => PaymentType::Cash,
            'status' => SeatRequestStatus::Pending,
            'expires_at' => now()->addHours(48),
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
