<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Support\Facades\Storage;

/**
 * Every state of the passenger's Home card (F3P-01 → F3P-11) and the strips under it (HOME-1 →
 * HOME-10).
 *
 * 🔴 What these guard is that the SERVER names the state. Each test drives the real run — start,
 * arrive, board, finish, cancel, leave — through the endpoints a driver and a passenger use, and
 * then reads `phase` back, so a change to how any of those is recorded breaks a test here instead
 * of a card in somebody's hand at a bus stop.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201)->json('data.id'),
    );

    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

/**
 * Screen 9's card, read as the passenger. `flushHeaders` because the driver's token is the other
 * default header in this file, and the card must be hers.
 *
 * @return array<string, mixed>|null
 */
function homeCard(string $token): ?array
{
    return test()->flushHeaders()->withToken($token)->getJson('/api/v1/home')->assertOk()->json('data.nextJourney');
}

function homeAs(string $token): array
{
    return test()->flushHeaders()->withToken($token)->getJson('/api/v1/home')->assertOk()->json('data');
}

function asHomeDriver(string $token, string $method, string $uri, array $body = [])
{
    return test()->flushHeaders()->withToken($token)->json($method, $uri, $body);
}

/*
|--------------------------------------------------------------------------
| Before the day — F3P-02 / F3P-03
|--------------------------------------------------------------------------
*/

it('names a confirmed journey that has not started as scheduled, with its days, pickup and drop-off', function () {
    $card = homeCard($this->paxToken);
    $booking = Booking::query()->findOrFail($this->bookingId);
    $trip = ScheduledTrip::query()->findOrFail($this->tripId);

    expect($card['phase'])->toBe('scheduled')
        // Additive: the old fields are all still there and still mean the same.
        ->and($card['status'])->toBe('CONFIRMED')
        ->and($card['meetingPoint'])->not->toBeNull()
        ->and($card['daysMask'])->toBeGreaterThan(0)
        // "Your agreed pickup · <label> · 07:05" — the place as meetingPoint, and a time of its own.
        ->and($card['pickup']['label'])->toBe($card['meetingPoint']['label'])
        ->and($card['pickup']['lat'])->toBe((float) $card['meetingPoint']['lat'])
        ->and($card['pickup']['isExact'])->toBeTrue()
        ->and($card['pickup']['at'])->not->toBeNull()
        // Never before the car leaves: the pickup is the departure plus the drive to her.
        ->and(strtotime($card['pickup']['at']))->toBeGreaterThanOrEqual(strtotime($card['departureAt']))
        ->and($card['pickup']['local'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($card['dropoff']['label'])->not->toBeNull()
        ->and($card['dropoff']['promisedArrivalAt'])->toBe($trip->promised_arrival_at?->toIso8601String())
        ->and($card['live'])->toBeNull()
        ->and($card['canLeaveWithoutFee'])->toBeFalse()
        ->and($card['endedAt'])->toBeNull()
        ->and($card['alternatives'])->toBe([])
        ->and($card['completedAt'])->toBeNull()
        ->and($card['canRate'])->toBeFalse()
        ->and($booking->status)->toBe(BookingStatus::Confirmed);
});

/**
 * 🔴 The card's 07:05 and the grace clock's 07:05 are one number. If they drifted, she would be
 * charged for lateness against a time she was never shown.
 */
it('shows the same pickup time the arrival grace is counted from', function () {
    runLeavingIn($this->tripId, 10);
    asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/start")->assertStatus(201);
    asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/status", ['status' => 'EN_ROUTE'])->assertOk();

    $card = homeCard($this->paxToken);

    $timer = asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/wait-timers", [
        'bookingId' => $this->bookingId, ...atPickupOf($this->bookingId),
    ])->assertStatus(201)->json('data');

    // The car is early (departure is ten minutes off), so her grace waits for her pickup time —
    // the very one the card showed.
    expect(strtotime($timer['graceStartsAt']))->toBe(strtotime($card['pickup']['at']));
});

