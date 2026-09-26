<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Verification\Enums\VerificationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The two screens the app opens on (screens 9 and 23).
 *
 * 🔴 What these really guard is that the card is right AS A WHOLE. Both screens lead
 * with one card — "your journey leaves in 51 minutes, 2 of 3 are coming, meet at
 * Gate 2" — and a half-rendered version of it on the first screen of the app is the
 * worst place to show a person a contradiction.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
});

/*
|--------------------------------------------------------------------------
| Screen 9 — the passenger's home
|--------------------------------------------------------------------------
*/

it('answers with an empty home for somebody who has just signed up', function () {
    fakeOtpSender();
    $token = passenger('01112223344');

    $data = test()->withToken($token)->getJson('/api/v1/home')->assertOk()->json('data');

    // Empty, not absent: a client that has to handle a missing key differently from an
    // empty one has two code paths for "nothing yet".
    expect($data['nextJourney'])->toBeNull()
        ->and($data['topMatches'])->toBe([])
        ->and($data['savedSearches'])->toBe([])
        ->and($data['greetingName'])->not->toBeEmpty();
});

it('tells a half-verified passenger how far along they are', function () {
    fakeOtpSender();
    $token = passenger('01112223344');

    $banner = test()->withToken($token)->getJson('/api/v1/home')
        ->assertOk()->json('data.verification');

    expect($banner['isComplete'])->toBeFalse()
        ->and($banner['of'])->toBeGreaterThan(0)
        ->and($banner['percentage'])->toBeLessThan(100);

    // Submitted and then approved, because there is nothing to approve otherwise — and
    // the banner is meant to move as the levels are actually earned.
    submitGovernmentId($token);
    approveVerification(VerificationType::GovernmentId);

    $after = test()->withToken($token)->getJson('/api/v1/home')
        ->assertOk()->json('data.verification');

    expect($after['level'])->toBeGreaterThan($banner['level'])
        ->and($after['percentage'])->toBeGreaterThan($banner['percentage']);
});

/**
 * The banner and the Verification Centre are two screens reading one thing. A passenger
 * told "1 of 3" on the home screen and "2 of 3" inside it would not know which to trust.
 */
it('says the same thing about verification as the Verification Centre does', function () {
    fakeOtpSender();
    $token = passenger('01112223344');

    $banner = test()->withToken($token)->getJson('/api/v1/home')->assertOk()->json('data.verification');
    $centre = test()->withToken($token)->getJson('/api/v1/account/verifications')->assertOk()->json('data');

    expect($banner['level'])->toBe($centre['level'])
        ->and($banner['of'])->toBe($centre['of'])
        ->and($banner['percentage'])->toBe($centre['percentage']);
});

it('leads with the journey that leaves soonest', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $card = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney');

    expect($card['tripId'])->toBe($this->tripId)
        // The whole card, in one response: where it goes, when, who is driving, which car.
        ->and($card['originLabel'])->toBe('Rehab Gate 2')
        ->and($card['destinationLabel'])->toBe('Smart Village B6')
        ->and($card['driver']['publicFirstName'])->not->toBeEmpty()
        ->and($card['vehicle']['model'])->not->toBeEmpty()
        ->and($card['attendance'])->toHaveKeys(['coming', 'away', 'awaiting', 'total'])
        // A trial ride, because that is what the helper asks for.
        ->and($card['isTrial'])->toBeTrue();
});

/**
 * 🔒 The plate identifies the car outside somebody's house. A confirmed booking has
 * earned it; nothing else has.
 */
it('gives the plate number only once the seat is confirmed', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $confirmed = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney');

    expect($confirmed['vehicle']['plateNumber'])->toBe(Vehicle::sole()->plate_number)
        // No meeting point of its own: this passenger meets the driver at the gate, and
        // the gate is the run's origin, which the card carries as `originLabel`.
        ->and($confirmed['meetingPoint'])->toBeNull()
        ->and($confirmed['originLabel'])->toBe('Rehab Gate 2');

    Booking::sole()->forceFill(['status' => 'pending'])->save();

    $pending = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney');

    expect($pending['vehicle']['plateNumber'])->toBeNull()
        // The colour and model stay: that is how you tell a silver car from a white one
        // at a busy gate, and it identifies nobody.
        ->and($pending['vehicle']['colour'])->not->toBeNull();
});

/**
 * 🔒 The same rule, on the meeting point that IS a specific place — which is the one the
 * rule exists for. A pickup point is often somebody's front door.
 */
