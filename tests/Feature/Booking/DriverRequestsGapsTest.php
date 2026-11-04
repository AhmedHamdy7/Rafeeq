<?php

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 2]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $this->paxToken = verifiedPassenger('01112223344');
});

function driverWaitlistMember(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): string
{
    $id = requestSeat(verifiedPassenger($phone, device: $device), $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)
        ->json('data.id');

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$id}/waitlist")
        ->assertOk();

    return $id;
}

function offerRound(string $tripId, array $statuses): string
{
    $release = (string) Str::ulid();

    foreach ($statuses as $seatRequestId => $status) {
        $offer = new SeatOffer;
        $offer->forceFill([
            'scheduled_trip_id' => $tripId,
            'seat_request_id' => $seatRequestId,
            'status' => $status,
            'release_event_id' => $release,
        ])->save();
    }

    return $release;
}

function addAdvertisedPickup(string $commuteId): void
{
    CommuteLocation::query()->create([
        'commute_offer_id' => $commuteId,
        'type' => 'pickup',
        'point' => new Coordinate(30.0620, 31.3700),
        'lat' => 30.0620,
        'lng' => 31.3700,
        'address' => 'Al Rehab Mall service road',
        'sequence' => 1,
        'is_exact' => true,
    ]);
}

it('puts the day, the route and the impact on a pending inbox row', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201);

    $row = test()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/seat-requests')
        ->assertOk()
        ->json('data.0');

    expect($row['tripDate'])->toBe($this->trip->trip_date->toDateString())
        ->and($row['departureLocal'])->toBe($this->trip->departure_local->format('Y-m-d H:i:s'))
        ->and($row['originLabel'])->not->toBeNull()
        ->and($row['destinationLabel'])->not->toBeNull()
        ->and($row['impact']['tripId'])->toBe($this->trip->id)
        ->and($row['impact']['addedMinutes'])->toBeInt()
        ->and($row['impact']['maxDetourMinutes'])->toBe(10)
        ->and($row['impact']['canAccept'])->toBeTrue()
        ->and($row['impact']['blockReason'])->toBeNull();
});

it('uses the next requested weekday for a recurring request', function () {
    $wanted = ScheduledTrip::query()->orderBy('trip_date')->skip(1)->firstOrFail();

    requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => 1 << (($wanted->trip_date->dayOfWeek + 1) % 7),
    ])->assertStatus(201);

    $row = test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-requests')->json('data.0');

    $expected = ScheduledTrip::query()
        ->whereDate('trip_date', '>=', now()->toDateString())
        ->where('departure_at', '>', now())
        ->orderBy('departure_at')
        ->get()
        ->first(fn (ScheduledTrip $trip) => $trip->trip_date->dayOfWeek === $wanted->trip_date->dayOfWeek);

    expect($row['tripDate'])->toBe($expected->trip_date->toDateString())
        ->and($row['impact']['tripDate'])->toBe($expected->trip_date->toDateString());
});

it('blocks accept on the row exactly when the approval would be refused', function () {
    $id = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    $this->trip->forceFill([
        'departure_at' => now()->addHours(2),
        'promised_arrival_at' => now()->addHours(2)->addMinute(),
    ])->save();

    $impact = test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-requests')->json('data.0.impact');

    expect($impact['canAccept'])->toBeFalse()
        ->and($impact['blockReason'])->toBe('promised_arrival')
        ->and($impact['promisedArrivalLocal'])->not->toBeNull()
        ->and($impact['projectedArrivalLocal'])->not->toBeNull();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_BREAKS_FROZEN_PROMISE');
});

it('sends no impact on a decided row', function () {
    $id = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    test()->withToken($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$id}/reject")->assertOk();

    $row = test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-requests')->json('data.0');

    expect($row['impact'])->toBeNull()->and($row['tripDate'])->not->toBeNull();
});

it('also answers the impact on the route preview', function () {
    $id = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/seat-requests/{$id}/route-preview")
        ->assertOk()
        ->assertJsonPath('data.status', 'PREVIEW_NOT_CONFIRMED')
        ->assertJsonPath('data.impact.tripId', $this->trip->id)
        ->assertJsonPath('data.impact.canAccept', true);
});

