<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Trip\Models\TripSession;

beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 2]);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $this->tripId = $this->trip->id;
});

function dcRider(string $driverToken, string $commuteId, string $tripId, string $phone): array
{
    $token = verifiedPassenger($phone, device: 'dc-'.$phone);
    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    return ['token' => $token, 'bookingId' => approveSeat($driverToken, $requestId)];
}

function dcWaiting(string $driverToken, string $commuteId, string $tripId, string $phone): array
{
    $token = verifiedPassenger($phone, device: 'dc-'.$phone);
    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    return ['token' => $token, 'requestId' => $requestId];
}

function dcGet(string $token, string $url)
{
    return test()->flushHeaders()->withToken($token)->getJson($url);
}

function dcPost(string $token, string $url, array $body = [])
{
    return test()->flushHeaders()->withToken($token)->postJson($url, $body);
}

it('lists the driver’s coming days oldest first, with route and seats', function () {
    dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');

    $rows = dcGet($this->driverToken, '/api/v1/driver/trips?scope=upcoming')->assertOk()->assertJsonStructure(['meta'])->json('data');

    expect($rows[0]['id'])->toBe($this->tripId)
        ->and($rows[0]['commuteId'])->toBe($this->commuteId)
        ->and($rows[0]['seatsTaken'])->toBe(1)
        ->and($rows[0]['passengerCount'])->toBe(1)
        ->and($rows[0]['originLabel'])->not->toBeNull()
        ->and($rows[1]['tripDate'] >= $rows[0]['tripDate'])->toBeTrue();
});

