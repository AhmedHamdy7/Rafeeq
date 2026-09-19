<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Notification\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_user_id' => User::factory(),
            'body' => 'أنا في بوابة الرحاب، هستناكي عند البوابة الرئيسية.',
        ];
    }

    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'flagged_reason' => 'contains_contact_info',
            'contains_contact_info' => true,
        ]);
    }
}
