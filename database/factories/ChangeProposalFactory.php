<?php

namespace Database\Factories;

use App\Domains\Commute\Enums\ChangeProposalScope;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeProposal>
 */
class ChangeProposalFactory extends Factory
{
    protected $model = ChangeProposal::class;

    /**
     * A pending proposal to move one morning's departure.
     *
     * ⚠️ A factory row has no affected bookings, so it can never reach unanimity — which is
     * correct: {@see ChangeProposal::isUnanimous()} refuses an empty affected set deliberately,
     * because nobody saying no is not everybody saying yes. A test about applying one builds the
     * passengers through the real endpoints.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_offer_id' => CommuteOffer::factory()->published(),
            'scheduled_trip_id' => ScheduledTrip::factory(),
            'scope' => ChangeProposalScope::OnlyThisOccurrence,
            'changes' => ['departureTime' => '07:30:00'],
            'previous' => ['departureTime' => '07:05:00'],
            'status' => ChangeProposalStatus::Pending,
            'version' => 1,
            'expires_at' => now()->addDay(),
        ];
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChangeProposalStatus::Applied,
            'applied_at' => now(),
        ]);
    }
}
