<?php

namespace Database\Factories;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'status' => AttendanceStatus::Pending,
        ];
    }

    public function present(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttendanceStatus::Present,
            'checked_in_at' => now(),
            'confirmed_by' => 'driver',
            'confirmed_at' => now(),
            'gps_corroborated' => true,
            'gps_confidence' => 0.94,
        ]);
    }

    public function disputed(): static
    {
        return $this->present()->state(fn (array $attributes) => [
            'disputed_at' => now(),
            'dispute_reason' => 'أنا ملحقتش أركب',
        ]);
    }
}
