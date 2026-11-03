<?php

use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Place;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->paxToken = passenger('01112223344');
});

function demandBody(array $overrides = []): array
{
    return array_merge([
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'arrivalWindowStart' => '08:00:00',
        'arrivalWindowEnd' => '08:00:00',
        'flexibilityMinutes' => 30,
    ], $overrides);
}

function postDemand(string $token, array $overrides = [], string $language = 'en')
{
    return test()->flushHeaders()->withToken($token)->withHeader('Accept-Language', $language)
        ->postJson('/api/v1/commute-demands', demandBody($overrides));
}

function asDemandOwner(string $token, string $method, string $uri, array $body = [], string $language = 'en')
{
    return test()->flushHeaders()->withToken($token)->withHeader('Accept-Language', $language)->json($method, $uri, $body);
}

it('leaves deleted demands out of the list', function () {
    $kept = postDemand($this->paxToken)->assertStatus(201)->json('data.id');
    $deleted = postDemand($this->paxToken, ['daysMask' => 1])->assertStatus(201)->json('data.id');

    asDemandOwner($this->paxToken, 'DELETE', "/api/v1/commute-demands/{$deleted}")->assertOk();

    expect(collect(asDemandOwner($this->paxToken, 'GET', '/api/v1/commute-demands')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$kept]);
});

it('loads one demand for its owner only, and not once deleted', function () {
    $id = postDemand($this->paxToken)->assertStatus(201)->json('data.id');

    asDemandOwner($this->paxToken, 'GET', "/api/v1/commute-demands/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.matchCount', 0);

    $other = passenger('01112229999', device: 'pax-other');
    asDemandOwner($other, 'GET', "/api/v1/commute-demands/{$id}")->assertNotFound();

    asDemandOwner($this->paxToken, 'DELETE', "/api/v1/commute-demands/{$id}")->assertOk();
    asDemandOwner($this->paxToken, 'GET', "/api/v1/commute-demands/{$id}")->assertNotFound();
});

it('refuses the same demand twice and names the one she has', function () {
    $id = postDemand($this->paxToken)->assertStatus(201)->json('data.id');

    postDemand($this->paxToken)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DEMAND_EXISTS')
        ->assertJsonPath('error.fields.demandId.0', $id);

    $second = postDemand($this->paxToken, ['daysMask' => 1])->assertStatus(201)->json('data.id');

    asDemandOwner($this->paxToken, 'PATCH', "/api/v1/commute-demands/{$second}", demandBody())->assertStatus(409);
});

it('refuses a demand from a place to itself, in her language', function () {
    $same = ['origin' => ['lat' => 30.0602, 'lng' => 31.4915], 'destination' => ['lat' => 30.0605, 'lng' => 31.4917]];

    postDemand($this->paxToken, $same)
        ->assertStatus(422)
        ->assertJsonPath('error.fields.destination.0', 'The destination must be different from the origin.');

    postDemand($this->paxToken, $same, 'ar')
        ->assertStatus(422)
        ->assertJsonPath('error.fields.destination.0', 'لازم الوجهة تكون مختلفة عن نقطة البداية.');
});

it('fills in the walk and detour limits and takes a short time', function () {
    $demand = postDemand($this->paxToken, ['arrivalWindowStart' => '08:00', 'arrivalWindowEnd' => '08:00'])->assertStatus(201)->json('data');

    expect($demand['maxWalkMinutes'])->toBe(10)
        ->and($demand['maxDetourMinutes'])->toBe(15)
        ->and($demand['arrivalWindowStart'])->toBe('08:00:00')
        ->and($demand['arrivalWindowEnd'])->toBe('08:00:00')
        ->and($demand['flexibilityMinutes'])->toBe(30);
});

it('lets only a woman save a women-only demand, and keeps both fields in step', function () {
    $man = passenger('01112225566', gender: 'man', device: 'pax-man');

    postDemand($man, ['womenOnly' => true])->assertStatus(422)->assertJsonPath('error.fields.womenOnly.0', fn ($m) => $m !== null);

    postDemand($this->paxToken, ['womenOnly' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.womenOnly', true)
        ->assertJsonPath('data.audiencePreference', 'women_only');
});

it('gives a fresh expiry on resume and on edit, and revives an expired demand', function () {
    $id = postDemand($this->paxToken)->assertStatus(201)->json('data.id');
    CommuteDemand::query()->whereKey($id)->update(['expires_at' => now()->addDay()]);

    asDemandOwner($this->paxToken, 'PATCH', "/api/v1/commute-demands/{$id}", ['paused' => true])->assertOk();
    $resumed = asDemandOwner($this->paxToken, 'PATCH', "/api/v1/commute-demands/{$id}", ['paused' => false])->assertOk()->json('data');

    expect(strtotime($resumed['expiresAt']))->toBeGreaterThan(now()->addDays(50)->getTimestamp());

    CommuteDemand::query()->whereKey($id)->update(['status' => CommuteDemandStatus::Expired->value, 'expires_at' => now()->subDay()]);

    expect(collect(asDemandOwner($this->paxToken, 'GET', '/api/v1/commute-demands')->json('data'))->pluck('status')->all())->toBe(['EXPIRED']);

    $edited = asDemandOwner($this->paxToken, 'PATCH', "/api/v1/commute-demands/{$id}", demandBody(['flexibilityMinutes' => 15]))->assertOk()->json('data');

    expect($edited['status'])->toBe('ACTIVE')
        ->and(strtotime($edited['expiresAt']))->toBeGreaterThan(now()->addDays(50)->getTimestamp());
});

it('keeps the catalogue place a label came from, and names it in her language', function () {
    $place = Place::factory()->create(['name' => 'Rehab Gate 2', 'name_ar' => 'الرحاب بوابة ٢', 'lat' => 30.0594, 'lng' => 31.4913, 'point' => new Coordinate(lat: 30.0594, lng: 31.4913)]);

    $id = postDemand($this->paxToken)->assertStatus(201)->json('data.id');

    expect(asDemandOwner($this->paxToken, 'GET', "/api/v1/commute-demands/{$id}", language: 'en')->json('data.origin'))
        ->toMatchArray(['placeId' => $place->id, 'label' => 'Rehab Gate 2'])
        ->and(asDemandOwner($this->paxToken, 'GET', "/api/v1/commute-demands/{$id}", language: 'ar')->json('data.origin.label'))
        ->toBe('الرحاب بوابة ٢');
});

it('answers the create with the matches already found', function () {
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    postDemand($this->paxToken, ['arrivalWindowStart' => '07:05:00', 'arrivalWindowEnd' => '07:05:00'])
        ->assertStatus(201)
        ->assertJsonPath('data.matchCount', 1);
});

it('adds the Greater Cairo places once', function () {
    $this->artisan('places:seed-greater-cairo')->expectsOutputToContain('26 places added')->assertSuccessful();
    $this->artisan('places:seed-greater-cairo')->expectsOutputToContain('0 places added')->assertSuccessful();

    test()->flushHeaders()->withToken($this->paxToken)->getJson('/api/v1/places?q=smart')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Smart Village');
});
