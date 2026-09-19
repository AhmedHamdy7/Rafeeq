<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'booking_confirmed',
            'title' => 'تم تأكيد حجزك',
            'body' => 'نور وافقت على طلب المقعد بتاعك يوم الأحد الساعة 7:05.',
            'channel' => NotificationChannel::Push,
            'category' => NotificationCategory::Booking,
            'sent_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => ['read_at' => now()]);
    }
}
