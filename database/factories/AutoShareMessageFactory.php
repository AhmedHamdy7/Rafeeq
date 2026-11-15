<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\AutoShareMessageStatus;
use App\Domains\Safety\Models\AutoShareMessage;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoShareMessage>
 */
class AutoShareMessageFactory extends Factory
{
    protected $model = AutoShareMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'trip_session_id' => TripSession::factory(),
            'channel' => 'sms',
            'to_e164' => '+201223334444',
            'status' => AutoShareMessageStatus::Sent,
            'sent_at' => now(),
        ];
    }
}
