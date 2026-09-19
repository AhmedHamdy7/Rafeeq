<?php

use App\Domains\Trip\Models\Attendance;

it('uses booking_id as its own primary key, one attendance per booking', function () {
    $attendance = Attendance::factory()->create();

    expect($attendance->getKeyName())->toBe('booking_id')
        ->and($attendance->getKey())->toBe($attendance->booking_id);
});

it('records the driver as the confirming party per decision D18', function () {
    $attendance = Attendance::factory()->present()->create();

    expect($attendance->confirmed_by)->toBe('driver')
        ->and($attendance->gps_corroborated)->toBeTrue();
});

it('detects an unresolved dispute', function () {
    $disputed = Attendance::factory()->disputed()->create();
    $resolved = Attendance::factory()->disputed()->create(['dispute_resolution' => 'upheld']);

    expect($disputed->isDisputed())->toBeTrue()
        ->and($resolved->isDisputed())->toBeFalse();
});
