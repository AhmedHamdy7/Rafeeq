<?php

use App\Domains\Safety\Models\EscortWindow;

it('reports active state within its time window', function () {
    $active = EscortWindow::factory()->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(5),
    ]);
    $notYet = EscortWindow::factory()->create([
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(5),
    ]);

    expect($active->isActive())->toBeTrue()
        ->and($notYet->isActive())->toBeFalse();
});
