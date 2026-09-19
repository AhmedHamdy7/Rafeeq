<?php

namespace Database\Factories;

use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupAbsence;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupAbsence>
 */
class GroupAbsenceFactory extends Factory
{
    protected $model = GroupAbsence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_group_id' => CommuteGroup::factory(),
            'user_id' => User::factory(),
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDays(2)->toDateString(),
            'reason' => 'إجازة',
            'releases_seat' => true,
        ];
    }
}
