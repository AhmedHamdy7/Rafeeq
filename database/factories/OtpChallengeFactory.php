<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Database\Factories\Concerns\GeneratesEgyptianPhoneNumbers;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpChallenge>
 */
class OtpChallengeFactory extends Factory
{
    use GeneratesEgyptianPhoneNumbers;

    protected $model = OtpChallenge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_e164' => PhoneNumber::fromRaw(self::fakeEgyptianMobile())->e164,
            'purpose' => OtpPurpose::Authentication,
            'code_hash' => Hash::make((string) fake()->numberBetween(100000, 999999)),
            'expires_at' => now()->addMinutes(2),
            'status' => OtpStatus::Pending,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
            'status' => OtpStatus::Expired,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OtpStatus::Verified,
            'verified_at' => now(),
        ]);
    }
}
