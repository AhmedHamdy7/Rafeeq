<?php

namespace Database\Factories;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Driver\Models\VerificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VerificationLog>
 */
class VerificationLogFactory extends Factory
{
    protected $model = VerificationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => 'driver_profile',
            'entity_id' => (string) Str::ulid(),
            'admin_id' => AdminUser::factory(),
            'action' => VerificationLogAction::Approve,
            'old_value' => ['status' => 'pending_review'],
            'new_value' => ['status' => 'approved'],
        ];
    }
}
