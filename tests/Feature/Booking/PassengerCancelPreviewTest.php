<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\Storage;

/**
 * TRIPS-18: what cancelling her own seat would cost her, before she confirms — from the same rule the
 * cancel runs (PAY-21), so the sheet and the charge cannot disagree.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->json('data.id'));
});

it('costs nothing far enough ahead', function () {
    runLeavingIn($this->tripId, 20 * 60);

    $this->withToken($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}/cancel-preview")
        ->assertOk()
        ->assertJsonPath('data.feePiastres', 0)
        ->assertJsonPath('data.reason', 'none')
        ->assertJsonPath('data.warning', false);
});

it('says inside the window what the cancel then actually records', function () {
    runLeavingIn($this->tripId, 6 * 60);

    $preview = $this->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}/cancel-preview")
        ->assertOk()
        ->assertJsonPath('data.warning', true)
        ->json('data');

    $this->withToken($this->paxToken)->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $recorded = MemberWarning::query()->where('booking_id', $this->bookingId)->sole();

    expect($preview['rung'])->toBe((int) $recorded->ordinal)
        ->and($preview['feePiastres'])->toBe((int) $recorded->fee_piastres)
        ->and($preview['reason'])->toBe($recorded->fee_piastres > 0 ? 'late_cancel_fee' : 'late_cancel_warning');
});

it('is hers only — the driver has his own previews', function () {
    $this->withToken($this->driverToken)->getJson("/api/v1/bookings/{$this->bookingId}/cancel-preview")
        ->assertNotFound();
});
