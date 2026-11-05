<?php

use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 6 end to end: a passenger asks, a driver answers, a seat becomes a
 * booking and the two of them become a group.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    // A verified passenger, since getting into a stranger's car needs more than
    // searching does.
    $this->paxToken = verifiedPassenger('01112223344');
});

it('creates a request the driver has to answer, without holding the seat', function () {
    $request = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data');

    expect($request['status'])->toBe('PENDING')
        ->and($request['agreedToRulesAt'])->not->toBeNull()
        ->and($request['kind'])->toBe('seat_request')
        // Requests never expire (Feature 03 R-4): it waits for the driver's answer.
        ->and($request['expiresAt'])->toBeNull()
        // The seat is NOT held: an unanswered request must not block someone who
        // would have taken it.
        ->and(ScheduledTrip::query()->whereKey($this->tripId)->sole()->seats_taken)->toBe(0);
});

/**
 * `agreed_to_rules_at` is NOT NULL in the schema, and the reason is substantive: a
 * passenger who did not accept the rules has not agreed to the terms the group runs
 * on, and a driver approving them would be agreeing on their behalf.
 */
it('refuses a request that did not agree to the rules', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
        'agreedToRules' => false,
    ])->assertStatus(422);

    expect(SeatRequest::count())->toBe(0);
});

it('refuses a second open request for the same commute', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201);

    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_ALREADY_REQUESTED');
});

it('is the database that forbids a second open request, not just the code', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201);

    $existing = SeatRequest::sole();

    expect(fn () => SeatRequest::factory()->create([
        'passenger_user_id' => $existing->passenger_user_id,
        'commute_offer_id' => $existing->commute_offer_id,
        'status' => SeatRequestStatus::Pending->value,
    ]))->toThrow(QueryException::class);
});

it('refuses a driver booking their own commute', function () {
    requestSeat($this->driverToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'BOOKING_OWN_COMMUTE');
});

/**
 * 🔴 Feature 03 C-11 (BOOK-31): a women-only commute is visible to everybody, and only a woman may
 * ask for a seat on it. A 403 with its own code — the commute is no secret any more.
 */
it('refuses a man a seat on a women-only commute, with its own code', function () {
    $manToken = verifiedPassenger('01223334455', gender: 'man', device: 'pax-man');

    requestSeat($manToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_WOMEN_ONLY');

    expect(SeatRequest::count())->toBe(0);
});

it('refuses an account that never said she is a woman, too', function () {
    $token = verifiedPassenger('01223334466', gender: 'prefer_not_to_say', device: 'pax-unsaid');

    // F1-2: and is told what would change the answer.
    requestSeat($token, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_WOMEN_ONLY')
        ->assertJsonPath('error.fields.reason.0', 'GENDER_NOT_SET');
});

it('does not tell a man to set his gender', function () {
    $manToken = verifiedPassenger('01223334455', gender: 'man', device: 'pax-man');

    requestSeat($manToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(403)
        ->assertJsonPath('error.fields', null);
});

it('lets a man ask for a seat on a commute open to everybody', function () {
    CommuteOffer::query()->whereKey($this->commuteId)->update(['audience' => 'any_verified']);

    $manToken = verifiedPassenger('01223334455', gender: 'man', device: 'pax-man');

    requestSeat($manToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201);
});

it('refuses a trip id that belongs to a different commute', function () {
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);
    $otherVehicle = Vehicle::query()->where('plate_normalized', 'ABC1236')->sole();
    $otherCommute = readyCommute($otherDriver, $otherVehicle->id);

    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    $theirTrip = ScheduledTrip::query()->where('commute_offer_id', $otherCommute)->first();

    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $theirTrip->id])
        ->assertStatus(404);
});

/**
 * 🔴 Feature 03 R-1 (BOOK-18): a request on a full day is an application to join the waiting list —
 * never a refusal, and never on the list without the driver's yes.
 */
