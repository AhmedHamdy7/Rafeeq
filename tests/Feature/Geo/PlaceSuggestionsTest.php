<?php

use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Enums\PlaceSuggestionKind;
use App\Domains\Geo\Models\PlacePinPick;
use App\Domains\Geo\Models\PlaceSuggestion;
use App\Domains\Geo\Support\LearnedPoint;
use App\Domains\Geo\Support\PlaceSuggestionBook;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);
    $this->paxToken = verifiedPassenger('01112223344');
});

function suggestions(string $token, array $query)
{
    return test()->withToken($token)->getJson('/api/v1/places/suggestions?'.http_build_query($query));
}

function pinBy(string $userId, float $lat, float $lng, ?string $label = 'Gate 3'): void
{
    app(PlaceSuggestionBook::class)->learn(new LearnedPoint(
        point: new Coordinate($lat, $lng),
        label: $label,
        googlePlaceId: null,
        areaId: null,
        role: LearnedPoint::BOARD,
        userId: $userId,
    ));
}

it('learns nothing from a draft and every point of a published commute', function () {
    expect(PlaceSuggestion::count())->toBe(0);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $locations = CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->get();

    expect(PlaceSuggestion::query()->where('kind', PlaceSuggestionKind::CommutePoint->value)->count())->toBe($locations->count())
        ->and((int) PlaceSuggestion::query()->sum('uses'))->toBe($locations->count());
});

it('suggests the nearest points first, in the point shape the app sends', function () {
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();
    $origin = CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->where('type', 'origin')->sole();

    $list = suggestions($this->paxToken, ['lat' => (float) $origin->lat + 0.001, 'lng' => (float) $origin->lng, 'kind' => 'start'])
        ->assertOk()->json('data');

    expect($list)->not->toBeEmpty()
        ->and($list[0])->toHaveKeys(['id', 'kind', 'label', 'lat', 'lng', 'googlePlaceId', 'area', 'distanceMetres', 'uses'])
        ->and($list[0]['lat'])->toBe((float) $origin->lat)
        ->and($list[0]['label'])->toBe($origin->address)
        ->and($list[0]['distanceMetres'])->toBeLessThan(200);

    expect(suggestions($this->paxToken, ['lat' => 25.0, 'lng' => 33.0])->assertOk()->json('data'))->toBe([]);
});

it('counts her pin on a driver point for that point and keeps no private pin', function () {
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();
    $trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $origin = CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->where('type', 'origin')->sole();
    $destination = CommuteLocation::query()->where('commute_offer_id', $this->commuteId)->where('type', 'destination')->sole();

    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => (float) $origin->lat + 0.0001, 'lng' => (float) $origin->lng, 'label' => 'At the gate'],
        'dropoff' => ['lat' => (float) $destination->lat, 'lng' => (float) $destination->lng + 0.0001, 'label' => 'Office'],
    ])->assertStatus(201);

    expect(PlaceSuggestion::query()->where('label', $origin->address)->sole()->uses)->toBe(2)
        ->and(PlaceSuggestion::query()->where('label', $destination->address)->sole()->uses)->toBe(2)
        ->and(PlacePinPick::count())->toBe(0);
});

it('keeps her own pin away from every driver point private', function () {
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();
    $trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $before = PlaceSuggestion::count();

    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $trip->id,
        'pickup' => onTheWay(),
    ])->assertStatus(201);

    expect(PlaceSuggestion::count())->toBe($before)
        ->and(PlacePinPick::query()->where('label', onTheWay()['label'])->count())->toBe(1);
});

it('keeps a passenger pin private until three people picked the same spot', function () {
    $users = User::factory()->count(3)->create();

    pinBy($users[0]->id, 30.1000, 31.4000, 'My home');
    pinBy($users[1]->id, 30.1002, 31.4001);

    expect(PlaceSuggestion::count())->toBe(0)
        ->and(PlacePinPick::count())->toBe(2)
        ->and(suggestions($this->paxToken, ['lat' => 30.1, 'lng' => 31.4])->json('data'))->toBe([]);

    pinBy($users[2]->id, 30.1001, 31.4002);

    $shared = PlaceSuggestion::query()->sole();

    expect($shared->kind)->toBe(PlaceSuggestionKind::SharedPin)
        ->and($shared->label)->toBe('Gate 3')
        ->and((float) $shared->lat)->not->toBe(30.1)
        ->and($shared->uses)->toBe(3);
});

