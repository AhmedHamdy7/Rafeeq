<?php

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Place;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = createCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true])
        ->assertStatus(201)->json('data.id');

    saveRoute($this->driverToken, $this->commuteId, [
        'pickups' => [['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road, Rehab exit']],
        'dropoffs' => [['lat' => 30.0684, 'lng' => 31.1015, 'address' => 'Mehwar, Sheikh Zayed']],
    ])->assertOk();
    saveSchedule($this->driverToken, $this->commuteId)->assertOk();

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344');
});

function commuteDetail(string $token, string $commuteId, string $language = 'ar')
{
    return test()->flushHeaders()->withToken($token)->withHeader('Accept-Language', $language)
        ->getJson("/api/v1/commutes/{$commuteId}/public")->assertOk();
}

it('sends rules as an object even when the commute has none', function () {
    CommuteOffer::query()->findOrFail($this->commuteId)->rules()->delete();

    expect(commuteDetail($this->paxToken, $this->commuteId)->getContent())->toContain('"rules":{}');
});

it('tells a woman she may request the commute', function () {
    expect(commuteDetail($this->paxToken, $this->commuteId)->json('data.requestEligibility'))
        ->toBe(['canRequest' => true, 'reason' => null]);
});

it('tells a man he may not request a women-only commute', function () {
    CommuteOffer::query()->whereKey($this->commuteId)->update(['audience' => CommuteAudience::WomenOnly->value]);
    $manToken = verifiedPassenger('01112225566', gender: 'man', device: 'pax-man');

    expect(commuteDetail($manToken, $this->commuteId)->json('data.requestEligibility'))
        ->toBe(['canRequest' => false, 'reason' => 'women_only']);
});

it('tells her she already has an open request', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => ScheduledTrip::query()->orderBy('departure_at')->firstOrFail()->id,
    ])->assertStatus(201);

    expect(commuteDetail($this->paxToken, $this->commuteId)->json('data.requestEligibility'))
        ->toBe(['canRequest' => false, 'reason' => 'already_requested']);
});

it('gives every upcoming day its own time at each suggested point', function () {
    $detail = commuteDetail($this->paxToken, $this->commuteId)->json('data');
    $pointIds = collect([...$detail['pickups'], ...$detail['dropoffs']])->pluck('id');
    $day = $detail['upcomingTrips'][0];
    $trip = ScheduledTrip::query()->findOrFail($day['id']);

    expect(collect($day['pointTimes'])->pluck('pointId')->all())->toBe($pointIds->all());

    foreach ($day['pointTimes'] as $time) {
        expect(strtotime($time['estimatedAt']))->toBeGreaterThanOrEqual($trip->departure_at->getTimestamp())
            ->and($time['estimatedLocal'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    }
});

it('names areas and the car colour in the request language', function () {
    Vehicle::sole()->forceFill(['colour' => 'فضي'])->save();
    $place = Place::factory()->create(['name' => 'Rehab Gate 2', 'name_ar' => 'الرحاب بوابة ٢', 'district' => 'القاهرة الجديدة']);
    CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->where('type', 'origin')->update(['place_id' => $place->id]);

    $ar = commuteDetail($this->paxToken, $this->commuteId, 'ar')->json('data');
    $en = commuteDetail($this->paxToken, $this->commuteId, 'en')->json('data');

    expect($ar['vehicle']['colour'])->toBe('فضي')
        ->and($en['vehicle']['colour'])->toBe('Silver')
        ->and($ar['route']['start']['label'])->toBe('القاهرة الجديدة')
        ->and($en['route']['start']['label'])->toBe('New Cairo');
});

it('accepts music as a commute rule and returns it', function () {
    $draftId = createCommute($this->driverToken, Vehicle::sole()->id)->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/commutes/{$draftId}", ['rules' => ['music' => true, 'quiet' => true]])
        ->assertOk()
        ->assertJsonPath('data.rules.music', true);
});

it('works out the meeting preference when the app leaves it out', function () {
    $detail = commuteDetail($this->paxToken, $this->commuteId)->json('data');
    $days = $detail['upcomingTrips'];

    $suggested = requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $days[0]['id'],
        'meetingPreference' => null,
        'pickup' => ['suggestionId' => $detail['pickups'][0]['id']],
    ])->assertStatus(201);

    expect($suggested->json('data.meetingPreference'))->toBe('landmark');

    $otherToken = verifiedPassenger('01112227788', device: 'pax-pin');

    $pin = requestSeat($otherToken, $this->commuteId, [
        'scheduledTripId' => $days[0]['id'],
        'meetingPreference' => null,
    ])->assertStatus(201);

    expect($pin->json('data.meetingPreference'))->toBe('custom');
});

it('names her meeting point in the request language when it is a catalogue place', function () {
    $place = Place::factory()->create(['name' => 'Rehab Main Gate', 'name_ar' => 'بوابة الرحاب الرئيسية', 'district' => 'القاهرة الجديدة']);
    CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->where('type', 'origin')->update(['place_id' => $place->id]);

    $criteria = searchCriteria(['origin' => ['lat' => 30.0594, 'lng' => 31.4913]]);

    $en = test()->flushHeaders()->withToken($this->paxToken)->withHeader('Accept-Language', 'en')
        ->getJson('/api/v1/search/commutes?'.http_build_query($criteria))->assertOk()->json('data.0.meetingPoint.address');
    $ar = test()->flushHeaders()->withToken($this->paxToken)->withHeader('Accept-Language', 'ar')
        ->getJson('/api/v1/search/commutes?'.http_build_query($criteria))->assertOk()->json('data.0.meetingPoint.address');

    expect($en)->toBe('Rehab Main Gate')->and($ar)->not->toBe('Rehab Main Gate');
});

it('names the demand a match came from, in the request language', function () {
    $from = Place::factory()->create(['name' => 'Rehab', 'name_ar' => 'الرحاب', 'lat' => 30.0594, 'lng' => 31.4913]);
    $to = Place::factory()->create(['name' => 'Smart Village', 'name_ar' => 'القرية الذكية', 'lat' => 30.0714, 'lng' => 30.9716]);

    test()->flushHeaders()->withToken($this->paxToken)->postJson('/api/v1/commute-demands', [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'placeId' => $from->id],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'placeId' => $to->id],
        'daysMask' => 62,
        'arrivalWindowStart' => '07:05',
        'arrivalWindowEnd' => '07:05',
        'flexibilityMinutes' => 30,
    ])->assertStatus(201);

    $title = fn (string $language) => test()->flushHeaders()->withToken($this->paxToken)->withHeader('Accept-Language', $language)
        ->getJson('/api/v1/matches')->assertOk()->json('data.0.demandTitle');

    expect($title('en'))->toBe('Rehab → Smart Village')
        ->and($title('ar'))->toBe('الرحاب → القرية الذكية');
});
