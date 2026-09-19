<?php

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Payment\Models\DriverFeeLedger;

it('cannot be updated once written', function () {
    $entry = DriverFeeLedger::factory()->create();

    $entry->update(['note' => 'rewriting history']);
})->throws(RuntimeException::class);

it('cannot be deleted', function () {
    $entry = DriverFeeLedger::factory()->create();

    $entry->delete();
})->throws(RuntimeException::class);

it('sums signed amounts to a running balance across fee_due and fee_settled entries', function () {
    $driverProfile = DriverProfile::factory()->create();

    DriverFeeLedger::factory()->create(['driver_profile_id' => $driverProfile->user_id, 'amount_piastres' => 240]);
    DriverFeeLedger::factory()->create(['driver_profile_id' => $driverProfile->user_id, 'amount_piastres' => 240]);
    DriverFeeLedger::factory()->settled()->create(['driver_profile_id' => $driverProfile->user_id]);

    $sum = DriverFeeLedger::where('driver_profile_id', $driverProfile->user_id)->sum('amount_piastres');

    expect((int) $sum)->toBe(240);
});

it('flags a balance that exceeds the configured debt cap', function () {
    $balance = DriverBalance::factory()->blocked()->create();

    expect($balance->exceedsDebtCap(20000))->toBeTrue()
        ->and($balance->is_blocked_from_publishing)->toBeTrue();
});
