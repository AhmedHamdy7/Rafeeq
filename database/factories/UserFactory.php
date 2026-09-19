<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Database\Factories\Concerns\GeneratesEgyptianPhoneNumbers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    use GeneratesEgyptianPhoneNumbers;

    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = fake()->randomElement([Gender::Woman, Gender::Man]);

        return [
            'phone_e164' => PhoneNumber::fromRaw(self::fakeEgyptianMobile())->e164,
            'phone_verified_at' => now(),
            'full_name' => fake()->name($gender === Gender::Woman ? 'female' : 'male'),
            'public_first_name' => fake()->firstName($gender === Gender::Woman ? 'female' : 'male'),
            'gender' => $gender,
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-18 years'),
            'email' => fake()->unique()->safeEmail(),
            'account_status' => AccountStatus::Active,
            'profile_status' => ProfileStatus::BasicComplete,
            'registered_role' => fake()->randomElement(RegisteredRole::cases()),
            'preferred_language' => fake()->randomElement(['ar', 'en']),
            'trust_level' => fake()->numberBetween(1, 4),
        ];
    }

    public function unverifiedPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
        ]);
    }

    public function woman(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => Gender::Woman,
            'full_name' => fake()->name('female'),
            'public_first_name' => fake()->firstName('female'),
        ]);
    }

    public function man(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => Gender::Man,
            'full_name' => fake()->name('male'),
            'public_first_name' => fake()->firstName('male'),
        ]);
    }
}
