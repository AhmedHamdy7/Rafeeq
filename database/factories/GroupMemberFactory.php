<?php

namespace Database\Factories;

use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMember>
 */
class GroupMemberFactory extends Factory
{
    protected $model = GroupMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commute_group_id' => CommuteGroup::factory(),
            'user_id' => User::factory(),
            'role' => GroupMemberRole::Member,
            'status' => GroupMemberStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function driver(): static
    {
        return $this->state(fn (array $attributes) => ['role' => GroupMemberRole::Driver]);
    }

    public function trial(): static
    {
        return $this->state(fn (array $attributes) => ['role' => GroupMemberRole::Trial]);
    }
}
