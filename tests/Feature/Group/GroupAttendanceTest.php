<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupAttendance;
use Illuminate\Support\Facades\Storage;

/**
 * "I'm coming tomorrow" / "I'm away tomorrow" — the declared attendance that lets a
 * driver plan their morning.
 *
 * ⚠️ NOT the check-in. `group_attendance` is what somebody said in advance;
 * `attendance` (Phase 9) is whether they actually got in the car, and that one drives
 * billing. The ERD warns about the confusion twice, so these tests assert the
 * distinction rather than assuming it.
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
 * ⚠️ Declaring you are coming is not boarding, and must not touch seats or money.
 */
it('changes nothing about the seat count or the booking', function () {
    $before = $this->trip->refresh()->seats_taken;

    declareAttendance($this->paxToken, $this->groupId, $this->trip->id, 'away')->assertStatus(201);

    // "I'm away" is information for the driver. Releasing the seat is a planned
    // absence, which is a different and deliberate decision.
    expect($this->trip->refresh()->seats_taken)->toBe($before);
});
