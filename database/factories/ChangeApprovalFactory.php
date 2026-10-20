<?php

namespace Database\Factories;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ChangeApprovalResponse;
use App\Domains\Commute\Models\ChangeApproval;
use App\Domains\Commute\Models\ChangeProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeApproval>
 */
class ChangeApprovalFactory extends Factory
{
    protected $model = ChangeApproval::class;

    /**
     * One passenger's yes to version 1.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'change_proposal_id' => ChangeProposal::factory(),
            'booking_id' => Booking::factory(),
            'response' => ChangeApprovalResponse::Accepted,
            'proposal_version' => 1,
            'responded_at' => now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'response' => ChangeApprovalResponse::Rejected,
        ]);
    }
}
