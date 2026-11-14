<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Place;
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

    $this->tripId = ScheduledTrip::query()->orderBy('departure_at')->first()->id;

    $this->near = Place::factory()->create([
        'name' => 'City Stars',
        'name_ar' => 'سيتي ستارز',
        'type' => PlaceType::Landmark,
        'lat' => 30.0650,
        'lng' => 31.3614,
        'point' => new Coordinate(30.0650, 31.3614),
    ]);
});

function seatRequestIn(string $language): array
{
    return test()->flushHeaders()->withToken(test()->paxToken)->withHeader('Accept-Language', $language)
        ->getJson('/api/v1/seat-requests/'.test()->requestId)
        ->assertOk()
        ->json('data');
}

it('names the catalogue place near a custom pin in the reader\'s language', function () {
    $this->requestId = requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'pickup' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'هنا'],
    ])->assertStatus(201)->json('data.id');

    $arabic = seatRequestIn('ar');
    $english = seatRequestIn('en');

    expect($arabic['pickup']['label'])->toBe('هنا')
        ->and($arabic['pickup']['nearLabel'])->toBe('سيتي ستارز')
        ->and($english['pickup']['nearLabel'])->toBe('City Stars')
        ->and($english['pickup']['nearPlaceId'])->toBe($this->near->id)
        ->and($english['pickup']['nearDistanceMetres'])->toBeGreaterThan(250)->toBeLessThan(330);
});

it('gives no near label when no catalogue place is within a kilometre', function () {
    $this->requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id');

    $dropoff = seatRequestIn('en')['dropoff'];

    expect($dropoff['nearLabel'])->toBeNull()
        ->and($dropoff['nearPlaceId'])->toBeNull()
        ->and($dropoff['nearDistanceMetres'])->toBeNull();
});

it('names a place added after the pin was saved', function () {
    $this->requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id');

    expect(seatRequestIn('en')['dropoff']['nearLabel'])->toBeNull();

    Place::factory()->create([
        'name' => 'Mehwar Gate',
        'name_ar' => 'بوابة المحور',
        'lat' => 30.0684,
        'lng' => 31.1020,
        'point' => new Coordinate(30.0684, 31.1020),
    ]);

    expect(seatRequestIn('ar')['dropoff']['nearLabel'])->toBe('بوابة المحور');
});

it('names the place near a driver\'s suggested point', function () {
    $locations = test()->flushHeaders()->withToken($this->driverToken)->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/commutes/{$this->commuteId}")
        ->assertOk()
        ->json('data.locations');

    $pickup = collect($locations)->firstWhere('type', 'pickup');

    expect($pickup['nearLabel'])->toBe('City Stars')
        ->and($pickup['nearDistanceMetres'])->toBeGreaterThan(250);
});
