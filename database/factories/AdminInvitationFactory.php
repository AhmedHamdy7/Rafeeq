<?php

namespace Database\Factories;

use App\Domains\Admin\Enums\AdminStatus;
use App\Domains\Admin\Models\AdminInvitation;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdminInvitation>
 */
class AdminInvitationFactory extends Factory
{
    protected $model = AdminInvitation::class;

    public function definition(): array
    {
        return [
            'admin_user_id' => AdminUser::factory()->state(['status' => AdminStatus::Invited, 'mfa_confirmed_at' => null]),
            'token_hash' => AdminInvitation::hash(Str::random(48)),
            'expires_at' => now()->addHours(72),
        ];
    }
}
