<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\BlockedUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedUser>
 */
class BlockedUserFactory extends Factory
{
    protected $model = BlockedUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blocker_user_id' => User::factory(),
            'blocked_user_id' => User::factory(),
            'reason' => 'سلوك غير مريح',
        ];
    }
}
