<?php

use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Place;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Shared\ValueObjects\Coordinate;
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

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344');
});

function areaPlace(string $name, float $lat, float $lng, PlaceType $type = PlaceType::District, array $overrides = []): Place
{
    return Place::factory()->create([
        'name' => $name,
        'name_ar' => 'منطقة '.$name,
        'type' => $type,
        'lat' => $lat,
        'lng' => $lng,
        'point' => new Coordinate($lat, $lng),
        ...$overrides,
    ]);
}

it('finds a commute anywhere in the chosen area when the point search misses it', function () {
    $area = areaPlace('South Nasr', 30.0300, 31.3600);
    $centre = ['lat' => 30.0300, 'lng' => 31.3600];

    $point = search($this->paxToken, ['origin' => $centre])->assertOk();

    expect($point->json('data'))->toBe([])
        ->and($point->json('meta.scope'))->toBe('point');

    $wide = search($this->paxToken, ['origin' => [...$centre, 'areaPlaceId' => $area->id]])->assertOk();

    expect($wide->json('meta'))->toMatchArray(['matched' => 1, 'scope' => 'area'])
        ->and($wide->json('data.0.commuteId'))->toBe($this->commuteId)
        ->and($wide->json('data.0.meetingPoint.address'))->toBe('Ring Road, Rehab exit')
        ->and($wide->json('data.0.distanceMetres'))->toBeGreaterThan(3000)->toBeLessThan(4000);
});

it('leaves out a commute that does not come into the area', function () {
    $area = areaPlace('Maadi', 29.9602, 31.2569);

    $wide = search($this->paxToken, ['origin' => ['lat' => 29.9602, 'lng' => 31.2569, 'areaPlaceId' => $area->id]])->assertOk();

    expect($wide->json('data'))->toBe([]);
});

it('widens the destination end too', function () {
    $area = areaPlace('October', 30.0400, 31.1000, PlaceType::City);

    expect(search($this->paxToken, ['destination' => ['lat' => 30.0400, 'lng' => 31.1000]])->assertOk()->json('data'))->toBe([]);

    $wide = search($this->paxToken, ['destination' => ['lat' => 30.0400, 'lng' => 31.1000, 'areaPlaceId' => $area->id]])->assertOk();

    expect($wide->json('data.0.commuteId'))->toBe($this->commuteId)
        ->and($wide->json('data.0.dropOffPoint.address'))->toBe('Mehwar, Sheikh Zayed');
});

it('uses the radius set on the place before the default for its type', function () {
    $area = areaPlace('South Nasr', 30.0300, 31.3600, PlaceType::District, ['radius_metres' => 1000]);

    $wide = search($this->paxToken, ['origin' => ['lat' => 30.0300, 'lng' => 31.3600, 'areaPlaceId' => $area->id]])->assertOk();

    expect($wide->json('data'))->toBe([]);
});

it('refuses an area that is not a district or a city', function () {
    $gate = areaPlace('Gate 1', 30.0300, 31.3600, PlaceType::CompoundGate);

    search($this->paxToken, ['origin' => ['lat' => 30.0300, 'lng' => 31.3600, 'areaPlaceId' => $gate->id]])
        ->assertStatus(422);
});

it('gives the distance to the commute on a point search and keeps area searches out of recents', function () {
    $area = areaPlace('South Nasr', 30.0300, 31.3600);

    search($this->paxToken, ['origin' => ['lat' => 30.0300, 'lng' => 31.3600, 'areaPlaceId' => $area->id]])->assertOk();

    expect(SearchHistory::query()->count())->toBe(0);

    $card = search($this->paxToken)->assertOk()->json('data.0');

    expect($card['distanceMetres'])->toBe(0)
        ->and(SearchHistory::query()->count())->toBe(1);
});
