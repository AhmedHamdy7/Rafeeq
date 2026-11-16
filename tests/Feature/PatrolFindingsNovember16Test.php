<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

function nov16As(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

it('B1: answers a test payee on staging when no InstaPay payee is set', function () {
    $token = verifiedPassenger('01112223344');

    nov16As($token)->getJson('/api/v1/account/debt')
        ->assertOk()
        ->assertJsonPath('data.instapay.handle', 'rafeeq.test@instapay')
        ->assertJsonPath('data.instapay.link', 'https://ipn.eg/S/rafeeq.test/instapay/0')
        ->assertJsonPath('data.instapay.displayName', 'Rafeeq (test)');

    config(['rafeeq.debt.instapay_handle' => 'rafeeq@instapay', 'rafeeq.debt.instapay_link' => 'https://ipn.eg/S/rafeeq/instapay/x', 'rafeeq.debt.instapay_name' => 'Rafeeq']);

    nov16As($token)->getJson('/api/v1/account/debt')
        ->assertOk()
        ->assertJsonPath('data.instapay.handle', 'rafeeq@instapay')
        ->assertJsonPath('data.instapay.displayName', 'Rafeeq');

    config(['rafeeq.testing.api' => false, 'rafeeq.debt.instapay_handle' => null, 'rafeeq.debt.instapay_link' => null]);

    expect(nov16As($token)->getJson('/api/v1/account/debt')->assertOk()->json('data.instapay'))->toBeNull();
});

it('B2: returns a full commute in search, marked full, so she can join its waiting list', function () {
    ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->update(['seats_taken' => DB::raw('seats_total')]);

    $paxToken = verifiedPassenger('01112223344');

    $page = search($paxToken, ['seatsNeeded' => 1])->assertOk();

    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('data.0.commuteId'))->toBe($this->commuteId)
        ->and($page->json('data.0.isFull'))->toBeTrue()
        ->and($page->json('data.0.seatsAvailable'))->toBe(0);
});

it('B2: puts an open day on the card ahead of a full one', function () {
    $days = ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->orderBy('departure_at')->get();
    $days->first()->forceFill(['seats_taken' => $days->first()->seats_total])->save();

    $page = search(verifiedPassenger('01112223344'))->assertOk();

    expect($page->json('data.0.tripId'))->toBe($days->get(1)->id)
        ->and($page->json('data.0.isFull'))->toBeFalse()
        ->and(collect($page->json('data.0.otherDays'))->firstWhere('id', $days->first()->id)['isFull'])->toBeTrue();
});

it('B3: lists a NO_RESPONSE row for a member who has not answered yet', function () {
    $paxToken = verifiedPassenger('01112223344', device: 'pax-1');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id'));
    $groupId = CommuteGroup::sole()->id;

    nov16As($paxToken)->getJson("/api/v1/groups/{$groupId}/attendance?tripId={$this->tripId}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'NO_RESPONSE')
        ->assertJsonPath('data.0.id', null)
        ->assertJsonPath('data.0.isMe', true)
        ->assertJsonPath('data.0.tripId', $this->tripId);

    nov16As($paxToken)->postJson("/api/v1/groups/{$groupId}/attendance", ['tripId' => $this->tripId, 'status' => 'coming'])->assertStatus(201);

    nov16As($paxToken)->getJson("/api/v1/groups/{$groupId}/attendance?tripId={$this->tripId}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'COMING');
});

it('B4: refuses Home to a banned account with a permanent ACCOUNT_SUSPENDED', function () {
    $paxToken = verifiedPassenger('01112223344');

    nov16As($paxToken)->getJson('/api/v1/home')->assertOk();

    nov16As($paxToken)->postJson('/api/v1/testing/account/ban')->assertOk();

    nov16As($paxToken)->getJson('/api/v1/home')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED')
        ->assertJsonPath('error.fields.permanent.0', 'true');

    nov16As($paxToken)->getJson('/api/v1/my-bookings')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    nov16As($paxToken)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.nextStep', 'RESTRICTED_ACCOUNT');
});

it('B5: approves the first car without AC when asked, and keeps it on the next approve', function () {
    nov16As($this->driverToken)->postJson('/api/v1/testing/driver/approve', ['hasAc' => false, 'tier' => 'standard'])
        ->assertOk()
        ->assertJsonPath('data.vehicles.0.hasAc', false)
        ->assertJsonPath('data.vehicles.0.tier', 'STANDARD');

    nov16As($this->driverToken)->postJson('/api/v1/testing/driver/approve')
        ->assertOk()
        ->assertJsonPath('data.vehicles.0.hasAc', false)
        ->assertJsonPath('data.vehicles.0.tier', 'STANDARD');

    expect(Vehicle::sole()->has_ac)->toBeFalse();
});

it('B6: gives a cast account its name back on reset', function () {
    $token = verifiedPassenger('01020000003');
    $mariam = User::query()->where('phone_e164', '+201020000003')->sole();
    $mariam->forceFill(['full_name' => 'Test Passenger', 'public_first_name' => 'Test'])->save();

    nov16As($token)->postJson('/api/v1/testing/reset')->assertOk()->assertJsonPath('data.castNameRestored', true);

    expect($mariam->refresh()->full_name)->toBe('Mariam Story')
        ->and($mariam->public_first_name)->toBe('Mariam');
});

it('B7: completes a run whose only passenger was a no-show, straight from the pickup', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id'));

    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $bookingId);

    nov16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $bookingId])->assertOk();
    nov16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect(TripSession::sole()->current_status)->toBe(TripSessionStatus::Completed)
        ->and(Booking::query()->findOrFail($bookingId)->status)->toBe(BookingStatus::NoShow);
});

it('B7: still refuses to complete while somebody is waiting to be collected', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id'));

    underway($this->driverToken, $this->tripId);

    nov16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_INVALID_TRANSITION');
});

it('B8: edits the pickup on a waiting-list place and sends it back to the driver', function () {
    $riderToken = verifiedPassenger('01112223344', device: 'pax-1');
    approveSeat($this->driverToken, requestSeat($riderToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id'));
    ScheduledTrip::query()->whereKey($this->tripId)->update(['seats_taken' => DB::raw('seats_total')]);

    $waiterToken = verifiedPassenger('01222220001', device: 'pax-2');
    $requestId = requestSeat($waiterToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->assertJsonPath('data.kind', 'waitlist_application')->json('data.id');

    nov16As($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    nov16As($waiterToken)->patchJson("/api/v1/seat-requests/{$requestId}", [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3500, 'label' => 'Rehab gate 2'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.kind', 'waitlist_application');

    expect(round((float) SeatRequest::query()->findOrFail($requestId)->requested_pickup_lat, 4))->toBe(30.063);
});
