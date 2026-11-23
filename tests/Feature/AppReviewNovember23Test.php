<?php

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Area;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Verification\Enums\VerificationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->vehicleId = Vehicle::sole()->id;
    $this->driver = User::query()->findOrFail(DriverProfile::sole()->user_id);
});

function reviewPublishedCommute(string $token, string $vehicleId, array $route = [], array $overrides = []): string
{
    $id = createCommute($token, $vehicleId, $overrides)->assertStatus(201)->json('data.id');
    saveRoute($token, $id, $route)->assertOk();
    saveSchedule($token, $id)->assertOk();
    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    return $id;
}

function reviewNasrCity(): array
{
    return ['placeId' => 'ChIJ-nasr-city', 'name' => 'Nasr City', 'city' => 'Cairo'];
}

/*
| Item 30 — women only for verified female drivers
*/

it('lets only a verified woman mark a commute women-only', function () {
    test()->withToken($this->driverToken)->getJson('/api/v1/commutes/options')
        ->assertOk()->assertJsonPath('data.canMarkWomenOnly', true);

    $this->driver->verifications()->where('type', VerificationType::GovernmentId->value)->update(['status' => 'pending']);

    test()->withToken($this->driverToken)->getJson('/api/v1/commutes/options')
        ->assertOk()->assertJsonPath('data.canMarkWomenOnly', false);

    createCommute($this->driverToken, $this->vehicleId)->assertStatus(422);
});

it('refuses women-only from a male driver', function () {
    $this->driver->forceFill(['gender' => 'man'])->save();

    test()->withToken($this->driverToken)->getJson('/api/v1/commutes/options')
        ->assertOk()->assertJsonPath('data.canMarkWomenOnly', false);

    createCommute($this->driverToken, $this->vehicleId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_WOMEN_ONLY_NOT_ALLOWED')
        ->assertJsonStructure(['error' => ['fields' => ['audience']]]);
});

it('resets women-only commutes of drivers who are not verified women', function () {
    $id = reviewPublishedCommute($this->driverToken, $this->vehicleId);
    $this->driver->forceFill(['gender' => 'man'])->save();

    (require database_path('migrations/2026_11_23_100000_reset_women_only_and_default_commute_ac.php'))->up();

    expect(CommuteOffer::query()->findOrFail($id)->audience->value)->toBe('any_verified')
        ->and(CommuteOffer::query()->findOrFail($id)->rules()->where('rule_key', 'ac')->value('rule_value'))->toBeTruthy();
});

/*
| Item 11 — per-commute A/C
*/

it('takes the A/C per commute, refuses it on a car without A/C, and searches by it', function () {
    $id = reviewPublishedCommute($this->driverToken, $this->vehicleId, overrides: ['rules' => ['ac' => false, 'quiet' => true]]);

    $results = search(passenger('01112223344'))->assertOk()->json('data');

    expect($results[0]['rules'])->toHaveCount(2)->toMatchArray(['quiet' => true, 'ac' => false])
        ->and(search(passenger('01112223344'), ['rules' => ['ac']])->json('data'))->toBe([]);

    Vehicle::query()->whereKey($this->vehicleId)->update(['has_ac' => false]);

    createCommute($this->driverToken, $this->vehicleId, ['rules' => ['ac' => true]])
        ->assertJsonPath('error.code', 'COMMUTE_AC_NOT_AVAILABLE');

    expect(CommuteOffer::query()->findOrFail($id)->runsAc())->toBeFalse();
});

it('uses the car\'s A/C for a commute that never chose', function () {
    reviewPublishedCommute($this->driverToken, $this->vehicleId);

    expect(search(passenger('01112223344'), ['rules' => ['ac']])->assertOk()->json('data.0.rules.ac'))->toBeTrue();
});

/*
| Item 15 — seats open
*/

it('returns full commutes unless a minimum of open seats is asked for', function () {
    reviewPublishedCommute($this->driverToken, $this->vehicleId);
    ScheduledTrip::query()->update(['seats_taken' => 3]);
    $token = passenger('01112223344');

    $full = search($token)->assertOk()->json('data.0');

    expect($full['isFull'])->toBeTrue()
        ->and($full['seatsAvailable'])->toBe(0)
        ->and(search($token, ['minSeatsOpen' => 1])->assertOk()->json('data'))->toBe([]);

    ScheduledTrip::query()->update(['seats_taken' => 1]);

    expect(search($token, ['minSeatsOpen' => 2])->json('data'))->toHaveCount(1)
        ->and(search($token, ['minSeatsOpen' => 3])->json('data'))->toBe([]);
});

/*
| Item 18 — travel dates
*/

it('searches a range of travel dates and says which of them the commute runs', function () {
    reviewPublishedCommute($this->driverToken, $this->vehicleId);
    $token = passenger('01112223344');

    $days = ScheduledTrip::query()->orderBy('trip_date')->pluck('trip_date')->map(fn ($d) => $d->toDateString())->values();
    $from = CarbonImmutable::parse($days[0]);
    $to = $from->addDays(4);

    $card = search($token, ['dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString(), 'daysMask' => 1])
        ->assertOk()->json('data.0');

    $expected = $days->filter(fn (string $d) => $d <= $to->toDateString())->values()->all();

    expect($card['matchedDates'])->toBe($expected);
});

it('refuses a travel range longer than five days, in the past, or past 90 days', function () {
    $token = passenger('01112223344');
    $today = CarbonImmutable::now('Africa/Cairo');

    search($token, ['dateFrom' => $today->toDateString(), 'dateTo' => $today->addDays(5)->toDateString()])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['dateTo']]]);
    search($token, ['dateFrom' => $today->subDay()->toDateString(), 'dateTo' => $today->toDateString()])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['dateFrom']]]);
    search($token, ['dateFrom' => $today->addDays(89)->toDateString(), 'dateTo' => $today->addDays(91)->toDateString()])
        ->assertStatus(422);
    search($token, ['dateFrom' => $today->toDateString(), 'dateTo' => $today->addDays(4)->toDateString()])
        ->assertOk();
});