it('takes a request on a full day as a waiting-list application for the driver to approve', function () {
    ScheduledTrip::query()->whereKey($this->tripId)->update(['seats_taken' => 3]);

    $request = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data');

    expect($request['status'])->toBe('PENDING')
        ->and($request['kind'])->toBe('waitlist_application')
        ->and($request['waitlistPosition'])->toBeNull();

    // The driver sees it in his inbox, marked, and his yes puts her on the list.
    test()->withToken($this->driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'waitlist_application')
        ->assertJsonPath('data.0.hasOtherConfirmedCommute', false);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$request['id']}/waitlist")
        ->assertOk()
        ->assertJsonPath('data.status', 'WAITLISTED')
        ->assertJsonPath('data.waitlistPosition', 1)
        ->assertJsonPath('data.expiresAt', null);
});

it('takes a recurring request as an application when every day it asks for is full', function () {
    ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->update(['seats_taken' => 3]);

    requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'scheduledTripId' => null,
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.kind', 'waitlist_application');
});

it('keeps a recurring request a seat request while any day it asks for has room', function () {
    ScheduledTrip::query()->whereKey($this->tripId)->update(['seats_taken' => 3]);

    requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'scheduledTripId' => null,
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)
        ->assertJsonPath('data.kind', 'seat_request');
});

it('approves a request into a confirmed booking with the money frozen', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $approval = test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)->json('data');

    // A trial asked for one day, so exactly one booking and nothing skipped.
    expect($approval['seatedDays'])->toBe(1)
        ->and($approval['skippedDays'])->toBe([])
        ->and($approval['membership']['role'])->toBe('trial');

    $booking = $approval['bookings'][0];
    $trip = ScheduledTrip::query()->whereKey($this->tripId)->sole();

    expect($booking['status'])->toBe('CONFIRMED')
        ->and($booking['seatsReserved'])->toBe(1)
        // The day's automatic price, frozen (P-1, PAY-44). The approval is read by the driver, so
        // it carries his share; it never carries the commission or its rate (P-3).
        ->and($booking['price'])->toBe([
            'seatPricePiastres' => $trip->seat_price_piastres,
            'totalPiastres' => $trip->seat_price_piastres,
            'driverSharePiastres' => $trip->driver_share_piastres,
            'driverShareTotalPiastres' => $trip->driver_share_piastres,
        ])
        // Cash is settled in the car, so nothing is owed to the platform yet.
        ->and($booking['paymentStatus'])->toBe('NOT_DUE')
        ->and(ScheduledTrip::query()->whereKey($this->tripId)->sole()->seats_taken)->toBe(1);
});

/**
 * One of the Bible's non-negotiable tests: the two halves must sum to the whole for
 * every price, or the ledger never reconciles.
 */
it('always splits the price into two halves that sum exactly', function (int $pricePiastres) {
    $commission = intdiv($pricePiastres, 11);

    ScheduledTrip::query()->whereKey($this->tripId)->update([
        'seat_price_piastres' => $pricePiastres,
        'driver_share_piastres' => $pricePiastres - $commission,
        'commission_piastres' => $commission,
    ]);

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201);

    $booking = Booking::sole();

    expect($booking->platform_fee_snapshot_piastres + $booking->driver_amount_snapshot_piastres)
        ->toBe($booking->price_snapshot_piastres)
        ->and($booking->price_snapshot_piastres)->toBe($pricePiastres);
})->with([8000, 8333, 1, 501, 49999]);

/**
 * Pitfall #42 and #13: the fee percentage and the driver's price can both change
 * tomorrow, and neither may move money on a booking that already exists.
 */
it('keeps the frozen money when the day is repriced afterwards', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $agreed = Booking::sole()->price_snapshot_piastres;

    ScheduledTrip::query()->whereKey($this->tripId)->update([
        'seat_price_piastres' => 99500, 'driver_share_piastres' => 90000, 'commission_piastres' => 9500,
    ]);

    expect(Booking::sole()->price_snapshot_piastres)->toBe($agreed);
});

