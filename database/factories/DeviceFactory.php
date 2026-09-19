<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\DevicePlatform;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $platform = fake()->randomElement(DevicePlatform::cases());

        return [
            'user_id' => User::factory(),
            'device_public_id' => (string) Str::ulid(),
            'platform' => $platform,
            'device_model' => $platform === DevicePlatform::Android ? 'Samsung SM-A546E' : 'iPhone 15',
            'os_version' => $platform === DevicePlatform::Android ? 'Android 14' : 'iOS 18',
            'app_version' => '1.0.0',
            'is_trusted' => true,
            'has_local_pin' => true,
            'biometric_enabled' => fake()->boolean(),
            'last_seen_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
