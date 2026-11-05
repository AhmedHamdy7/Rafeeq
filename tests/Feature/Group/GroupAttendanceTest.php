<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupAttendance;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\Storage;

/**
 * "I'm coming tomorrow" / "I'm away tomorrow" — the declared attendance that lets a
 * driver plan their morning.
 *
 * ⚠️ NOT the check-in. `group_attendance` is what somebody said in advance;
 * `attendance` (Phase 9) is whether they actually got in the car, and that one drives
 * billing. The ERD warns about the confusion twice, so these tests assert the
 * distinction rather than assuming it — with one exception decided later: "away" cancels the
 * seat (TRIP-26).
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->first();
    $this->paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    approveSeat($this->driverToken, $requestId);

    $this->groupId = CommuteGroup::sole()->id;
});

function declareAttendance(string $token, string $groupId, string $tripId, string $status)
{
    return test()->withToken($token)->postJson("/api/v1/groups/{$groupId}/attendance", [
        'tripId' => $tripId,
        'status' => $status,
    ]);
}

it('records a declaration for one day', function () {
    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'coming')
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'COMING')
        ->assertJsonPath('data.tripId', $this->trip->id);

    expect(GroupAttendance::sole()->marked_at)->not->toBeNull();
});

/**
 * One declaration per person per day: changing your mind replaces the answer rather
 * than adding a second one. A unique index enforces it; this decides what "changing
 * your mind" means.
 */
it('replaces an answer instead of recording a second one', function () {
    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'coming')->assertStatus(201);
    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'away')->assertStatus(201);

    expect(GroupAttendance::count())->toBe(1)
        ->and(GroupAttendance::sole()->status)->toBe(GroupAttendanceStatus::Away);
});

it('shows the whole group who is travelling', function () {
    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'coming')->assertStatus(201);
    declareAttendance($this->driverToken, $this->groupId, $this->trip->id, 'coming')->assertStatus(201);

    // The driver sees the answers, which is the entire point of collecting them.
    test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/attendance?tripId={$this->trip->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        // 🔒 And still only as public first names — the passenger answered first, and
        // `سارة` is all the driver learns of her from this.
        ->assertJsonPath('data.0.person.publicFirstName', 'سارة');
});

/**
 * The cutoff is the point of the feature: after it the driver is already planning
 * around the answer they were given, so a silent change would make the declaration
 * worthless.
 */
it('refuses a change once the driver is already planning around the answer', function () {
    $cutoff = (int) config('rafeeq.group.attendance_cutoff_hours');

    // An hour inside the cutoff.
    $this->trip->forceFill(['departure_at' => now()->addHours($cutoff - 1)])->save();

    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'away')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'GROUP_ATTENDANCE_NOT_DECLARABLE');

    expect(GroupAttendance::count())->toBe(0);
});

it('refuses a declaration for a cancelled day', function () {
    $this->trip->forceFill(['status' => 'cancelled'])->save();

    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'coming')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'GROUP_ATTENDANCE_NOT_DECLARABLE');
});

/**
 * 🔒 A trip id from another commute must not become a way to write into, or read
 * from, a group the caller is not in.
 */
it('refuses a day that belongs to a different commute', function () {
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);
    $otherVehicle = Vehicle::query()->where('plate_normalized', 'ABC1236')->sole();
    $otherCommute = readyCommute($otherDriver, $otherVehicle->id);

    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    $theirTrip = ScheduledTrip::query()->where('commute_offer_id', $otherCommute)->first();

    declareAttendance($this->paxToken, $this->groupId, $theirTrip->id, 'coming')
        ->assertStatus(404);

    expect(GroupAttendance::count())->toBe(0);
});

it('refuses a declaration from somebody outside the group', function () {
    $stranger = verifiedPassenger('01223334455', device: 'pax-2');

    declareAttendance($stranger, $this->groupId, $this->trip->id, 'coming')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

/**
 * ⚠️ Declaring you are coming is not boarding, and touches nothing.
 */
it('changes nothing about the seat when she says she is coming', function () {
    $before = $this->trip->refresh()->seats_taken;

    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'coming')->assertStatus(201);

    expect($this->trip->refresh()->seats_taken)->toBe($before);
});

/**
 * 🔴 TRIP-26 (Feature 03 backend file T-5): "I can't make it" cancels that day under the ordinary
 * cancel rules. Days ahead, that is free.
 */
it('cancels her seat that day when she says she is away, for free days ahead', function () {
    $before = $this->trip->refresh()->seats_taken;

    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'away')->assertStatus(201);

    $booking = Booking::query()->where('scheduled_trip_id', $this->trip->id)->sole();

    expect($this->trip->refresh()->seats_taken)->toBe($before - 1)
        ->and($booking->status)->toBe(BookingStatus::CancelledByPassenger)
        ->and(MemberWarning::query()->exists())->toBeFalse();
});

it('makes "away" within 12 hours a late cancel', function () {
    $this->trip->forceFill(['departure_at' => now()->addHours(5)])->save();
    Booking::query()->where('scheduled_trip_id', $this->trip->id)->update(['agreed_pickup_at' => null]);

    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'away')->assertStatus(201);

    expect(MemberWarning::query()->sole()->kind)->toBe(WarningKind::LateCancel);
});

/*
|--------------------------------------------------------------------------
| TRIPS-20 → TRIPS-23: the Trips tabs' booking and group fields
|--------------------------------------------------------------------------
*/

it('marks her own row in the attendance list, and nobody else\'s (TRIPS-23)', function () {
    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'coming')->assertSuccessful();

    $rows = test()->withToken($this->paxToken)->getJson("/api/v1/groups/{$this->groupId}/attendance")
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['isMe'])->toBeTrue();

    $asDriver = test()->withToken($this->driverToken)->getJson("/api/v1/groups/{$this->groupId}/attendance")
        ->assertOk()->json('data');

    expect($asDriver[0]['isMe'])->toBeFalse();
});

it('says how long a pause may be, and until when she is paused (TRIPS-22)', function () {
    test()->withToken($this->paxToken)->getJson("/api/v1/groups/{$this->groupId}")
        ->assertOk()
        ->assertJsonPath('data.maxPauseDays', 31);

    $to = $this->trip->trip_date->copy()->addDays(10)->toDateString();

    test()->withToken($this->paxToken)->postJson("/api/v1/groups/{$this->groupId}/absences", [
        'fromDate' => $this->trip->trip_date->toDateString(),
        'toDate' => $to,
    ])->assertSuccessful();

    $request = test()->withToken($this->paxToken)->getJson('/api/v1/seat-requests')->assertOk()->json('data.0');

    expect($request['status'])->toBe('WAITLISTED')
        ->and($request['pausedAt'])->not->toBeNull()
        ->and($request['pausedUntil'])->toBe($to);
});

it('carries the promised arrival and her membership on the booking (TRIPS-20, TRIPS-21)', function () {
    $booking = test()->withToken($this->paxToken)->getJson('/api/v1/my-bookings')->assertOk()->json('data.0');

    expect($booking['trip'])->toHaveKeys(['promisedArrivalAt', 'promisedArrivalLocal'])
        ->and($booking['membership'])->not->toBeNull()
        ->and($booking['membership'])->toHaveKeys(['committedDaysMask', 'commitment', 'status'])
        ->and($booking['membership']['commitment'])->toBeIn(['trial', 'recurring']);
});
