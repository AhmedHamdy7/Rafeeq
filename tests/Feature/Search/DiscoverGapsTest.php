<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Place;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\Support\TrimmedRoute;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Rating\Actions\RecomputeRatingStatsAction;
use App\Domains\Rating\Models\Rating;
use App\Domains\Shared\ValueObjects\Coordinate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The passenger Discover gaps (ClickUp DISCOVER-1 → 15, 2026-11-01): what the search, detail,
 * demand and seat-request screens draw that the API did not yet supply.
 *
 * Every field here is ADDITIVE — nothing a shipped client reads was renamed or removed — so each
 * test asserts the new field rather than the shape as a whole.
 */
beforeEach(function () {
    Storage::fake('documents');

    // The Bible's reference journey: Rehab to Smart Village, Sun–Thu, 07:05, with one suggested
    // pickup and one suggested drop-off on the line.
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
    $this->firstTrip = ScheduledTrip::query()->orderBy('departure_at')->first();
});

/*
|--------------------------------------------------------------------------
| DISCOVER-2, -3, -5, -15 — the search result card
|--------------------------------------------------------------------------
*/

it('carries the promised arrival, the drop-off point and the commute weekdays on each result', function () {
    $this->firstTrip->forceFill(['promised_arrival_at' => $this->firstTrip->departure_at->copy()->addMinutes(70)])->save();

    $card = search($this->paxToken)->assertOk()->json('data.0');

    expect($card['promisedArrivalAt'])->toBe($this->firstTrip->fresh()->promised_arrival_at->toIso8601String())
        // The departure's own wall clock moved by the same 70 minutes.
        ->and(substr($card['promisedArrivalLocal'], 11, 5))->toBe('08:22')
        // The days it RUNS, not the days that fit this search.
        ->and($card['daysMask'])->toBe(62)
        ->and($card['originLabel'])->toBe('Rehab Gate 2')
        ->and($card['destinationLabel'])->toBe('Smart Village B6')
        // The searched destination is the commute's own end, so that is where she gets out.
        ->and($card['dropOffPoint']['address'])->toBe('Smart Village B6')
        ->and($card['dropOffPoint'])->toHaveKeys(['lat', 'lng', 'walkMinutes', 'estimatedArrivalAt', 'estimatedArrivalLocal'])
        ->and($card['meetingPoint'])->toHaveKeys(['estimatedAt', 'estimatedLocal'])
        // DISCOVER-5: what the app splits results on is on every card.
        ->and($card['vehicle'])->toHaveKeys(['tier', 'hasAc'])
        ->and($card)->toHaveKey('rules')
        // DISCOVER-15: one canonical name, the old one kept beside it with the same value.
        ->and($card['pricePerSeatPiastres'])->toBe($card['seatPricePiastres']);
});

