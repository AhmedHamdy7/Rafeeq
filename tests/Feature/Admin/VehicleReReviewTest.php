<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Support\SeatPricing;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Livewire\Admin\Directory\VehicleDetail;
use Livewire\Livewire;

/**
 * Re-reviewing an approved car (E06): seats, Comfort and AC change, and so does the price of
 * the car's commutes for trip days created from now on.
 */
function reReviewableCommute(): CommuteOffer
{
    $commute = CommuteOffer::factory()->published()->create();
    $commute->vehicle->forceFill([
        'verification_status' => VehicleVerificationStatus::Approved->value,
        'approved_seats' => 4,
        'tier' => VehicleTier::Standard->value,
        'has_ac' => true,
    ])->save();

    return $commute;
}

it('re-reviews a car and reprices its published commutes', function () {
    $commute = reReviewableCommute();
    $vehicle = $commute->vehicle;
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(VehicleDetail::class, ['vehicle' => $vehicle])
        ->call('start', 're_review')
        ->assertSet('reSeats', 4)
        ->set('reTier', VehicleTier::Comfort->value)
        ->set('reason', 'Driver sent photos of the new leather seats')
        ->call('act')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/records.vehicle.done.re_review'));

    $vehicle->refresh();
    $expected = SeatPricing::quote((int) $commute->route_distance_meters, $vehicle);

    expect($vehicle->tierOrDefault())->toBe(VehicleTier::Comfort)
        ->and((int) $commute->refresh()->seat_price_piastres)->toBe($expected->seatPricePiastres)
        ->and(AdminAction::query()->where('action', 'vehicle.rereview')->value('reason'))->toBe('Driver sent photos of the new leather seats');
});

it('refuses fewer seats than a commute on the car already sells', function () {
    $commute = reReviewableCommute();
    $commute->forceFill(['seats_total' => 3])->save();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(VehicleDetail::class, ['vehicle' => $commute->vehicle])
        ->call('start', 're_review')
        ->set('reSeats', 2)
        ->set('reason', 'One seat no longer has a belt')
        ->call('act')
        ->assertHasErrors('reSeats');

    expect((int) $commute->vehicle->refresh()->approved_seats)->toBe(4);
});
