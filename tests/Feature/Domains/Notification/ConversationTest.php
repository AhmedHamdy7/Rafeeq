<?php

use App\Domains\Notification\Models\Conversation;
use App\Domains\Notification\Models\Message;

it('reports open vs closed state', function () {
    $open = Conversation::factory()->create();
    $closed = Conversation::factory()->closed()->create();

    expect($open->isOpen())->toBeTrue()
        ->and($closed->isOpen())->toBeFalse();
});

it('loads its messages in order', function () {
    $conversation = Conversation::factory()->create();

    Message::factory()->for($conversation)->create(['body' => 'أول رسالة']);
    Message::factory()->for($conversation)->create(['body' => 'تاني رسالة']);

    expect($conversation->messages)->toHaveCount(2);
});

it('flags a message suspected of containing contact info', function () {
    $message = Message::factory()->flagged()->create();

    expect($message->isFlagged())->toBeTrue()
        ->and($message->contains_contact_info)->toBeTrue();
});
