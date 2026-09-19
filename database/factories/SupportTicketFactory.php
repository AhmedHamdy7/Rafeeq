<?php

namespace Database\Factories;

use App\Domains\Admin\Enums\SupportTicketPriority;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => 'payment_issue',
            'priority' => SupportTicketPriority::Medium,
        ];
    }
}
