<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
});

function replacementCarFor(string $token, string $plate): Vehicle
{
    test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2021, 'colour' => 'white',
        'plateNumber' => $plate, 'seats' => 5,
        'transmission' => 'automatic', 'fuelType' => 'petrol',
    ])->assertStatus(201);

    return Vehicle::query()->where('plate_number', $plate)->sole();
}

it('renders the drivers page with an approved driver who has pending and rejected replacement cars', function (AdminRole $role) {
    config(['rafeeq.testing.api' => true]);
    $token = approvedDriver();

    replacementCarFor($token, 'ق ن ر 7788');
    $rejected = replacementCarFor($token, 'ق ن ر 9911');

    test()->withToken($token)->postJson("/api/v1/testing/driver/vehicles/{$rejected->id}/reject", ['reason' => 'Plate does not match.'])->assertOk();

    expect($rejected->refresh()->verification_status)->toBe(VehicleVerificationStatus::Rejected);

    actingAsAdmin(adminWithRole($role));

    test()->get('/admin/drivers')->assertOk();
    test()->get('/admin/replacement-cars')->assertOk();
})->with([AdminRole::SuperAdmin, AdminRole::Verification]);

it('renders the drivers page after a driver with a pending application deleted the account', function () {
    config(['rafeeq.testing.api' => true]);

    $token = readyDriverApplicant();
    completeDriverApplication($token);
    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();
    test()->withToken($token)->deleteJson('/api/v1/testing/account')->assertOk();

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    test()->get('/admin/drivers')->assertOk();
    test()->get('/admin/replacement-cars')->assertOk();
});

it('takes a deleted driver out of every review list, even if her profile was left pending', function () {
    config(['rafeeq.testing.api' => true]);
    $token = approvedDriver();
    replacementCarFor($token, 'ق ن ر 7788');
    $driverId = DriverProfile::query()->sole()->getKey();

    test()->withToken($token)->deleteJson('/api/v1/testing/account')->assertOk();

    DriverProfile::query()->whereKey($driverId)->update(['status' => 'pending_review']);

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    test()->get('/admin/drivers')->assertOk();
    test()->get('/admin/replacement-cars')->assertOk();
});
