<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => NotificationCategory::Marketing,
            'channel' => NotificationChannel::Push,
            'enabled' => true,
        ];
    }
}