/*
| Item 8 — Google places and areas
*/

it('stores the Google place and area of each route point and returns them', function () {
    $id = createCommute($this->driverToken, $this->vehicleId)->json('data.id');

    $locations = saveRoute($this->driverToken, $id, [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'Rehab Gate 2', 'googlePlaceId' => 'ChIJ-gate', 'area' => reviewNasrCity()],
    ])->assertOk()->json('data.locations');

    $origin = collect($locations)->firstWhere('type', 'origin');

    expect($origin['address'])->toBe('Rehab Gate 2')
        ->and($origin['googlePlaceId'])->toBe('ChIJ-gate')
        ->and($origin['area'])->toBe(reviewNasrCity())
        ->and(Area::query()->findOrFail('ChIJ-nasr-city')->name_en)->toBe('Nasr City');
});

it('keeps the area name in the language it was sent in', function () {
    $id = createCommute($this->driverToken, $this->vehicleId)->json('data.id');

    test()->withToken($this->driverToken)->withHeader('Accept-Language', 'ar')->putJson("/api/v1/commutes/{$id}/route", [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'بوابة ٢', 'area' => ['placeId' => 'ChIJ-nasr-city', 'name' => 'مدينة نصر', 'city' => 'القاهرة']],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'label' => 'Smart Village B6'],
    ])->assertOk();

    expect(Area::query()->findOrFail('ChIJ-nasr-city')->name('ar'))->toBe('مدينة نصر')
        ->and(Area::query()->findOrFail('ChIJ-nasr-city')->name_en)->toBeNull();
});