it('fuzzes an agreed meeting point until the seat is confirmed', function () {
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->where('commute_offer_id', $commuteId)
        ->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    // The seat first, then the point: approving a point writes it onto bookings that
    // already exist, and before the seat is approved there are none.
    approveSeat($this->driverToken, $requestId);

    // Against the MEMBERSHIP, not the seat request: once the seat is approved the
    // request is answered, and the group screen is where a member asks to move their
    // meeting point.
    $member = GroupMember::query()
        ->whereIn('commute_group_id', CommuteGroup::query()->where('commute_offer_id', $commuteId)->select('id'))
        ->where('role', '!=', 'driver')
        ->sole();

    $pickupId = test()->withToken($paxToken)
        ->postJson("/api/v1/groups/{$member->commute_group_id}/pickup-request", onTheWay())
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")->assertOk();

    // The earlier commute's trip leaves sooner, so it is out of the way first.
    test()->withToken($this->driverToken)->deleteJson("/api/v1/commutes/{$this->commuteId}")->assertOk();

    $card = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney');

    expect($card['meetingPoint']['isExact'])->toBeTrue()
        ->and($card['meetingPoint']['lat'])->toBe(onTheWay()['lat']);

    Booking::query()->where('scheduled_trip_id', $tripId)
        ->update(['status' => 'pending']);

    $pending = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney');

    // ~110m: the right neighbourhood on a map, not a door to knock on.
    expect($pending['meetingPoint']['isExact'])->toBeFalse()
        ->and($pending['meetingPoint']['lat'])->toBe(round(onTheWay()['lat'], 3));
});

it('stops showing a journey once it is cancelled', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    $bookingId = approveSeat($this->driverToken, $requestId);

    test()->withToken($paxToken)->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    expect(test()->withToken($paxToken)->getJson('/api/v1/home')->assertOk()->json('data.nextJourney'))
        ->toBeNull();
});

/**
 * A commuter's history is hundreds of rows within a term. The card is ONE journey, and
 * which one is the whole question: the soonest, not the most recently booked.
 */
it('picks the soonest journey rather than the newest booking', function () {
    $paxToken = verifiedPassenger('01112223344');

    // A second commute on the same route leaving an hour LATER, booked SECOND. So "the
    // newest booking" and "the soonest journey" are deliberately different rows.
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);
    $otherVehicle = Vehicle::query()->where('plate_normalized', 'ABC1236')->sole();

    $laterCommute = readyCommute($otherDriver, $otherVehicle->id, schedule: ['departureTime' => '08:30:00']);
    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$laterCommute}/publish")->assertOk();

    $earlyTrip = ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)
        ->orderBy('departure_at')->first();
    $lateTrip = ScheduledTrip::query()->where('commute_offer_id', $laterCommute)
        ->orderBy('departure_at')->first();

    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $earlyTrip->id,
    ])->assertStatus(201)->json('data.id'));

    approveSeat($otherDriver, requestSeat($paxToken, $laterCommute, [
        'scheduledTripId' => $lateTrip->id,
    ])->assertStatus(201)->json('data.id'));

    $card = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney');

    expect($card['tripId'])->toBe($earlyTrip->id)
        ->and($lateTrip->departure_at->isAfter($earlyTrip->departure_at))->toBeTrue();
});

it('counts attendance as four states, not one fraction', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $group = CommuteGroup::sole();

    $before = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney.attendance');

    // Driver plus one passenger, neither having answered yet.
    expect($before)->toBe(['coming' => 0, 'away' => 0, 'awaiting' => 2, 'total' => 2]);

    test()->withToken($paxToken)->postJson("/api/v1/groups/{$group->id}/attendance", [
        'tripId' => $this->tripId,
        'status' => GroupAttendanceStatus::Coming->value,
    ])->assertStatus(201);

    $after = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.nextJourney.attendance');

    // 🔴 Somebody who said they are away and somebody who has not answered are not the
    // same person to a driver deciding whether to wait, so they are not the same number.
    expect($after)->toBe(['coming' => 1, 'away' => 0, 'awaiting' => 1, 'total' => 2]);
});

/**
 * The strip has room for three, so the three BEST matches are more useful than the three
 * most recent. The full list, newest first, is `GET /v1/matches`.
 */