it('filters the inbox by status and counts the filtered list', function () {
    $rejected = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');
    test()->withToken($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$rejected}/reject")->assertOk();

    $pending = requestSeat(verifiedPassenger('01112223355', device: 'pax-2'), $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    $filtered = test()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/seat-requests?status=PENDING,counteroffered')
        ->assertOk();

    expect(collect($filtered->json('data'))->pluck('id')->all())->toBe([$pending])
        ->and($filtered->json('meta.total'))->toBe(1);

    test()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/seat-requests?status=nonsense')
        ->assertStatus(422);
});

it('lists the waiting list across commutes with the new row fields and day chips', function () {
    $second = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 2], ['departureTime' => '17:30:00']);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$second}/publish")->assertOk();
    $secondTrip = ScheduledTrip::query()->where('commute_offer_id', $second)->orderBy('trip_date')->firstOrFail();

    $first = driverWaitlistMember($this->driverToken, $this->commuteId, $this->trip->id, '01112223366', 'pax-3');
    driverWaitlistMember($this->driverToken, $second, $secondTrip->id, '01112223377', 'pax-4');

    SeatRequest::query()->whereKey($first)->update(['unavailable_on' => now('Africa/Cairo')->toDateString()]);
    $this->trip->forceFill(['seats_taken' => $this->trip->seats_total])->save();

    $response = test()->withToken($this->driverToken)->getJson('/api/v1/driver/waitlist')->assertOk();
    $row = collect($response->json('data'))->firstWhere('seatRequestId', $first);
    $chip = collect($response->json('meta.days'))->firstWhere('tripId', $this->trip->id);

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('meta.waitingCount'))->toBe(2)
        ->and($row['pickup']['label'])->toBe('Ring Road, Rehab exit')
        ->and($row['dropoff']['label'])->toBe('Mehwar, Sheikh Zayed')
        ->and($row['availableToday'])->toBeFalse()
        ->and($row['tripDate'])->toBe($this->trip->trip_date->toDateString())
        ->and($row['offer'])->toBeNull()
        ->and($chip['isFull'])->toBeTrue();

    $day = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/waitlist?tripId={$this->trip->id}")
        ->assertOk();

    expect(collect($day->json('data'))->pluck('seatRequestId')->all())->toBe([$first]);
});

it('refuses a day chip from another driver', function () {
    $other = approvedDriver('01099998888', 'dev-2', 2);

    test()->withToken($other)
        ->getJson("/api/v1/driver/waitlist?tripId={$this->trip->id}")
        ->assertNotFound();
});

it('answers empty waiting list and offers for a driver with no commute', function () {
    $newcomer = verifiedPassenger('01112223388', device: 'pax-5');

    test()->withToken($newcomer)->getJson('/api/v1/driver/waitlist')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.days', []);
    test()->withToken($newcomer)->getJson('/api/v1/driver/seat-offers')->assertOk()->assertJsonPath('data', []);
    test()->withToken($newcomer)->getJson('/api/v1/driver/seat-requests')->assertOk()->assertJsonPath('meta.total', 0);
});

it('shows who accepted and hides who declined', function () {
    $laila = driverWaitlistMember($this->driverToken, $this->commuteId, $this->trip->id, '01112223366', 'pax-3');
    $mona = driverWaitlistMember($this->driverToken, $this->commuteId, $this->trip->id, '01112223377', 'pax-4');

    offerRound($this->trip->id, [$laila => SeatOfferStatus::Accepted->value, $mona => SeatOfferStatus::TakenByOther->value]);

    $round = test()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/seat-offers?status=accepted')
        ->assertOk()
        ->json('data.0');

    expect($round['status'])->toBe('ACCEPTED')
        ->and($round['recipientsCount'])->toBe(2)
        ->and($round['acceptedBy']['seatRequestId'])->toBe($laila)
        ->and(collect($round['members'])->pluck('status', 'seatRequestId')->all())
        ->toBe([$laila => 'CONFIRMED', $mona => 'OFFER_CLOSED']);
});

