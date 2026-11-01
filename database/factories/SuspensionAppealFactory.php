<?php

namespace Database\Factories;

use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\SuspensionAppeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A first, undecided appeal against a hold.
 *
 * @extends Factory<SuspensionAppeal>
 */
class SuspensionAppealFactory extends Factory
{
    protected $model = SuspensionAppeal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suspension = AccountSuspension::factory()->create();

        return [
            'account_suspension_id' => $suspension->id,
            'user_id' => $suspension->user_id,
            'attempt' => 1,
            'message' => 'The report is about a different car; I was not driving that morning.',
        ];
    }
}
