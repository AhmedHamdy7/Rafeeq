<?php

use App\Domains\Trip\Models\TripWaitTimer;

it('reports expiry once the grace period has passed', function () {
    $fresh = TripWaitTimer::factory()->create();
    $expired = TripWaitTimer::factory()->expired()->create();

    expect($fresh->hasExpired())->toBeFalse()
        ->and($expired->hasExpired())->toBeTrue();
});

it('accounts for an extension when checking expiry', function () {
    $timer = TripWaitTimer::factory()->create([
        'started_at' => now()->subMinutes(6),
        'grace_seconds' => 300,
        'extended_seconds' => 120,
    ]);

    // 6 minutes elapsed < 5 + 2 minutes grace+extension.
    expect($timer->hasExpired())->toBeFalse();
});