/*
|--------------------------------------------------------------------------
| On the day — F3P-04 → F3P-07
|--------------------------------------------------------------------------
*/

it('says the driver is delayed once departure passes without the run starting, and opens the no-fee exit', function () {
    runLeavingIn($this->tripId, -5);

    $card = homeCard($this->paxToken);

    expect($card['phase'])->toBe('driver_delayed')
        ->and($card['tripId'])->toBe($this->tripId)
        // 🔴 The same finding the exit endpoint makes, so the button is never refused.
        ->and($card['canLeaveWithoutFee'])->toBeTrue()
        ->and($card['live'])->toBeNull();
});

it('keeps the no-fee exit closed while the driver is still on time', function () {
    runLeavingIn($this->tripId, 30);

    $card = homeCard($this->paxToken);

    expect($card['phase'])->toBe('scheduled')
        ->and($card['canLeaveWithoutFee'])->toBeFalse();

    // And the endpoint agrees: refused, with the reason the card would give.
    asHomeDriver($this->paxToken, 'POST', "/api/v1/bookings/{$this->bookingId}/exit-without-fee")
        ->assertStatus(409);
});

it('follows the car from setting off, to her kerb, to on board', function () {
    runLeavingIn($this->tripId, 10);
    asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/start")->assertStatus(201);
    asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/status", ['status' => 'EN_ROUTE'])->assertOk();

    $enRoute = homeCard($this->paxToken);

    expect($enRoute['phase'])->toBe('driver_en_route')
        ->and($enRoute['live'])->toHaveKeys(['pickupEtaAt', 'arrivalEtaAt', 'progressPercent', 'driverArrivedAt', 'boardedAt'])
        ->and($enRoute['live']['driverArrivedAt'])->toBeNull()
        ->and($enRoute['live']['boardedAt'])->toBeNull()
        ->and($enRoute['live']['arrivalEtaAt'])->toBeNull()
        ->and($enRoute['canLeaveWithoutFee'])->toBeFalse();

    if ($enRoute['live']['pickupEtaAt'] !== null) {
        // Never in the past, and the bar never claims an arrival nothing recorded.
        expect(strtotime($enRoute['live']['pickupEtaAt']))->toBeGreaterThanOrEqual(now()->subSecond()->getTimestamp())
            ->and($enRoute['live']['progressPercent'])->toBeLessThanOrEqual(99);
    }

    // The driver's GPS-checked "I've arrived" (TRIP-10).
    asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/wait-timers", [
        'bookingId' => $this->bookingId, ...atPickupOf($this->bookingId),
    ])->assertStatus(201);

    $arrived = homeCard($this->paxToken);

    expect($arrived['phase'])->toBe('driver_arrived')
        ->and($arrived['live']['driverArrivedAt'])->not->toBeNull()
        ->and($arrived['live']['progressPercent'])->toBe(100);

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    $aboard = homeCard($this->paxToken);

    expect($aboard['phase'])->toBe('on_board')
        ->and($aboard['live']['boardedAt'])->not->toBeNull()
        ->and($aboard['live']['pickupEtaAt'])->toBeNull()
        // She is in the car: leaving "without a fee" is not what this button means any more.
        ->and($aboard['canLeaveWithoutFee'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| After it ended — F3P-08 → F3P-11 (HOME-1, HOME-6, HOME-7)
|--------------------------------------------------------------------------
*/

it('keeps showing a journey she left because the driver was late, with no fee and alternatives', function () {
    runLeavingIn($this->tripId, -5);

    asHomeDriver($this->paxToken, 'POST', "/api/v1/bookings/{$this->bookingId}/exit-without-fee")->assertOk();

    $card = homeCard($this->paxToken);

    expect($card['id'])->toBe($this->bookingId)
        ->and($card['phase'])->toBe('exited')
        ->and($card['status'])->toBe('EXITED_DUE_TO_DRIVER_DELAY')
        ->and($card['endedAt'])->not->toBeNull()
        ->and($card['cancellationFeePiastres'])->toBe(0)
        ->and($card['alternatives'])->toBeArray()
        ->and(count($card['alternatives']))->toBeLessThanOrEqual(2)
        ->and($card['canLeaveWithoutFee'])->toBeFalse();

    foreach ($card['alternatives'] as $alternative) {
        expect(array_keys($alternative))->toEqualCanonicalizing([
            'commuteId', 'tripId', 'departureAt', 'departureLocal', 'originLabel', 'destinationLabel',
            'seatsAvailable', 'driver',
        ])->and(array_keys($alternative['driver']))->toBe(['publicFirstName']);
    }
});

it('shows a day the driver cancelled, until that day is over', function () {
    runLeavingIn($this->tripId, 120);

    asHomeDriver($this->driverToken, 'POST', "/api/v1/trips/{$this->tripId}/cancel", ['reason' => 'Car in the garage'])
        ->assertOk();

    $card = homeCard($this->paxToken);

    expect($card['phase'])->toBe('cancelled_by_driver')
        ->and($card['endedAt'])->not->toBeNull()
        ->and($card['cancellationFeePiastres'])->toBe(0)
        ->and($card['alternatives'])->toBeArray();

    // Yesterday's cancellation is not news today. The day is moved rather than the clock, which
    // would also expire her access token.
    $yesterday = now()->subDay();
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => $yesterday, 'departure_local' => $yesterday]);

    expect(homeCard($this->paxToken))->toBeNull();
});