it('gives each other day its local departure time', function () {
    $other = search($this->paxToken)->assertOk()->json('data.0.otherDays.0');

    $trip = ScheduledTrip::query()->findOrFail($other['id']);

    expect($other['departureLocal'])->toBe($trip->departure_local->format('Y-m-d H:i:s'))
        ->and($other)->toHaveKeys(['promisedArrivalLocal', 'myStatus', 'overlapsBooking']);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-4 — the rating count on `person`
|--------------------------------------------------------------------------
*/

it('counts the revealed ratings an average is made of', function () {
    $driverId = DriverProfile::sole()->user_id;

    Rating::factory()->count(2)->create([
        'reviewed_user_id' => $driverId,
        'direction' => 'passenger_to_driver',
        'kind' => 'completed_ride',
        'visible_at' => now(),
        'moderation_status' => 'clean',
        'stars' => 5,
    ]);
    // Not revealed yet: counted by neither the average nor the count.
    Rating::factory()->create([
        'reviewed_user_id' => $driverId,
        'direction' => 'passenger_to_driver',
        'kind' => 'completed_ride',
        'visible_at' => null,
        'moderation_status' => 'clean',
        'stars' => 1,
    ]);

    app(RecomputeRatingStatsAction::class)->execute($driverId);

    expect(search($this->paxToken)->assertOk()->json('data.0.driver'))
        ->toMatchArray(['rating' => 5.0, 'ratingCount' => 2]);
});

it('reports no ratings as zero beside a null average', function () {
    expect(search($this->paxToken)->assertOk()->json('data.0.driver'))
        ->toMatchArray(['rating' => null, 'ratingCount' => 0]);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-6, -7 — where she stands on each day
|--------------------------------------------------------------------------
*/

it('says none on a commute she has not asked for', function () {
    expect(search($this->paxToken)->assertOk()->json('data.0.myStatus'))
        ->toBe(['kind' => 'none', 'seatRequestId' => null, 'bookingId' => null]);
});

it('marks the day she already asked for, and only that day', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->firstTrip->id])
        ->assertStatus(201)->json('data.id');

    $card = search($this->paxToken)->assertOk()->json('data.0');

    expect($card['tripId'])->toBe($this->firstTrip->id)
        ->and($card['myStatus'])->toBe(['kind' => 'pending_request', 'seatRequestId' => $requestId, 'bookingId' => null])
        ->and($card['otherDays'][0]['myStatus']['kind'])->toBe('none');
});

it('marks a booked day, and the overlap it makes with another commute at the same time', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->firstTrip->id])
        ->assertStatus(201)->json('data.id');
    $bookingId = approveSeat($this->driverToken, $requestId);

    expect(search($this->paxToken)->assertOk()->json('data.0.myStatus'))
        ->toBe(['kind' => 'booked', 'seatRequestId' => $requestId, 'bookingId' => $bookingId]);

    // A second driver on the same road at the same time.
    $secondDriver = approvedDriver(phone: '01012345679', devicePublicId: 'driver-2', seed: 2);
    $secondId = readyCommute($secondDriver, Vehicle::query()->latest('created_at')->orderByDesc('id')->first()->id);
    test()->withToken($secondDriver)->postJson("/api/v1/commutes/{$secondId}/publish")->assertOk();

    $card = collect(search($this->paxToken)->assertOk()->json('data'))->firstWhere('commuteId', $secondId);

    $sameDay = $card['tripDate'] === $this->firstTrip->trip_date->toDateString()
        ? $card
        : collect($card['otherDays'])->firstWhere('tripDate', $this->firstTrip->trip_date->toDateString());

    expect($sameDay['overlapsBooking']['bookingId'])->toBe($bookingId)
        ->and($sameDay['overlapsBooking'])->toHaveKeys(['driverFirstName', 'departureLocal', 'arrivalLocal']);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-8 — recent searches: labels, and removing one
|--------------------------------------------------------------------------
*/

it('keeps the labels she sent on a recent search, beside the coordinates', function () {
    search($this->paxToken, [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'New Cairo', 'placeId' => '01JPLACEORIGIN00000000000A'],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'label' => 'Smart Village'],
    ])->assertOk();

    $criteria = test()->withToken($this->paxToken)->getJson('/api/v1/me/recent-searches')
        ->assertOk()->json('data.0.criteria');

    expect($criteria['origin'])->toBe(['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'New Cairo', 'placeId' => '01JPLACEORIGIN00000000000A'])
        ->and($criteria['destination']['label'])->toBe('Smart Village')
        ->and($criteria['destination']['placeId'])->toBeNull();

    // And the round trip still works: the object goes straight back to the search.
    test()->withToken($this->paxToken)
        ->getJson('/api/v1/search/commutes?'.http_build_query($criteria))
        ->assertOk();
});

it('names an end sent as bare coordinates after the nearest catalogue place', function () {
    Place::factory()->create(['name' => 'Rehab Gate 2', 'lat' => 30.0598, 'lng' => 31.4910, 'point' => new Coordinate(30.0598, 31.4910)]);

    search($this->paxToken)->assertOk();

    expect(SearchHistory::sole()->criteria['origin']['label'])->toBe('Rehab Gate 2')
        // Nothing within 500 m of the destination: no label rather than a wrong one.
        ->and(SearchHistory::sole()->criteria['destination']['label'])->toBeNull();
});

