<?php

namespace Database\Factories;

use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdminAction>
 */
class AdminActionFactory extends Factory
{
    protected $model = AdminAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_id' => AdminUser::factory(),
            'action' => 'approve_driver',
            'entity_type' => 'driver_profile',
            'entity_id' => (string) Str::ulid(),
            'old_value' => ['status' => 'pending_review'],
            'new_value' => ['status' => 'approved'],
        ];
    }
}