it('offers the best matches it was told about, not the most recent', function () {
    $paxToken = verifiedPassenger('01112223344');

    // A saved request is what produces matches at all: the search that found nothing is
    // what a passenger saves, and the platform tells them when something turns up.
    test()->withToken($paxToken)->postJson('/api/v1/commute-demands', searchCriteria())
        ->assertStatus(201);

    $demandId = CommuteDemand::sole()->id;

    // One notification per demand per commute, so each score needs its own commute. The
    // scores are deliberately out of order, and the newest is not the best.
    $expected = [];

    foreach ([61, 94, 77, 30] as $score) {
        $offer = CommuteOffer::factory()->published()->create();

        MatchNotification::query()->create([
            'commute_demand_id' => $demandId,
            'commute_offer_id' => $offer->id,
            'score' => $score,
        ]);

        $expected[] = $score;
    }

    $matches = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.topMatches');

    rsort($expected);

    expect(array_column($matches, 'score'))->toBe(array_slice($expected, 0, 3))
        ->and($matches)->toHaveCount(3);
});

it('names the commute a match points at, not just that one was found', function () {
    $paxToken = verifiedPassenger('01112223344');

    test()->withToken($paxToken)->postJson('/api/v1/commute-demands', searchCriteria())
        ->assertStatus(201);

    MatchNotification::query()->create([
        'commute_demand_id' => CommuteDemand::sole()->id,
        'commute_offer_id' => $this->commuteId,
        'score' => 88,
    ]);

    $card = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.topMatches.0');

    // Without these the notification says a commute was found and not which one, so the
    // only way to tell two apart is to open both.
    expect($card['originLabel'])->toBe('Rehab Gate 2')
        ->and($card['destinationLabel'])->toBe('Smart Village B6')
        ->and($card['departureTimeLocal'])->toStartWith('07:05')
        ->and($card['timezone'])->toBe('Africa/Cairo')
        ->and($card['pricePerSeatPiastres'])->toBe(8000);
});

it('shows the saved corridors without running every saved search', function () {
    $paxToken = verifiedPassenger('01112223344');

    test()->withToken($paxToken)->postJson('/api/v1/saved-searches', [
        'title' => 'الرحاب ← القرية الذكية',
        'filters' => searchCriteria(),
    ])->assertStatus(201);

    $saved = test()->withToken($paxToken)->getJson('/api/v1/home')
        ->assertOk()->json('data.savedSearches');

    // A title and an id, and deliberately no match count: a count would mean running the
    // most expensive query in the product several times to render the home screen.
    expect($saved)->toHaveCount(1)
        ->and(array_keys($saved[0]))->toEqualCanonicalizing(['id', 'title']);
});

/**
 * 🔒 Neither home endpoint takes an id, so there is nothing to point at somebody else's
 * journey — but the scoping is what makes that true, and it is worth proving.
 */
it('never shows one passenger another passenger journey', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    expect(test()->withToken($stranger)->getJson('/api/v1/home')->assertOk()->json('data.nextJourney'))
        ->toBeNull();
});

