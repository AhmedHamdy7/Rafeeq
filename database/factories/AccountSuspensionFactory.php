<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountSuspension>
 */
class AccountSuspensionFactory extends Factory
{
    protected $model = AccountSuspension::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'case_number' => 'RF-'.fake()->unique()->numerify('######'),
            'reason_code' => SuspensionReason::SafetyReport,
            'note' => 'Two reports about unsafe driving in one week, holding while we review.',
            'suspended_at' => now(),
            'review_due_at' => now()->addDay(),
        ];
    }
}