it('names a morning the sweep expired as expired, not as her leaving', function () {
    runLeavingIn($this->tripId, -30);

    // The sweep's own writes (SweepDelayedTripsAction::endSeat), set directly so the test does not
    // depend on the ladder's timings.
    ScheduledTrip::query()->whereKey($this->tripId)->update(['status' => ScheduledTripStatus::Expired->value]);
    Booking::query()->whereKey($this->bookingId)->update([
        'status' => BookingStatus::ExitedDueToDriverDelay->value,
        'cancelled_at' => now(),
        'cancelled_reason' => 'trip_expired',
        'cancellation_fee_piastres' => 0,
    ]);

    $card = homeCard($this->paxToken);

    expect($card['phase'])->toBe('expired')
        ->and($card['endedAt'])->not->toBeNull();
});

it('shows a completed ride until she rates it', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $card = homeCard($this->paxToken);

    expect($card['phase'])->toBe('completed')
        ->and($card['completedAt'])->not->toBeNull()
        ->and($card['canRate'])->toBeTrue()
        ->and($card['live'])->toBeNull();

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(201);

    // Rated, and nothing else booked: the card has nothing left to say.
    expect(homeCard($this->paxToken))->toBeNull();
});

it('lets the next pickup outrank an old ride still waiting for stars', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    // Two days ago — still inside the rating window — and a new seat booked on another day.
    $twoDaysAgo = now()->subDays(2);
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => $twoDaysAgo, 'departure_local' => $twoDaysAgo]);

    expect(homeCard($this->paxToken)['phase'])->toBe('completed');

    // Another driver's commute: one request per commute, and hers on the first is answered.
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);
    $otherCommute = readyCommute($otherDriver, Vehicle::query()->where('plate_normalized', 'ABC1236')->sole()->id);
    asHomeDriver($otherDriver, 'POST', "/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    $later = ScheduledTrip::query()
        ->where('commute_offer_id', $otherCommute)
        ->where('departure_at', '>', now())
        ->orderBy('departure_at')
        ->firstOrFail();

    test()->flushHeaders();
    approveSeat($otherDriver, requestSeat($this->paxToken, $otherCommute, ['scheduledTripId' => $later->id])
        ->assertStatus(201)->json('data.id'));

    expect(homeCard($this->paxToken)['tripId'])->toBe($later->id);
});

/*
|--------------------------------------------------------------------------
| Under the card — HOME-8, HOME-9, HOME-10
|--------------------------------------------------------------------------
*/