it('adds same-area commutes when the point search finds fewer than three', function () {
    $id = reviewPublishedCommute($this->driverToken, $this->vehicleId, [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'Rehab Gate 2', 'area' => reviewNasrCity()],
    ]);

    $farAway = ['lat' => 30.1100, 'lng' => 31.5600, 'label' => 'Far corner', 'area' => reviewNasrCity()];
    $token = passenger('01112223344');

    $response = search($token, ['origin' => $farAway])->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('areaMatches'))->toHaveCount(1)
        ->and($response->json('areaMatches.0.commuteId'))->toBe($id)
        ->and($response->json('areaMatches.0.matchKind'))->toBe('sameArea')
        ->and($response->json('areaMatches.0.areaName'))->toBe('Nasr City')
        ->and($response->json('areaMatches.0.distanceMetres'))->toBeGreaterThan(1000)
        ->and($response->json('meta.areaMatched'))->toBe(1);

    $query = searchCriteria();
    $query['origin'] = ['lat' => 30.1100, 'lng' => 31.5600, 'label' => 'Far corner', 'areaId' => 'ChIJ-nasr-city', 'areaName' => 'Nasr City', 'areaCity' => 'Cairo'];

    expect(test()->withToken($token)->getJson('/api/v1/search/commutes?'.http_build_query($query))->assertOk()->json('areaMatches.0.commuteId'))->toBe($id);
});

it('matches a saved demand in the same area and says where', function () {
    reviewPublishedCommute($this->driverToken, $this->vehicleId, [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'Rehab Gate 2', 'area' => reviewNasrCity()],
    ]);
    $token = passenger('01112223344');

    $demand = test()->withToken($token)->postJson('/api/v1/commute-demands', [
        'origin' => ['lat' => 30.1100, 'lng' => 31.5600, 'label' => 'Far corner', 'googlePlaceId' => 'ChIJ-corner', 'area' => reviewNasrCity()],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'label' => 'Smart Village'],
        'daysMask' => 62,
        'arriveBy' => '09:00',
    ])->assertStatus(201)->json('data');

    expect($demand['origin']['googlePlaceId'])->toBe('ChIJ-corner')
        ->and($demand['origin']['area'])->toBe(reviewNasrCity())
        ->and(CommuteDemand::sole()->origin_area_id)->toBe('ChIJ-nasr-city')
        ->and(MatchNotification::sole()->match_kind)->toBe('sameArea');

    $match = test()->withToken($token)->getJson('/api/v1/matches')->assertOk()->json('data.0');

    expect($match['matchKind'])->toBe('sameArea')
        ->and($match['areaName'])->toBe('Nasr City');
});

it('keeps the Google place and area on a custom seat-request point', function () {
    $id = reviewPublishedCommute($this->driverToken, $this->vehicleId);
    $token = verifiedPassenger('01112223344');

    $trip = ScheduledTrip::query()->orderBy('departure_at')->firstOrFail();

    requestSeat($token, $id, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'Rehab Gate 2', 'googlePlaceId' => 'ChIJ-gate', 'area' => reviewNasrCity()],
    ])->assertStatus(201);

    expect(SeatRequest::sole()->requested_pickup_area_id)->toBe('ChIJ-nasr-city')
        ->and(SeatRequest::sole()->requested_pickup_google_place_id)->toBe('ChIJ-gate');
});

it('copies the area of a suggested point onto the seat request', function () {
    $id = reviewPublishedCommute($this->driverToken, $this->vehicleId, [
        'pickups' => [['lat' => 30.0420, 'lng' => 31.4100, 'label' => 'Ring Road exit', 'area' => reviewNasrCity()]],
    ]);
    $token = verifiedPassenger('01112223344');
    $suggestion = CommuteLocation::query()->where('type', 'pickup')->sole();

    requestSeat($token, $id, [
        'scheduledTripId' => ScheduledTrip::query()->orderBy('departure_at')->firstOrFail()->id,
        'pickup' => ['suggestionId' => $suggestion->id],
    ])->assertStatus(201);

    expect(SeatRequest::sole()->requested_pickup_area_id)->toBe('ChIJ-nasr-city');
});