it('leaves a paused commute’s days out of the coming days', function () {
    dcPost($this->driverToken, "/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    expect(dcGet($this->driverToken, '/api/v1/driver/trips?scope=upcoming')->assertOk()->json('data'))->toBe([]);
});

it('requires a scope and hides other drivers’ days', function () {
    dcGet($this->driverToken, '/api/v1/driver/trips')->assertStatus(422);

    $other = verifiedPassenger('01100000001', device: 'dc-other');

    expect(dcGet($other, '/api/v1/driver/trips?scope=upcoming')->assertOk()->json('data'))->toBe([]);
});

it('lists past days newest first with each result', function () {
    $days = ScheduledTrip::query()->orderBy('trip_date')->take(3)->get();

    $days[0]->forceFill(['status' => ScheduledTripStatus::Expired->value, 'expired_at' => now(), 'departure_at' => now()->subDays(3), 'departure_local' => now()->subDays(3)])->save();

    $days[1]->forceFill(['status' => ScheduledTripStatus::Completed->value, 'departure_at' => now()->subDays(2), 'departure_local' => now()->subDays(2)])->save();
    $session = new TripSession;
    $session->fill(['scheduled_trip_id' => $days[1]->id, 'started_at' => now()->subDays(2)]);
    $session->current_status = 'completed';
    $session->completed_at = now()->subDays(2)->addHour();
    $session->save();

    dcPost($this->driverToken, "/api/v1/trips/{$days[2]->id}/cancel")->assertOk();

    $rows = collect(dcGet($this->driverToken, '/api/v1/driver/trips?scope=past')->assertOk()->json('data'))->keyBy('status');

    expect($rows['EXPIRED']['expiredAt'])->not->toBeNull()
        ->and($rows['COMPLETED']['durationSeconds'])->toBe(3600)
        ->and($rows['CANCELLED']['cancelledBy'])->toBe('DRIVER');
});

it('filters a driver’s bookings by commute, day and status, and names the passenger by first name', function () {
    $first = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    $otherDay = ScheduledTrip::query()->orderBy('trip_date')->skip(1)->firstOrFail();
    dcRider($this->driverToken, $this->commuteId, $otherDay->id, '01155667788');

    dcPost($this->driverToken, "/api/v1/driver/bookings/{$first['bookingId']}/cancel", ['reason' => 'Changed my plans']);

    expect(dcGet($this->driverToken, "/api/v1/driver/bookings?commuteId={$this->commuteId}")->assertOk()->json('data'))->toHaveCount(2);

    $day = dcGet($this->driverToken, "/api/v1/driver/bookings?tripId={$otherDay->id}&status=confirmed")->assertOk()->json('data');

    expect($day)->toHaveCount(1)
        ->and($day[0]['passenger']['publicFirstName'])->not->toBeNull()
        ->and($day[0]['passenger'])->not->toHaveKey('phone')
        ->and($day[0]['price'])->toHaveKey('driverSharePiastres')
        ->and($day[0])->toHaveKeys(['meetingPoint', 'dropoffPoint', 'pickupEtaLocal']);

    dcGet($this->driverToken, '/api/v1/driver/bookings?status=nonsense')->assertStatus(422);
});

it('previews the fee for removing one passenger, from the same rule the cancel runs', function () {
    $rider = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');

    $this->trip->forceFill(['departure_at' => now()->addHours(5)])->save();

    $preview = dcGet($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/cancel-preview")->assertOk()->json('data');

    expect($preview['reason'])->toBe('short_notice')
        ->and($preview['affectedSeats'])->toBe(1)
        ->and($preview['feePiastres'])->toBeGreaterThan(0);

    dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/cancel", ['reason' => 'Changed my plans'])->assertOk();

    expect((int) DebtEntry::query()->where('idempotency_key', "driver_cancel:{$rider['bookingId']}")->value('amount_piastres'))->toBe($preview['feePiastres']);

    dcGet($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/cancel-preview")->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_NOT_CANCELLABLE');
    dcGet(verifiedPassenger('01100000002', device: 'dc-x'), "/api/v1/driver/bookings/{$rider['bookingId']}/cancel-preview")->assertNotFound();
});

it('applies a change at once when nobody is booked', function () {
    $proposal = dcPost($this->driverToken, "/api/v1/driver/commutes/{$this->commuteId}/change-proposals", [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'promisedArrivalTime' => '10:00:00',
    ])->assertStatus(201)->json('data');

    expect($proposal['status'])->toBe('APPLIED')
        ->and($proposal['passengers'])->toBe([]);

    $trip = $this->trip->refresh();

    expect(substr(dcGet($this->driverToken, "/api/v1/commutes/{$this->commuteId}")->json('data.upcomingTrips.0.promisedArrivalLocal'), 11))->toBe('10:00:00')
        ->and($trip->departure_at->lessThan($trip->promised_arrival_at))->toBeTrue();
});

it('moves the computed departure with an agreed arrival, and shows each pickup before and after', function () {
    $rider = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    $trip = $this->trip->refresh();
    $trip->forceFill(['promised_arrival_at' => $trip->departure_at->copy()->addHours(4)])->save();

    CommuteWeekdaySchedule::query()->where('commute_offer_id', $this->commuteId)->update([
        'departure_is_computed' => true,
        'promised_arrival_time' => $trip->promised_arrival_at->copy()->timezone('Africa/Cairo')->format('H:i:s'),
    ]);

    $arrival = $trip->promised_arrival_at->copy()->timezone('Africa/Cairo');
    $newArrival = $arrival->copy()->subMinutes(30)->format('H:i:s');
    $departureBefore = $trip->departure_at->copy();

    $proposal = dcPost($this->driverToken, "/api/v1/driver/commutes/{$this->commuteId}/change-proposals", [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'promisedArrivalTime' => $newArrival,
    ])->assertStatus(201)->json('data');

    $row = $proposal['passengers'][0];

    expect($proposal['status'])->toBe('PENDING')
        ->and($row['bookingId'])->toBe($rider['bookingId'])
        ->and($row)->toHaveKeys(['previousPickupLocal', 'newPickupLocal', 'pickupLabel', 'tripDate']);

    if ($row['previousPickupLocal'] !== null) {
        expect(strtotime('2000-01-01 '.$row['previousPickupLocal']) - strtotime('2000-01-01 '.$row['newPickupLocal']))->toBe(1800);
    }

    dcPost($rider['token'], "/api/v1/change-proposals/{$proposal['id']}/respond", ['response' => 'accepted'])->assertOk();

    $trip->refresh();

    expect($trip->departure_at->equalTo($departureBefore))->toBeFalse()
        ->and($trip->promised_arrival_at->copy()->timezone('Africa/Cairo')->format('H:i:s'))->toBe($newArrival)
        ->and($trip->departure_at->lessThan($trip->promised_arrival_at))->toBeTrue();
});

it('finds a commute’s proposal with the commuteId filter', function () {
    dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    dcPost($this->driverToken, "/api/v1/driver/commutes/{$this->commuteId}/change-proposals", [
        'scheduledTripId' => $this->tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '06:30:00',
    ])->assertStatus(201);

    expect(dcGet($this->driverToken, "/api/v1/driver/change-proposals?commuteId={$this->commuteId}")->json('data'))->toHaveCount(1)
        ->and(dcGet($this->driverToken, '/api/v1/driver/change-proposals?commuteId=01JABCDEFGHJKMNPQRSTVWXYZ0')->json('data'))->toBe([]);
});

it('refuses a pickup far off the route on a proposal', function () {
    dcPost($this->driverToken, "/api/v1/driver/commutes/{$this->commuteId}/change-proposals", [
        'scheduledTripId' => $this->tripId,
        'scope' => 'all_future_on_this_weekday',
        'pickupPoints' => [['lat' => 31.2, 'lng' => 29.9, 'address' => 'Alexandria']],
    ])->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_ROUTE_INVALID');
});

it('changes the seats on one published day only', function () {
    $other = ScheduledTrip::query()->orderBy('trip_date')->skip(1)->firstOrFail();

    test()->flushHeaders()->withToken($this->driverToken)->patchJson("/api/v1/driver/commutes/{$this->commuteId}/seats", [
        'seatsTotal' => 1,
        'scope' => 'only_this_occurrence',
        'scheduledTripId' => $this->tripId,
    ])->assertOk()->assertJsonPath('data.updatedDays', 1);

    expect($this->trip->refresh()->seats_total)->toBe(1)
        ->and($other->refresh()->seats_total)->toBe(2);
});

it('changes one weekday’s seats on generated days and later ones', function () {
    $sameDay = ScheduledTrip::query()->whereRaw('WEEKDAY(trip_date) = WEEKDAY(?)', [$this->trip->trip_date->toDateString()])->count();

    test()->flushHeaders()->withToken($this->driverToken)->patchJson("/api/v1/driver/commutes/{$this->commuteId}/seats", [
        'seatsTotal' => 1,
        'scope' => 'all_future_on_this_weekday',
        'scheduledTripId' => $this->tripId,
    ])->assertOk()->assertJsonPath('data.updatedDays', $sameDay);

    expect(CommuteWeekdaySchedule::query()->where('commute_offer_id', $this->commuteId)->where('seats_total', 1)->count())->toBe(1);
});

it('refuses fewer seats than are taken and more than the car has', function () {
    dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    dcRider($this->driverToken, $this->commuteId, $this->tripId, '01155667788');

    test()->flushHeaders()->withToken($this->driverToken)->patchJson("/api/v1/driver/commutes/{$this->commuteId}/seats", [
        'seatsTotal' => 1,
        'scope' => 'all_future_days',
    ])->assertStatus(409)->assertJsonPath('error.code', 'COMMUTE_SEATS_CONFLICT')->assertJsonPath('error.fields.seatsTotal.0', '2');

    expect($this->trip->refresh()->seats_total)->toBe(2);

    test()->flushHeaders()->withToken($this->driverToken)->patchJson("/api/v1/driver/commutes/{$this->commuteId}/seats", [
        'seatsTotal' => 7,
        'scope' => 'all_future_days',
    ])->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_SEATS_EXCEED_APPROVED');
});

it('keeps the passenger’s seat until the replacement accepts, then charges the driver', function () {
    $this->trip->forceFill(['seats_total' => 1])->save();
    $rider = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    $waiting = dcWaiting($this->driverToken, $this->commuteId, $this->tripId, '01155667788');

    $this->trip->forceFill(['departure_at' => now()->addHours(30)])->save();

    $booking = dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => $waiting['requestId']])
        ->assertStatus(201)
        ->assertJsonPath('data.replacementOffer.status', 'OPEN')
        ->assertJsonPath('data.status', 'CONFIRMED')
        ->json('data');

    $offerId = $booking['replacementOffer']['offerId'];

    dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => $waiting['requestId']])
        ->assertStatus(409)->assertJsonPath('error.code', 'REPLACEMENT_ALREADY_OPEN');

    expect(Booking::findOrFail($rider['bookingId'])->status)->toBe(BookingStatus::Confirmed);

    dcPost($waiting['token'], "/api/v1/seat-offers/{$offerId}/accept")->assertOk();

    expect(Booking::findOrFail($rider['bookingId'])->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(SeatOffer::findOrFail($offerId)->status->value)->toBe('accepted')
        ->and($this->trip->refresh()->seats_taken)->toBe(1)
        ->and(ReliabilityEvent::query()->where('booking_id', $rider['bookingId'])->exists())->toBeTrue();
});