it('creates the group on first approval, with the driver in it', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $group = CommuteGroup::sole();
    $members = GroupMember::query()->where('commute_group_id', $group->id)->get();

    expect($group->commute_offer_id)->toBe($this->commuteId)
        // Named after the journey: "Group 47" would tell its members nothing.
        ->and($group->name)->toContain('Rehab Gate 2')
        ->and($members)->toHaveCount(2)
        // A group whose driver is not in it would leave every member list missing
        // the one person who is always there.
        ->and($members->pluck('role')->map->value->all())
        ->toEqualCanonicalizing(['driver', 'trial']);
});

it('joins a trial rider as a trial, not as a committed member', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $member = GroupMember::query()->where('role', GroupMemberRole::Trial->value)->sole();

    // One trial ride is not a commitment to a week, and the distinction is what
    // lets the group show its real regulars.
    expect($member->committed_days_mask)->toBeNull();
});

it('records every status change in an append-only log', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    test()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel", ['reason' => 'Plans changed'])->assertOk();

    $events = BookingEvent::query()->orderBy('created_at')->get();

    expect($events->pluck('event_type')->map->value->all())->toBe(['confirmed', 'cancelled'])
        ->and($events->last()->actor_type->value)->toBe('passenger');

    // The record cannot be tidied afterwards by whoever has an interest in how it
    // reads.
    expect(fn () => $events->first()->update(['event_type' => 'completed']))
        ->toThrow(RuntimeException::class);
});

it('refuses to approve a request that was already answered', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});

it('lets the driver decline, without inflating anything for a withdrawal', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/reject", ['note' => 'Car is full this week'])
        ->assertOk()
        ->assertJsonPath('data.status', 'REJECTED')
        ->assertJsonPath('data.responseNote', 'Car is full this week');
});

it('lets the passenger withdraw before an answer', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->paxToken)
        ->deleteJson("/api/v1/seat-requests/{$requestId}")
        ->assertOk()
        // Withdrawn, not rejected: a driver's refusal rate must not include
        // requests they never saw.
        ->assertJsonPath('data.status', 'WITHDRAWN');
});

/**
 * 🔴 Feature 03 R-5 (follow-up N2): no booking deadline. A day leaving in half an hour can still be
 * asked for and approved — under the old rule it stopped taking requests at 21:00 last night and
 * answered `BOOKING_DEADLINE_PASSED`.
 */
it('takes and approves a request right up to the departure', function () {
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->addMinutes(30)]);

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201);
});

/**
 * 🔴 Feature 03 R-4 (BOOK-36, decision C2-note): requests never expire. The 48-hour window is gone.
 */
it('never expires a request nobody has answered yet', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201);

    $this->travel(5)->days();

    expect(RespondToSeatRequestAction::expireOverdue())->toBe(0)
        ->and(SeatRequest::sole()->status)->toBe(SeatRequestStatus::Pending);
});

/**
 * R-4: "a one-day request whose date has passed closes (`CLOSED_DATE_PASSED`)" — and only then.
 */
it('closes a one-day request once its day has passed', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $tripDate = ScheduledTrip::query()->whereKey($this->tripId)->sole()->trip_date;

    // Still the day itself in Cairo: nothing closes.
    $this->travelTo($tripDate->copy()->setTimezone('Africa/Cairo')->setTime(23, 0));
    expect(RespondToSeatRequestAction::closeDatePassed())->toBe(0);

    $this->travelTo($tripDate->copy()->addDays(2));
    expect(RespondToSeatRequestAction::closeDatePassed())->toBe(1);

    // Out of her one open request on this commute, so she can ask again. (Read from the row: two
    // days on, her access token has long expired.)
    expect(SeatRequest::sole()->id)->toBe($requestId)
        ->and(SeatRequest::sole()->status)->toBe(SeatRequestStatus::ClosedDatePassed);
});

it('leaves a recurring request open when one of its days passes', function () {
    requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'scheduledTripId' => $this->tripId,
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201);

    $this->travelTo(ScheduledTrip::query()->whereKey($this->tripId)->sole()->trip_date->copy()->addDays(2));

    expect(RespondToSeatRequestAction::closeDatePassed())->toBe(0)
        ->and(SeatRequest::sole()->status)->toBe(SeatRequestStatus::Pending);
});