it('requires a token', function () {
    // `withoutToken`, because `beforeEach` already set one as a default header and a bare
    // `getJson` would carry it — the test would pass against an authenticated request.
    test()->withoutToken()->getJson('/api/v1/home')->assertStatus(401);
    test()->withoutToken()->getJson('/api/v1/driver/home')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Screen 23 — the driver's home
|--------------------------------------------------------------------------
*/

it('gives a passenger an empty driver home rather than a refusal', function () {
    fakeOtpSender();
    $token = passenger('01112223344');

    $data = test()->withToken($token)->getJson('/api/v1/driver/home')->assertOk()->json('data');

    // 🔴 The role switch lives on this screen. A 404 would make tapping "drive" look
    // broken, when the truthful answer is that there is no run.
    expect($data['nextRun'])->toBeNull()
        ->and($data['pendingRequests'])->toBe(['count' => 0, 'items' => []])
        ->and($data['stats']['completedTrips'])->toBe(0);
});

it('puts the whole run on one card', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    expect($run['commuteId'])->toBe($this->commuteId)
        ->and($run['originLabel'])->toBe('Rehab Gate 2')
        ->and($run['destinationLabel'])->toBe('Smart Village B6')
        ->and($run['seatsTotal'])->toBe(3)
        ->and($run['seatsTaken'])->toBe(1)
        ->and($run['seatsOpen'])->toBe(2)
        ->and($run['passengers'])->toHaveCount(1)
        // The person, with the restraint that applies everywhere: a public first name and
        // what was verified, never a full name.
        ->and($run['passengers'][0]['person'])->toHaveKey('publicFirstName')
        ->and($run['passengers'][0]['person'])->not->toHaveKey('fullName')
        ->and($run['passengers'][0]['person'])->not->toHaveKey('phone')
        /*
         * Null, because this passenger meets the driver at the gate — and a booking only
         * stores a point of its own once a CUSTOM pickup was approved. The gate is the
         * run's origin, which the same card already carries as `originLabel`.
         */
        ->and($run['passengers'][0]['pickup'])->toBeNull()
        // Nobody has declared yet, which is not the same as being away.
        ->and($run['passengers'][0]['attendanceStatus'])->toBeNull();
});

/**
 * And once a custom point IS agreed, the driver gets it exactly: they have to drive to
 * it, and they are the one who approved it.
 */
it('gives the driver the exact point of a pickup they approved', function () {
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->where('commute_offer_id', $commuteId)
        ->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    // The seat first, then the point: approving a point writes it onto bookings that
    // already exist, and before the seat is approved there are none.
    approveSeat($this->driverToken, $requestId);

    // Against the MEMBERSHIP, not the seat request: once the seat is approved the
    // request is answered, and the group screen is where a member asks to move their
    // meeting point.
    $member = GroupMember::query()
        ->whereIn('commute_group_id', CommuteGroup::query()->where('commute_offer_id', $commuteId)->select('id'))
        ->where('role', '!=', 'driver')
        ->sole();

    $pickupId = test()->withToken($paxToken)
        ->postJson("/api/v1/groups/{$member->commute_group_id}/pickup-request", onTheWay())
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")->assertOk();

    // The earlier commute's trip is sooner, so ask about this run specifically by
    // archiving the other one out of the way.
    test()->withToken($this->driverToken)->deleteJson("/api/v1/commutes/{$this->commuteId}")->assertOk();

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    expect($run['commuteId'])->toBe($commuteId)
        ->and($run['passengers'][0]['pickup']['lat'])->toBe(onTheWay()['lat']);
});

/**
 * 🔴 The screen says "You collect EGP 240". With a cash commute that is two different
 * numbers — what to take at the door, and what of it is the driver's — so both are sent.
 */
it('says both what the driver collects and what they keep', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    $booking = Booking::sole();

    expect($run['collectPiastres'])->toBe($booking->price_snapshot_piastres)
        ->and($run['keepPiastres'])->toBe($booking->driver_amount_snapshot_piastres)
        // The platform's cut is the difference, and it is not zero.
        ->and($run['collectPiastres'] - $run['keepPiastres'])
        ->toBe($booking->platform_fee_snapshot_piastres);
});

/**
 * Pitfall #13 again: the money on a booking is frozen at approval. A driver raising their
 * price tomorrow must not change what today's card says they are owed.
 */
it('keeps reporting the money the bookings were approved at', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $before = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun.collectPiastres');

    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/commutes/{$this->commuteId}", ['pricePerSeatPiastres' => 11000])
        ->assertOk();

    expect(test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun.collectPiastres'))->toBe($before);
});

it('counts every waiting request and lists the ones the card has room for', function () {
    $paxToken = verifiedPassenger('01112223344');
    requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201);

    fakeOtpSender();
    $second = verifiedPassenger('01223339999', device: 'pax-2');
    requestSeat($second, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201);

    $pending = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.pendingRequests');

    expect($pending['count'])->toBe(2)
        ->and($pending['items'])->toHaveCount(2)
        // The driver decides who rides in their car from this, so the person comes with
        // what was verified about them.
        ->and($pending['items'][0]['passenger'])->toHaveKey('verifiedLevels');
});

/**
 * A driver with eleven requests must see "11", not the length of the list. A count that
 * quietly equalled the page size would hide work from them.
 */
it('does not let the count shrink to the size of the list', function () {
    $paxToken = verifiedPassenger('01112223344');

    // Seven requests across seven passengers; the card has room for five.
    $phones = ['01100000001', '01100000002', '01100000003', '01100000004',
        '01100000005', '01100000006', '01100000007'];

    foreach ($phones as $index => $phone) {
        fakeOtpSender();
        $token = verifiedPassenger($phone, device: "pax-{$index}");
        requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201);
    }

    $pending = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.pendingRequests');

    expect($pending['count'])->toBe(7)
        ->and($pending['items'])->toHaveCount(5);
});

it('answers the driver stats the card shows in a row', function () {
    DriverProfile::sole()->forceFill(['on_time_rate' => 98, 'completed_trips_count' => 12])->save();

    $stats = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.stats');

    expect((float) $stats['onTimeRate'])->toBe(98.0)
        ->and($stats['completedTrips'])->toBe(12)
        // Zero, not null: everybody meets where the driver already drives, so the detour
        // really is none. Null would claim there was nothing to measure.
        ->and((float) $stats['avgDetourMinutes'])->toBe(0.0);
});

