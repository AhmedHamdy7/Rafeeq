<?php

namespace Database\Factories;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupAttendance;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupAttendance>
 */
class GroupAttendanceFactory extends Factory
{
    protected $model = GroupAttendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_group_id' => CommuteGroup::factory(),
            'scheduled_trip_id' => ScheduledTrip::factory(),
            'user_id' => User::factory(),
            'status' => GroupAttendanceStatus::Coming,
            'marked_at' => now(),
        ];
    }
}