/**
 * 🔒 Chapter 6's security section: "passengers cannot access others' bookings" and
 * "drivers see only their own".
 */
it('hides a booking from everyone except its passenger and its driver', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    // Both parties can see it.
    test()->withToken($this->paxToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk();
    test()->withToken($this->driverToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk();

    // Nobody else can, and gets a 404 rather than a 403.
    $stranger = verifiedPassenger('01223334455', device: 'pax-3');

    test()->withToken($stranger)->getJson("/api/v1/bookings/{$bookingId}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    test()->withToken($stranger)->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertStatus(404);

    expect(Booking::sole()->status)->toBe(BookingStatus::Confirmed);
});

/**
 * Which side cancelled is read from who the caller IS, never from the request:
 * otherwise a passenger could record their own cancellation as the driver's, moving
 * the blame for a missed ride.
 */
it('attributes a cancellation to the side that actually made it', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED_BY_DRIVER');
});

it('charges nothing to cancel, because the policy does not exist yet', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    // Open question #7 in MASTER_PLAN §19, deferred to Phase 8. A guessed fee would
    // take money from a real person on the strength of an assumption.
    test()->withToken($this->paxToken)->patchJson("/api/v1/bookings/{$bookingId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.cancellationFeePiastres', 0);
});

it('refuses to cancel a booking twice', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $bookingId = approveSeat($this->driverToken, $requestId);

    test()->withToken($this->paxToken)->patchJson("/api/v1/bookings/{$bookingId}/cancel")->assertOk();

    test()->withToken($this->paxToken)->patchJson("/api/v1/bookings/{$bookingId}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_NOT_CANCELLABLE');
});

/**
 * 🔴 This test used to assert the OPPOSITE — that asking for a seat needed a verified identity —
 * and it is inverted rather than deleted, because an accidentally reinstated gate would lock every
 * passenger out of the product and no other test would notice.
 *
 * Feature 02 v3: "No passenger verification. Passenger can book without ID verification",
 * confirmed as a product decision. What replaced the gate is the driver: she approves every seat
 * request by hand and sees the passenger's trust level before she does.
 */
it('lets an unverified passenger ask for a seat, with nothing but a verified phone', function () {
    fakeOtpSender();
    $unverified = signIn(phone: '01223338888', devicePublicId: 'pax-unverified')['session']['accessToken'];
    completeBasicProfile($unverified);

    search($unverified)->assertOk();

    requestSeat($unverified, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201);
});

/**
 * 🔒 And the asymmetry that makes the above safe: the person CARRYING strangers is still checked.
 *
 * Asserted here, next to the relaxation, so that anybody loosening the passenger side again has to
 * walk past the reason it was safe to do so.
 */
it('still refuses an unverified identity the driver surface', function () {
    fakeOtpSender();
    $unverified = signIn(phone: '01223337777', devicePublicId: 'drv-unverified')['session']['accessToken'];
    completeBasicProfile($unverified);

    // The application itself is open in any order since F2-1 (the five items are checked on
    // submit), so the gate that still stands is publishing a commute.
    test()->withToken($unverified)->postJson('/api/v1/commutes/01JABCDEFGHJKMNPQRSTVWXYZ0/publish')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');
});

it('shows a driver the requests on their own commutes only', function () {
    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201);

    $inbox = test()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/seat-requests')->assertOk()->json('data');

    expect($inbox)->toHaveCount(1)
        // A driver deciding who rides in their car sees a public first name and what
        // has been verified — never a full name, a phone number or a gender.
        ->and($inbox[0]['passenger'])->toHaveKey('publicFirstName')
        ->and($inbox[0]['passenger'])->not->toHaveKey('fullName')
        ->and($inbox[0]['passenger'])->not->toHaveKey('gender');
});

it('answers 404 when a driver tries to approve a request on someone else commute', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);

    test()->withToken($otherDriver)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(404);

    expect(SeatRequest::sole()->status)->toBe(SeatRequestStatus::Pending);
});