it('counts the matches she has not opened on one demand, and opening one lowers it', function () {
    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson('/api/v1/commute-demands', searchCriteria())->assertStatus(201);

    MatchNotification::query()->delete();
    $demand = CommuteDemand::sole();

    $ids = [];

    foreach ([70, 80, 90] as $score) {
        $ids[] = MatchNotification::query()->create([
            'commute_demand_id' => $demand->id,
            'commute_offer_id' => CommuteOffer::factory()->published()->create()->id,
            'score' => $score,
        ])->id;
    }

    $alert = homeAs($this->paxToken)['demandAlert'];

    expect($alert['demandId'])->toBe($demand->id)
        ->and($alert['newMatchesCount'])->toBe(3)
        ->and($alert['daysMask'])->toBe((int) $demand->days_mask)
        ->and($alert['arrivalTimeLocal'])->toBe('06:45');

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/matches/{$ids[0]}/opened")->assertOk()
        ->assertJsonPath('data.clickedAt', fn (?string $at) => $at !== null);

    // A second tap is not news.
    test()->flushHeaders()->withToken($this->paxToken)->postJson("/api/v1/matches/{$ids[0]}/opened")->assertOk();

    expect(homeAs($this->paxToken)['demandAlert']['newMatchesCount'])->toBe(2);
});

it('has no demand alert when nothing new matched', function () {
    expect(homeAs($this->paxToken)['demandAlert'])->toBeNull();
});

it('opens only her own matches', function () {
    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson('/api/v1/commute-demands', searchCriteria())->assertStatus(201);

    $match = MatchNotification::query()->create([
        'commute_demand_id' => CommuteDemand::sole()->id,
        'commute_offer_id' => CommuteOffer::factory()->published()->create()->id,
        'score' => 50,
    ]);

    fakeOtpSender();
    $stranger = verifiedPassenger('01112223344', device: 'pax-3');

    test()->flushHeaders()->withToken($stranger)->postJson("/api/v1/matches/{$match->id}/opened")->assertNotFound();

    expect($match->refresh()->clicked_at)->toBeNull();
});

it('gives the demand rows their seats, the driver her trip count, and the demand its name', function () {
    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson('/api/v1/commute-demands', searchCriteria())->assertStatus(201);

    MatchNotification::query()->delete();
    CommuteDemand::query()->update(['origin_label' => 'New Cairo', 'destination_label' => 'Smart Village']);

    MatchNotification::query()->create([
        'commute_demand_id' => CommuteDemand::sole()->id,
        'commute_offer_id' => $this->commuteId,
        'score' => 88,
    ]);

    $row = homeAs($this->paxToken)['topMatches'][0];
    $next = ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)
        ->where('status', 'scheduled')->where('departure_at', '>=', now())
        ->orderBy('departure_at')->firstOrFail();

    expect($row['demandTitle'])->toBe('New Cairo → Smart Village')
        ->and($row['seatsAvailable'])->toBe($next->seats_total - $next->seats_taken)
        ->and($row['driver']['completedTrips'])->toBe(0)
        // The resource's own fields are untouched.
        ->and($row['score'])->toBe(88)
        ->and($row['driver']['publicFirstName'])->not->toBeEmpty();
});

it('says when she last ran a saved search', function () {
    test()->flushHeaders()->withToken($this->paxToken)->postJson('/api/v1/saved-searches', [
        'title' => 'الرحاب ← القرية الذكية',
        'filters' => searchCriteria(),
    ])->assertStatus(201);

    expect(homeAs($this->paxToken)['savedSearches'][0]['lastSearchedAt'])->toBeNull();

    test()->flushHeaders();
    search($this->paxToken)->assertOk();

    expect(homeAs($this->paxToken)['savedSearches'][0]['lastSearchedAt'])->not->toBeNull();
});

it('carries the unread notification count the inbox reports', function () {
    Notifier::send($this->pax, NotificationType::PassengerExitedDriverDelay, params: ['name' => 'X']);

    $inbox = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson('/api/v1/notifications?perPage=1')->assertOk()->json('meta.unreadCount');

    expect(homeAs($this->paxToken)['unreadNotifications'])->toBe($inbox)
        ->and($inbox)->toBeGreaterThan(0);
});
