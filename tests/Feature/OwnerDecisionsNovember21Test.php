<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Safety\Models\AutoShareMessage;
use App\Domains\Safety\Support\SilentNumberMessenger;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id'),
    );
});

function od21As(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

it('D18: sends the scheduled departure on the booking', function () {
    od21As($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->assertJsonPath('data.departureAt', $this->trip->departure_at->toIso8601String())
        ->assertJsonPath('data.departureLocal', $this->trip->departure_local->format('Y-m-d H:i:s'));
});

it('D18: has its own error code for a seat that would move a pickup inside the lock', function () {
    expect(ErrorCode::BookingMovesFrozenPickup->value)->toBe('BOOKING_MOVES_FROZEN_PICKUP')
        ->and(ErrorCode::BookingMovesFrozenPickup->message())->not->toBe('errors.BOOKING_MOVES_FROZEN_PICKUP');
});

it('D8: keeps the day\'s cancelled run on Home with what the card needs, until she books another', function () {
    underway($this->driverToken, $this->trip->id);

    od21As($this->driverToken)->postJson("/api/v1/trips/{$this->trip->id}/cancel-active", ['reason' => 'breakdown'])->assertOk();

    $card = od21As($this->paxToken)->getJson('/api/v1/home')->assertOk()->json('data.canceledTrip');

    expect($card)->not->toBeNull()
        ->and($card['tripId'])->toBe($this->trip->id)
        ->and($card['bookingId'])->toBe($this->bookingId)
        ->and($card['originLabel'])->not->toBeNull()
        ->and($card['destinationLabel'])->not->toBeNull()
        ->and($card['driverName'])->not->toBeNull()
        ->and($card['date'])->toBe($this->trip->fresh()->trip_date->toDateString())
        ->and($card['searchedFrom'])->toHaveKeys(['lat', 'lng', 'label']);

    od21As($this->paxToken)->postJson("/api/v1/bookings/{$this->bookingId}/seen")->assertOk();

    expect(od21As($this->paxToken)->getJson('/api/v1/home')->json('data.canceledTrip.tripId'))->toBe($this->trip->id);

    $other = ScheduledTrip::query()->where('id', '!=', $this->trip->id)->orderBy('trip_date')->firstOrFail();
    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $other->id])->assertStatus(201)->json('data.id'));

    expect(od21As($this->paxToken)->getJson('/api/v1/home')->json('data.canceledTrip'))->toBeNull();
});

it('D8: has no cancelled card for a passenger whose run went ahead', function () {
    expect(od21As($this->paxToken)->getJson('/api/v1/home')->assertOk()->json('data.canceledTrip'))->toBeNull();
});

it('D24: keeps chat sendable for one hour after the ride, then closes it', function () {
    journeyCompleted($this->driverToken, $this->trip->id);

    od21As($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}/messages")->assertOk()->assertJsonPath('meta.canSend', true);

    TripSession::query()->where('scheduled_trip_id', $this->trip->id)->update(['completed_at' => now()->subMinutes(59)]);
    od21As($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}/messages")->assertJsonPath('meta.canSend', true);

    TripSession::query()->where('scheduled_trip_id', $this->trip->id)->update(['completed_at' => now()->subMinutes(61)]);
    od21As($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}/messages")->assertJsonPath('meta.canSend', false);
});

it('D29: lists the matches of one saved demand', function () {
    $mine = od21As($this->paxToken)->postJson('/api/v1/commute-demands', [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => 31,
        'arrivalWindowStart' => '08:30:00',
        'arrivalWindowEnd' => '09:00:00',
        'maxWalkMinutes' => 15,
        'maxDetourMinutes' => 15,
    ])->assertStatus(201)->json('data.id');

    expect(od21As($this->paxToken)->getJson("/api/v1/matches?demandId={$mine}")->assertOk()->json('data'))->toBeArray()
        ->and(od21As($this->paxToken)->getJson('/api/v1/matches?demandId=not-mine')->assertOk()->json('data'))->toBe([]);
});

it('D37: gives seats left and full flags for each upcoming day of a commute', function () {
    $days = od21As($this->paxToken)->getJson("/api/v1/commutes/{$this->commuteId}/public")->assertOk()->json('data.upcomingTrips');

    expect($days)->not->toBeEmpty()
        ->and($days[0])->toHaveKeys(['seatsAvailable', 'isFull']);
});

it('texts only the picked contact, and never a reserved test number', function () {
    $mama = od21As($this->paxToken)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Mama', 'phone' => '01099998888'])->assertStatus(201)->json('data.id');
    $silent = od21As($this->paxToken)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Test', 'phone' => '01000000000'])->assertStatus(201)->json('data.id');

    expect(AutoShareMessage::query()->count())->toBe(0);

    od21As($this->paxToken)->patchJson("/api/v1/safety/emergency-contacts/{$silent}", ['autoShareTrips' => true])->assertOk();

    expect(SilentNumberMessenger::isSilent('+201000000000'))->toBeTrue()
        ->and(AutoShareMessage::query()->where('emergency_contact_id', $silent)->sole()->status->value)->toBe('sent')
        ->and(AutoShareMessage::query()->where('emergency_contact_id', $mama)->exists())->toBeFalse();
});

it('mentions no coupon in any notification text a ride outcome sends', function () {
    foreach (['en', 'ar'] as $locale) {
        $texts = require base_path("lang/{$locale}/notifications.php");

        foreach ($texts as $key => $text) {
            if ($key === 'coupon_issued') {
                continue;
            }

            expect(mb_strtolower(json_encode($text, JSON_UNESCAPED_UNICODE)))->not->toContain('coupon')->not->toContain('كوبون');
        }
    }
});

it('D18: refuses a new rider inside 12 hours when her stop would move an existing pickup by more than 5 minutes', function () {
    config(['rafeeq.booking.frozen_pickup_shift_seconds' => 1]);
    $trip = ScheduledTrip::query()->findOrFail($this->trip->id);
    $trip->forceFill(['departure_at' => now()->addHours(5), 'promised_arrival_at' => null])->save();

    $far = ['lat' => 30.0700, 'lng' => 31.4000, 'label' => 'Detour stop'];

    $second = verifiedPassenger('01223338888', device: 'pax-3');
    $request = requestSeat($second, $this->commuteId, ['scheduledTripId' => $trip->id, 'pickup' => $far])->assertStatus(201)->json('data.id');

    $response = test()->flushHeaders()->withToken($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$request}/approve");

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_MOVES_FROZEN_PICKUP')
        ->assertJsonPath('error.fields.allowedShiftMinutes.0', '0');
});
