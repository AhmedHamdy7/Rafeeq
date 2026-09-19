<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserVerification>
 */
class UserVerificationFactory extends Factory
{
    protected $model = UserVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => VerificationType::GovernmentId,
            'status' => VerificationStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'صورة الهوية غير واضحة — أعد رفعها في إضاءة أفضل.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationStatus::Rejected,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
