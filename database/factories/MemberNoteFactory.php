<?php

namespace Database\Factories;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\MemberNote;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberNote>
 */
class MemberNoteFactory extends Factory
{
    protected $model = MemberNote::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'admin_id' => AdminUser::factory(),
            'body' => 'Called about last week’s commission. Says they will pay Thursday.',
        ];
    }
}