it('leaves the passenger and the driver untouched when the replacement declines', function () {
    $this->trip->forceFill(['seats_total' => 1, 'departure_at' => now()->addHours(5)])->save();
    $rider = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    $waiting = dcWaiting($this->driverToken, $this->commuteId, $this->tripId, '01155667788');

    $this->trip->forceFill(['departure_at' => now()->addHours(30)])->save();

    $offerId = dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => $waiting['requestId']])
        ->assertStatus(201)->json('data.replacementOffer.offerId');

    dcPost($waiting['token'], "/api/v1/seat-offers/{$offerId}/decline")->assertOk();

    expect(Booking::findOrFail($rider['bookingId'])->status)->toBe(BookingStatus::Confirmed)
        ->and(DebtEntry::query()->where('idempotency_key', "driver_cancel:{$rider['bookingId']}")->exists())->toBeFalse()
        ->and(ReliabilityEvent::query()->where('booking_id', $rider['bookingId'])->exists())->toBeFalse();

    $row = collect(dcGet($this->driverToken, "/api/v1/driver/bookings?tripId={$this->tripId}")->json('data'))->firstWhere('id', $rider['bookingId']);

    expect($row['replacementOffer']['status'])->toBe('DECLINED');
});

it('lets the driver withdraw it, and closes it when the passenger leaves first', function () {
    $this->trip->forceFill(['seats_total' => 1, 'departure_at' => now()->addHours(30)])->save();
    $rider = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');
    $waiting = dcWaiting($this->driverToken, $this->commuteId, $this->tripId, '01155667788');

    $offerId = dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => $waiting['requestId']])
        ->json('data.replacementOffer.offerId');

    test()->flushHeaders()->withToken($this->driverToken)->deleteJson("/api/v1/driver/seat-offers/{$offerId}")->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');

    $again = dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => $waiting['requestId']])
        ->assertStatus(201)->json('data.replacementOffer.offerId');

    test()->flushHeaders()->withToken($rider['token'])->patchJson("/api/v1/bookings/{$rider['bookingId']}/cancel")->assertOk();

    expect(SeatOffer::findOrFail($again)->status->value)->toBe('closed');

    dcPost($waiting['token'], "/api/v1/seat-offers/{$again}/accept")->assertStatus(409)->assertJsonPath('error.code', 'SEAT_OFFER_CLOSED');
});

it('refuses someone who isn’t waiting, and a different stop inside the lock', function () {
    $this->trip->forceFill(['seats_total' => 1])->save();
    $rider = dcRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344');

    dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => '01JABCDEFGHJKMNPQRSTVWXYZ0'])->assertNotFound();

    $waiting = dcWaiting($this->driverToken, $this->commuteId, $this->tripId, '01155667788');

    SeatRequest::query()->whereKey($waiting['requestId'])->update(['requested_pickup_lat' => 30.5]);

    $this->trip->forceFill(['departure_at' => now()->addHours(5)])->save();

    dcPost($this->driverToken, "/api/v1/driver/bookings/{$rider['bookingId']}/replacement-offer", ['seatRequestId' => $waiting['requestId']])
        ->assertStatus(409)->assertJsonPath('error.code', 'REPLACEMENT_CHANGES_OTHERS');
});