it('reads a decline as still offered until someone accepts', function () {
    $laila = driverWaitlistMember($this->driverToken, $this->commuteId, $this->trip->id, '01112223366', 'pax-3');
    $mona = driverWaitlistMember($this->driverToken, $this->commuteId, $this->trip->id, '01112223377', 'pax-4');

    offerRound($this->trip->id, [$laila => SeatOfferStatus::Open->value, $mona => SeatOfferStatus::Declined->value]);

    $round = test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-offers?status=open')->assertOk()->json('data.0');

    expect(collect($round['members'])->pluck('status')->unique()->all())->toBe(['OFFERED'])
        ->and($round['acceptedBy'])->toBeNull();

    $rows = test()->withToken($this->driverToken)->getJson('/api/v1/driver/waitlist')->json('data');

    expect(collect($rows)->firstWhere('seatRequestId', $mona)['offer']['status'])->toBe('OFFERED')
        ->and(collect($rows)->firstWhere('seatRequestId', $laila)['offer']['date'])->toBe($this->trip->trip_date->toDateString());
});

it('does not list offers for a day that has left', function () {
    $laila = driverWaitlistMember($this->driverToken, $this->commuteId, $this->trip->id, '01112223366', 'pax-3');

    offerRound($this->trip->id, [$laila => SeatOfferStatus::Accepted->value]);
    $this->trip->forceFill(['departure_at' => now()->subMinute()])->save();

    test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-offers')->assertOk()->assertJsonPath('data', []);
});

it('uses the moment she joined the list on both shapes', function () {
    $passenger = verifiedPassenger('01112223366', device: 'pax-3');
    $id = requestSeat($passenger, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    SeatRequest::query()->whereKey($id)->update(['created_at' => now()->subDays(5)]);

    test()->withToken($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$id}/waitlist")->assertOk();

    $asDriver = test()->withToken($this->driverToken)->getJson('/api/v1/driver/waitlist')->json('data.0.joinedWaitlistAt');
    $asPassenger = test()->withToken($passenger)->getJson('/api/v1/seat-requests')->json('data.0.joinedWaitlistAt');
    $answered = SeatRequest::query()->findOrFail($id)->responded_at->toIso8601String();

    expect($asDriver)->toBe($answered)->and($asPassenger)->toBe($answered);
});

it('costs each advertised stop for a counteroffer', function () {
    addAdvertisedPickup($this->commuteId);

    $id = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    $options = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/seat-requests/{$id}/counteroffer-options")
        ->assertOk()
        ->json('data');

    $stops = CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->where('type', 'pickup')->count();

    expect($options['pickups'])->toHaveCount($stops)
        ->and($options['tripDate'])->toBe($this->trip->trip_date->toDateString())
        ->and($options['canDropPin'])->toBeTrue()
        ->and($options['requested']['pickup']['label'])->toBe('Ring Road, Rehab exit')
        ->and($options['requested']['addedMinutes'])->toBeInt()
        ->and($options['route'])->toHaveKeys(['polyline', 'distanceMeters', 'durationSeconds']);

    expect($options['pickups'][0])->toHaveKeys([
        'suggestionId', 'label', 'lat', 'lng', 'addedMinutes', 'changeFromRequestedMinutes', 'walkMeters', 'withinLimit', 'refusalCode',
    ])->and($options['pickups'][0]['walkMeters'])->toBeGreaterThan(0);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$id}/counteroffer", ['pickup' => ['suggestionId' => $options['pickups'][0]['suggestionId']]])
        ->assertOk();
});

it('never offers the drivers own start or end as a counteroffer option', function () {
    $id = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    $options = test()->withToken($this->driverToken)->getJson("/api/v1/driver/seat-requests/{$id}/counteroffer-options")->json('data');

    $endpoints = CommuteLocation::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereIn('type', ['origin', 'destination'])
        ->pluck('id')
        ->map(fn ($key) => (string) $key)
        ->all();

    expect(array_intersect(collect([...$options['pickups'], ...$options['dropoffs']])->pluck('suggestionId')->all(), $endpoints))->toBe([]);
});

it('refuses options once the request is answered, and for another driver', function () {
    $id = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->json('data.id');

    test()->withToken(approvedDriver('01099998888', 'dev-2', 2))
        ->getJson("/api/v1/driver/seat-requests/{$id}/counteroffer-options")
        ->assertNotFound();

    SeatRequest::query()->whereKey($id)->update(['status' => SeatRequestStatus::Rejected->value]);

    test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/seat-requests/{$id}/counteroffer-options")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});
