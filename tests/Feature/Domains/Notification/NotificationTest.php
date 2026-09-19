<?php

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;

it('overrides Notifiable default relation with the custom schema', function () {
    $user = User::factory()->create();
    Notification::factory()->for($user)->create();

    expect($user->notifications)->toHaveCount(1)
        ->and($user->notifications->first())->toBeInstanceOf(Notification::class);
});

it('reports read state', function () {
    $unread = Notification::factory()->create();
    $read = Notification::factory()->read()->create();

    expect($unread->isRead())->toBeFalse()
        ->and($read->isRead())->toBeTrue();
});
