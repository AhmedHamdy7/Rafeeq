<?php

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Database\QueryException;

it('normalizes the plate number automatically on save', function () {
    $vehicle = Vehicle::factory()->create(['plate_number' => 'ق ط 421']);

    expect($vehicle->plate_normalized)->toBe('قط421');
});

it('rejects a duplicate normalized plate across the whole platform', function () {
    Vehicle::factory()->create(['plate_number' => '123 ABC']);
    Vehicle::factory()->create(['plate_number' => '123abc']);
})->throws(QueryException::class);

it('allows only one active vehicle per driver', function () {
    $profile = DriverProfile::factory()->create();

    Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);
    Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);
})->throws(QueryException::class);

it('allows several inactive vehicles for the same driver', function () {
    $profile = DriverProfile::factory()->create();

    Vehicle::factory()->for($profile, 'driverProfile')->inactive()->create();
    $second = Vehicle::factory()->for($profile, 'driverProfile')->inactive()->create();

    expect($second->exists)->toBeTrue();
});

it('loads vehicles through DriverProfile::vehicles() despite the non-standard user_id primary key', function () {
    $profile = DriverProfile::factory()->create();
    Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);

    expect($profile->vehicles)->toHaveCount(1)
        ->and($profile->activeVehicle?->driver_profile_id)->toBe($profile->user_id);
});

it('allows a driver to switch which vehicle is active', function () {
    $profile = DriverProfile::factory()->create();

    $first = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);
    $first->update(['is_active' => false]);

    $second = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);

    expect($second->fresh()->is_active)->toBeTrue();
});