it('gives a driver with no history no on-time rate rather than a zero', function () {
    $stats = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.stats');

    // A 0% badge on somebody's first morning is a number nobody earned.
    expect($stats['onTimeRate'])->toBeNull();
});

it('averages the detour over the pickups people actually got approved', function () {
    // `allows_custom_pickup` is FALSE by default in the product, so a commute that
    // accepts proposals has to say so.
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->where('commute_offer_id', $commuteId)
        ->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
        'meetingPreference' => 'custom',
    ])->assertStatus(201)->json('data.id');

    $pickupId = test()->withToken($paxToken)
        ->postJson("/api/v1/seat-requests/{$requestId}/pickup-request", onTheWay())->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")->assertOk();

    $average = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.stats.avgDetourMinutes');

    // Measured by us and never claimed by the requester — so whatever the number is, it
    // is the one the platform computed for that point.
    expect((float) $average)->toBe(
        (float) round(PickupPointRequest::sole()->added_minutes, 1),
    );
});

/**
 * The countdown both screens lead with. A phone with a skewed clock would otherwise
 * count down to the wrong moment, and a driver who believes they have 51 minutes when
 * they have 5 misses the run.
 */
it('counts down from the server clock, not the phone', function () {
    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    $expected = (int) round(now()->diffInMinutes(
        ScheduledTrip::query()->whereKey($run['tripId'])->sole()->departure_at,
        absolute: false,
    ));

    expect($run['departsInMinutes'])->toBeGreaterThanOrEqual($expected - 1)
        ->and($run['departsInMinutes'])->toBeLessThanOrEqual($expected + 1)
        // And the instant to count down from, since the minutes go stale immediately.
        ->and($run['departureAt'])->not->toBeEmpty();
});

/**
 * "Sunday · driving today" has to be a statement about the day where the commute
 * happens, not where the server is.
 */
it('decides whether the run is today from the local clock of the journey', function () {
    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    $trip = ScheduledTrip::query()->whereKey($run['tripId'])->sole();

    expect($run['isToday'])->toBe(
        $trip->departure_local->isSameDay(
            Carbon::now($trip->commuteSchedule->timezone),
        )
    );
});

it('keeps showing a run that is underway even though it has departed', function () {
    // 🔴 A run two hours late is exactly the run the driver most needs on their screen,
    // which is why the query asks whether the trip has finished and not what time it is.
    ScheduledTrip::query()->whereKey($this->tripId)->update([
        'departure_at' => now()->subHours(2),
        'departure_local' => now()->subHours(2),
        'status' => 'in_progress',
    ]);

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    expect($run['tripId'])->toBe($this->tripId)
        ->and($run['status'])->toBe('IN_PROGRESS')
        // Negative rather than clamped: running late is a fact the screen needs to show.
        ->and($run['departsInMinutes'])->toBeLessThan(0);
});

it('stops showing a run once it is finished', function () {
    ScheduledTrip::query()->update([
        'departure_at' => now()->subDays(1),
        'status' => 'cancelled',
    ]);

    expect(test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun'))->toBeNull();
});

it('never shows one driver another driver run', function () {
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);

    expect(test()->withToken($otherDriver)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun'))->toBeNull();
});

/**
 * The group's declared attendance drives "2 of 3 confirmed" and the per-passenger
 * "Coming" / "Away today" labels. Both are read from the same rows, so they cannot
 * disagree about who said what.
 */
it('labels each passenger with what they said, and counts the same answers', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $group = CommuteGroup::sole();

    test()->withToken($paxToken)->postJson("/api/v1/groups/{$group->id}/attendance", [
        'tripId' => $this->tripId,
        'status' => GroupAttendanceStatus::Away->value,
    ])->assertStatus(201);

    $run = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun');

    expect($run['passengers'][0]['attendanceStatus'])->toBe('away')
        ->and($run['attendance']['away'])->toBe(1)
        ->and($run['attendance']['coming'])->toBe(0)
        // The driver has still not answered.
        ->and($run['attendance']['awaiting'])->toBe(1);
});

it('counts the driver in the group total, which is what makes the fraction add up', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $attendance = test()->withToken($this->driverToken)->getJson('/api/v1/driver/home')
        ->assertOk()->json('data.nextRun.attendance');

    expect($attendance['total'])->toBe(
        GroupMember::query()->where('commute_group_id', CommuteGroup::sole()->id)
            ->where('status', 'active')->count()
    )
        ->and($attendance['total'])->toBe(2);
});