it('removes one recent search and leaves the others', function () {
    search($this->paxToken)->assertOk();
    search($this->paxToken, ['maxWalkMinutes' => 10])->assertOk();

    $one = SearchHistory::query()->orderBy('id')->first();

    test()->withToken($this->paxToken)
        ->deleteJson("/api/v1/me/recent-searches/{$one->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(SearchHistory::query()->count())->toBe(1)
        ->and(SearchHistory::query()->whereKey($one->id)->exists())->toBeFalse();
});

it('answers 404 for somebody else\'s recent search, and deletes nothing', function () {
    search($this->paxToken)->assertOk();
    $hers = SearchHistory::sole();

    test()->withToken(passenger('01112223355', device: 'pax-2'))
        ->deleteJson("/api/v1/me/recent-searches/{$hers->id}")
        ->assertNotFound();

    expect(SearchHistory::query()->whereKey($hers->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| DISCOVER-9, -10 — demands: labels, preferences, match count
|--------------------------------------------------------------------------
*/

it('keeps a demand\'s place ids and preferences, and matches with them', function () {
    $demand = test()->withToken($this->paxToken)->postJson('/api/v1/commute-demands', searchCriteria([
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'New Cairo', 'placeId' => '01JPLACEORIGIN00000000000A'],
        'womenOnly' => true,
        'rules' => ['nonsmoking'],
        'tier' => 'comfort',
    ]))->assertStatus(201)->json('data');

    expect($demand['origin'])->toMatchArray(['label' => 'New Cairo', 'placeId' => '01JPLACEORIGIN00000000000A'])
        ->and($demand['womenOnly'])->toBeTrue()
        ->and($demand['rules'])->toBe(['nonsmoking'])
        ->and($demand['tier'])->toBe('comfort')
        ->and($demand)->toHaveKey('matchCount');

    // The Comfort filter is now part of the demand's question: a standard car does not match it.
    expect(MatchNotification::query()->count())->toBe(0);
});

it('counts the commutes matched to a demand that run this week', function () {
    test()->withToken($this->paxToken)->postJson('/api/v1/commute-demands', searchCriteria())
        ->assertStatus(201);

    $demand = CommuteDemand::sole();

    // The demand was matched against the commute already running.
    expect(MatchNotification::query()->where('commute_demand_id', $demand->id)->count())->toBe(1);

    test()->withToken($this->paxToken)->getJson('/api/v1/commute-demands')
        ->assertOk()
        ->assertJsonPath('data.0.matchCount', 1);

    // Paused by the driver: not something she can ride this week.
    CommuteOffer::sole()->forceFill(['status' => 'paused'])->save();

    test()->withToken($this->paxToken)->getJson('/api/v1/commute-demands')
        ->assertOk()
        ->assertJsonPath('data.0.matchCount', 0);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-11 — the full match-result shape on /matches
|--------------------------------------------------------------------------
*/

it('gives each match the fields a search result carries', function () {
    test()->withToken($this->paxToken)->postJson('/api/v1/commute-demands', searchCriteria())
        ->assertStatus(201);

    $match = test()->withToken($this->paxToken)->getJson('/api/v1/matches')
        ->assertOk()->json('data.0');

    expect($match['commuteId'])->toBe($this->commuteId)
        ->and($match['tripId'])->toBe($this->firstTrip->id)
        ->and($match['seatsAvailable'])->toBe(3)
        ->and($match['daysMask'])->toBe(62)
        ->and($match['meetingPoint']['address'])->toBe('Rehab Gate 2')
        ->and($match['dropOffPoint']['address'])->toBe('Smart Village B6')
        ->and($match['otherDays'])->not->toBeEmpty()
        ->and($match['myStatus']['kind'])->toBe('none')
        ->and($match['vehicle'])->toHaveKeys(['colour', 'tier', 'hasAc'])
        ->and($match['driver'])->toHaveKeys(['publicFirstName', 'rating', 'ratingCount', 'completedTrips'])
        ->and($match['pricePerSeatPiastres'])->toBe($match['seatPricePiastres'])
        // What it always had is still there.
        ->and($match)->toHaveKeys(['demandId', 'score', 'originLabel', 'destinationLabel', 'departureTimeLocal']);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-12 — a label for a dropped pin
|--------------------------------------------------------------------------
*/

it('looks up the places nearest a pin without a search term, closest first', function () {
    Place::factory()->create(['name' => 'Far', 'lat' => 30.0800, 'lng' => 31.0000, 'point' => new Coordinate(30.0800, 31.0000), 'usage_count' => 9999]);
    Place::factory()->create(['name' => 'Near', 'lat' => 30.0716, 'lng' => 30.9718, 'point' => new Coordinate(30.0716, 30.9718), 'usage_count' => 0]);

    $places = test()->withToken($this->paxToken)
        ->getJson('/api/v1/places?lat=30.0714&lng=30.9716')
        ->assertOk()->json('data');

    expect($places[0]['name'])->toBe('Near')
        ->and($places[0]['distanceMetres'])->toBeLessThan(50)
        ->and($places[1]['distanceMetres'])->toBeGreaterThan($places[0]['distanceMetres']);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-1 — the passenger's commute detail
|--------------------------------------------------------------------------
*/

it('lets a passenger read a commute: suggested points, weekdays, upcoming days, a trimmed route', function () {
    $detail = test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/public")
        ->assertOk()->json('data');

    $offer = CommuteOffer::sole();

    expect($detail['commuteId'])->toBe($this->commuteId)
        ->and($detail['schedule']['daysMask'])->toBe(62)
        ->and($detail['pickups'])->toHaveCount(1)
        ->and($detail['pickups'][0])->toHaveKeys(['id', 'label', 'lat', 'lng', 'estimatedTime'])
        ->and($detail['pickups'][0]['label'])->toBe('Ring Road, Rehab exit')
        ->and($detail['dropoffs'][0]['label'])->toBe('Mehwar, Sheikh Zayed')
        ->and($detail['upcomingTrips'][0]['id'])->toBe($this->firstTrip->id)
        ->and($detail['upcomingTrips'][0]['seatsAvailable'])->toBe(3)
        ->and($detail['upcomingTrips'][0]['myStatus']['kind'])->toBe('none')
        ->and($detail['vehicle'])->not->toHaveKey('plateNumber')
        ->and($detail['route']['isTrimmed'])->toBeTrue()
        ->and($detail['route']['distanceKm'])->toBeGreaterThan(0)
        // 🔒 Not the driver's front door: the line starts about a kilometre in.
        ->and($detail['route']['polyline'])->not->toBe($offer->route_polyline);

    $start = Polyline::decode($detail['route']['polyline'])[0];
    $origin = Polyline::decode($offer->route_polyline)[0];

    expect(Haversine::between($start, $origin)->metres)->toBeGreaterThan(900);
});

it('gives the suggestion ids a seat request accepts', function () {
    $detail = test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/public")->assertOk()->json('data');

    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->firstTrip->id,
        'pickup' => ['suggestionId' => $detail['pickups'][0]['id']],
        'dropoff' => ['suggestionId' => $detail['dropoffs'][0]['id']],
    ])->assertStatus(201)->assertJsonPath('data.pickup.suggestionId', $detail['pickups'][0]['id']);
});

it('draws the whole route once she holds a confirmed seat', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->firstTrip->id])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    expect(Booking::sole()->status->value)->toBe('confirmed');

    $route = test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/public")->assertOk()->json('data.route');

    expect($route['isTrimmed'])->toBeFalse()
        ->and(Polyline::decode($route['polyline']))->toHaveCount(count(Polyline::decode(CommuteOffer::sole()->route_polyline)));
});

it('hides a commute that is not published, the same as one that does not exist', function () {
    CommuteOffer::sole()->forceFill(['status' => 'paused'])->save();

    test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/public")
        ->assertNotFound();
});

it('trims a route by distance, a kilometre off each end', function () {
    // A straight line of about 11 km with only its two ends as vertices.
    $line = Polyline::encode([new Coordinate(30.0, 31.0), new Coordinate(30.1, 31.0)]);

    $kept = TrimmedRoute::of($line);

    expect($kept)->toHaveCount(2)
        ->and(round($kept[0]->lat, 3))->toBe(30.009)
        ->and(round($kept[1]->lat, 3))->toBe(30.091);
});

/*
|--------------------------------------------------------------------------
| DISCOVER-13 — the seat-request shape her screens read
|--------------------------------------------------------------------------
*/

it('gives her request the points with times and the commute it is for', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->firstTrip->id])
        ->assertStatus(201);

    $mine = test()->withToken($this->paxToken)->getJson('/api/v1/seat-requests')
        ->assertOk()->json('data.0');

    expect($mine['pickup'])->toHaveKeys(['label', 'lat', 'lng', 'source', 'estimatedAt', 'estimatedLocal'])
        ->and($mine['pickup']['estimatedLocal'])->not->toBeNull()
        ->and($mine['dropoff'])->toHaveKeys(['estimatedArrivalLocal', 'promisedArrivalLocal'])
        ->and($mine['commute']['originLabel'])->toBe('Rehab Gate 2')
        ->and($mine['commute']['tripId'])->toBe($this->firstTrip->id)
        ->and($mine['commute']['driver'])->toHaveKeys(['publicFirstName', 'rating', 'ratingCount'])
        ->and($mine['commute']['vehicle'])->toHaveKeys(['make', 'model', 'colour'])
        ->and($mine['commute']['seatPricePiastres'])->toBe($mine['commute']['pricePerSeatPiastres']);
});

it('sends the driver none of the passenger-side summary', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->firstTrip->id])
        ->assertStatus(201);

    $inbox = test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertOk()->json('data.0');

    expect($inbox)->not->toHaveKey('commute')
        ->and($inbox['pickup']['estimatedLocal'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| DISCOVER-14 — estimated pickup time
|--------------------------------------------------------------------------
*/

it('estimates when the car reaches a previewed point', function () {
    $preview = test()->withToken($this->paxToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/pickup-preview", onTheWay() + ['scheduledTripId' => $this->firstTrip->id])
        ->assertOk()->json('data');

    $estimated = CarbonImmutable::parse($preview['estimatedPickupAt']);

    expect($preview['tripId'])->toBe($this->firstTrip->id)
        // After the departure, before the end of the run.
        ->and($estimated->greaterThan($this->firstTrip->departure_at))->toBeTrue()
        ->and($estimated->lessThan($this->firstTrip->departure_at->copy()->addSeconds(CommuteOffer::sole()->route_duration_seconds)))->toBeTrue()
        ->and($preview['estimatedPickupLocal'])->not->toBeNull();
});

it('puts a pickup ETA on her booking', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->firstTrip->id])
        ->assertStatus(201)->json('data.id');
    $bookingId = approveSeat($this->driverToken, $requestId);

    $booking = test()->withToken($this->paxToken)->getJson("/api/v1/bookings/{$bookingId}")
        ->assertOk()->json('data');

    expect($booking['pickupEta'])->not->toBeNull()
        ->and($booking['pickupEtaLocal'])->not->toBeNull()
        ->and(CarbonImmutable::parse($booking['pickupEta'])->greaterThanOrEqualTo($this->firstTrip->departure_at))->toBeTrue();

    expect(test()->withToken($this->paxToken)->getJson('/api/v1/my-bookings')->assertOk()->json('data.0.pickupEta'))
        ->toBe($booking['pickupEta']);
});

it('keeps a user stat count in step with the stars after a takedown', function () {
    $driverId = DriverProfile::sole()->user_id;

    $rating = Rating::factory()->create([
        'reviewed_user_id' => $driverId,
        'direction' => 'passenger_to_driver',
        'kind' => 'completed_ride',
        'visible_at' => now(),
        'moderation_status' => 'clean',
        'stars' => 4,
    ]);

    app(RecomputeRatingStatsAction::class)->execute($driverId);
    expect(User::query()->findOrFail($driverId)->stats->rating_count_as_driver)->toBe(1);

    $rating->forceFill(['moderation_status' => 'hidden'])->save();
    app(RecomputeRatingStatsAction::class)->execute($driverId);

    expect(User::query()->findOrFail($driverId)->stats->fresh()->rating_count_as_driver)->toBe(0);
});