it('never names a shared pin after a label only one person typed', function () {
    $users = User::factory()->count(3)->create();

    pinBy($users[0]->id, 30.1000, 31.4000, 'My home');
    pinBy($users[1]->id, 30.1002, 31.4001, 'Work');
    pinBy($users[2]->id, 30.1001, 31.4002, 'Gym');

    expect(PlaceSuggestion::count())->toBe(0);
});

it('does not count the same person twice for a shared pin', function () {
    $user = User::factory()->create();

    pinBy($user->id, 30.1000, 31.4000);
    pinBy($user->id, 30.1002, 31.4001);
    pinBy($user->id, 30.1001, 31.4002);

    expect(PlacePinPick::count())->toBe(1)
        ->and(PlacePinPick::sole()->uses)->toBe(3)
        ->and(PlaceSuggestion::count())->toBe(0);
});

it('finds a suggestion by name ignoring case and Arabic letter forms', function () {
    PlaceSuggestion::query()->create([
        'kind' => PlaceSuggestionKind::CommutePoint, 'label' => 'Cairo Festival City', 'label_ar' => 'كايرو فستيفال سيتي - البوابة',
        'lat' => 30.0290, 'lng' => 31.4080, 'uses' => 4, 'roles' => LearnedPoint::BOARD,
        'search_text' => 'cairo festival city | كايرو فستيفال سيتي - البوابه',
    ]);

    expect(suggestions($this->paxToken, ['q' => 'FESTIVAL'])->json('data.0.label'))->toBe('Cairo Festival City')
        ->and(suggestions($this->paxToken, ['q' => 'البوابة'])->json('data'))->toHaveCount(1)
        ->and(suggestions($this->paxToken, ['q' => 'zamalek'])->json('data'))->toBe([]);
});

it('stores a Google place with its id and refreshes or drops its coordinates within 30 days', function () {
    config(['services.google_maps.server_key' => 'test-key']);
    Http::fake(['places.googleapis.com/*' => Http::response(['location' => ['latitude' => 30.05, 'longitude' => 31.25]])]);

    app(PlaceSuggestionBook::class)->learn(new LearnedPoint(
        point: new Coordinate(30.0400, 31.2400), label: 'City Stars', googlePlaceId: 'ChIJ-used',
        areaId: null, role: LearnedPoint::ALIGHT, userId: User::factory()->create()->id,
    ));
    app(PlaceSuggestionBook::class)->learn(new LearnedPoint(
        point: new Coordinate(30.2000, 31.5000), label: 'Old Mall', googlePlaceId: 'ChIJ-idle',
        areaId: null, role: LearnedPoint::ALIGHT, userId: User::factory()->create()->id,
    ));

    PlaceSuggestion::query()->where('google_place_id', 'ChIJ-used')->update(['coords_fetched_at' => now()->subDays(28), 'last_used_at' => now()->subDays(3)]);
    PlaceSuggestion::query()->where('google_place_id', 'ChIJ-idle')->update(['coords_fetched_at' => now()->subDays(31), 'last_used_at' => now()->subDays(31)]);

    test()->artisan('places:refresh-coordinates')->assertSuccessful();

    $used = PlaceSuggestion::query()->where('google_place_id', 'ChIJ-used')->sole();
    $idle = PlaceSuggestion::query()->where('google_place_id', 'ChIJ-idle')->sole();

    expect((float) $used->lat)->toBe(30.05)
        ->and($used->coords_fetched_at->isToday())->toBeTrue()
        ->and($idle->lat)->toBeNull()
        ->and($idle->label)->toBe('Old Mall');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ChIJ-used')
        && $request->header('X-Goog-FieldMask')[0] === 'location');
    Http::assertSentCount(1);

    expect(suggestions($this->paxToken, ['q' => 'old mall'])->json('data'))->toBe([]);
});

it('refuses a nearby request without a position', function () {
    suggestions($this->paxToken, ['kind' => 'start'])->assertStatus(422);
});

it('does not geocode on a search, only when a point is saved', function () {
    config(['services.google_maps.server_key' => 'test-key']);
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => []])]);

    search($this->paxToken)->assertOk();

    Http::assertNothingSent();
});
