<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Database\Factories\Concerns\GeneratesEgyptianPhoneNumbers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmergencyContact>
 */
class EmergencyContactFactory extends Factory
{
    use GeneratesEgyptianPhoneNumbers;

    protected $model = EmergencyContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName('female'),
            'phone_e164' => PhoneNumber::fromRaw(self::fakeEgyptianMobile())->e164,
            'relationship' => 'أخت',
            'auto_share_trips' => true,
            'is_guardian' => true,
            'verified_at' => now(),
        ];
    }
}
