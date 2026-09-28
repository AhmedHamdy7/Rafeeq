<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Support\Facades\Storage;

/**
 * Who actually travelled — decision D18, and the safeguards that make it acceptable.
 *
 * 🔴 D18 makes the DRIVER's tap decide whether a passenger is charged. The Master Plan
 * (§15.6) states what that is: "one party deciding the other's bill", and attaches four
 * conditions. Two of them are code in this slice — a 24-hour window to contest it, and GPS
 * recorded as supporting evidence rather than proof. These tests are mostly about those,
 * because the power without them is the thing §15.6 was written to prevent.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

/*
|--------------------------------------------------------------------------
| The driver records
|--------------------------------------------------------------------------
*/

it('records a passenger as present when the driver confirms them', function () {
    underway($this->driverToken, $this->tripId);

    $row = checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertOk()->json('data');

    expect($row['status'])->toBe('PRESENT')
        ->and($row['travelled'])->toBeTrue()
        ->and($row['checkedInAt'])->not->toBeNull()
        // 'driver', per D18 — sent rather than assumed, so an old record still says which
        // method produced it once a second one exists.
        ->and($row['confirmedBy'])->toBe('driver')
        ->and($row['confirmedAt'])->not->toBeNull();
});

it('records a no-show as an explicit act, not by omission', function () {
    underway($this->driverToken, $this->tripId);

    $row = test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])
        ->assertOk()->json('data');

    expect($row['status'])->toBe('PASSENGER_NO_SHOW')
        ->and($row['travelled'])->toBeFalse()
        // Nobody was collected, so there is no boarding time.
        ->and($row['checkedInAt'])->toBeNull();
});

/**
 * 🔒 The safeguard behind the safeguard. Once a driver has said somebody was in the car she
 * cannot take it back — otherwise a passenger charged on Monday could be recorded absent on
 * Friday and the dispute window would protect nothing.
 */
it('will not let the driver change her mind once she has decided', function () {
    underway($this->driverToken, $this->tripId);

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_NOT_CONFIRMABLE')
        // The refusal names what the record already says, because her screen may just be
        // stale — "already marked present" is a different thing to be told.
        ->assertJsonPath('error.fields.currentStatus.0', 'PRESENT');

    expect(Attendance::sole()->status)->toBe(AttendanceStatus::Present);
});

it('refuses a check-in before the run has left', function () {
    runLeavingIn($this->tripId, 10);

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);

    // Preparing: a claim that somebody is in a car that is still parked.
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.tripStatus.0', 'PREPARING');
});

it('refuses a check-in on a run that has finished', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    // A record being edited after the fact.
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.tripStatus.0', 'COMPLETED');
});

it('refuses to start a run and check in without ever starting it', function () {
    runLeavingIn($this->tripId, 10);

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_STARTED');
});

/*
|--------------------------------------------------------------------------
| GPS as evidence, never as proof
|--------------------------------------------------------------------------
*/

/**
 * 🔒 The Master Plan says it in as many words: "not a condition for confirmation, but
 * recorded for the dispute". A confirmation that REQUIRED a good fix would fail in a
 * basement car park, and the driver would learn to check people in from the street.
 */
it('confirms a passenger with no position at all', function () {
    underway($this->driverToken, $this->tripId);

    $row = checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])
        ->assertOk()->json('data');

    expect($row['status'])->toBe('PRESENT')
        ->and($row['gpsCorroborated'])->toBeFalse()
        ->and($row['gpsConfidence'])->toBeNull();
});

it('records a strong corroboration when the driver is at the meeting point', function () {
    // A booking with an agreed point of its own, since a gate pickup has none.
    $member = GroupMember::query()->where('role', 'trial')->sole();

    $pickupId = test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$member->commute_group_id}/pickup-request", onTheWay())
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")->assertOk();

    underway($this->driverToken, $this->tripId);

    $row = checkIn($this->driverToken, $this->tripId, [
        'bookingId' => $this->bookingId,
        'lat' => onTheWay()['lat'],
        'lng' => onTheWay()['lng'],
    ])->assertOk()->json('data');

    expect($row['gpsCorroborated'])->toBeTrue()
        // A number, not a flag: a dispute is settled on how strong the evidence was, and
        // "within 250 metres" hides the difference between five metres and two hundred.
        ->and($row['gpsConfidence'])->toBeGreaterThan(0.9);
});

it('records a weak corroboration when the driver is nowhere near, and confirms anyway', function () {
    $member = GroupMember::query()->where('role', 'trial')->sole();

    $pickupId = test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$member->commute_group_id}/pickup-request", onTheWay())
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/pickup-requests/{$pickupId}/approve")->assertOk();

    underway($this->driverToken, $this->tripId);

    $row = checkIn($this->driverToken, $this->tripId, [
        'bookingId' => $this->bookingId,
        // Kilometres away.
        'lat' => 30.1200,
        'lng' => 31.4000,
    ])->assertOk()->json('data');

    // 🔴 Still PRESENT. The evidence is weak and it is recorded as weak — it does not veto
    // the driver, because the whole reason D18 exists is that the mechanical checks fail on
    // honest mornings.
    expect($row['status'])->toBe('PRESENT')
        ->and($row['gpsCorroborated'])->toBeFalse()
        /*
         * Zero, not null — and compared numerically, because `0.0` serialises to JSON as
         * `0` and `toBe` is identity. A reading was taken and it disagrees, which is a
         * different fact from having no reading at all.
         */
        ->and((float) $row['gpsConfidence'])->toBe(0.0)
        ->and($row['gpsConfidence'])->not->toBeNull();
});

it('demands both halves of a position or neither', function () {
    underway($this->driverToken, $this->tripId);

    checkIn($this->driverToken, $this->tripId, [
        'bookingId' => $this->bookingId,
        'lat' => 30.0654,
    ])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| The passenger contests — §15.6 safeguard 1
|--------------------------------------------------------------------------
*/

it('lets a passenger say the record is wrong', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    $row = test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", [
            'reason' => 'أنا ملحقتش أركب، العربية مشيت قبل ما أوصل.',
        ])->assertOk()->json('data');

    expect($row['disputedAt'])->not->toBeNull()
        ->and($row['disputeReason'])->toContain('ملحقتش')
        // 🔴 The status does NOT change. A dispute records that a record is contested;
        // letting the passenger set the value would move the same unchecked power to the
        // other side.
        ->and($row['status'])->toBe('PRESENT')
        // And nobody has decided it yet.
        ->and($row['disputeResolution'])->toBeNull();
});

it('lets a passenger contest a no-show recorded against them', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();

    // The case that matters most: a mark on somebody's record that they say is wrong.
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", [
            'reason' => 'كنت واقفة عند البوابة وهي ما جاتش.',
        ])->assertOk()
        ->assertJsonPath('data.status', 'PASSENGER_NO_SHOW')
        ->assertJsonPath('data.disputedAt', fn ($value) => $value !== null);
});

/**
 * 🔒 Measured from when the DRIVER decided, not from when the trip ended. Timed from
 * completion, a driver who marks somebody absent a day later would hand them a window that
 * had already closed — which turns the safeguard into paperwork.
 */
it('still accepts a dispute ten minutes before the window closes', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    /*
     * The DECISION is moved back rather than the clock forward: an access token lives
     * fifteen minutes, so travelling nearly a day ahead would 401 and the test would pass
     * or fail for a reason that has nothing to do with the window.
     */
    Attendance::sole()->forceFill([
        'confirmed_at' => now()->subHours(23)->subMinutes(50),
    ])->save();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'ده مش صح خالص.'])
        ->assertOk();
});

it('refuses a dispute once the window has closed', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    // Moved backwards on the record rather than forwards on the clock: travelling a day
    // ahead expires the access token, and the test would fail on a 401 for a reason that
    // has nothing to do with the window.
    Attendance::sole()->forceFill(['confirmed_at' => now()->subHours(25)])->save();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'ده مش صح خالص.'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATTENDANCE_DISPUTE_WINDOW_CLOSED')
        // The refusal says when it closed, so the app can point at support instead.
        ->assertJsonPath('error.fields.closedAt.0', fn ($value) => $value !== null);
});

it('follows the window length from settings rather than from code', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    Attendance::sole()->forceFill(['confirmed_at' => now()->subHours(40)])->save();

    // A platform that decides 48 hours is fairer must be able to say so without a deploy.
    PlatformSetting::query()->create([
        'setting_key' => 'trip.dispute_window_hours',
        'setting_value' => 48,
        'value_type' => 'integer',
        'description' => 'A longer window',
    ]);

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'ده مش صح خالص.'])
        ->assertOk();
});

it('has nothing to dispute while the record says nobody decided', function () {
    underway($this->driverToken, $this->tripId);

    // A pending row already means "not recorded", so there is no claim to contest.
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'ده مش صح خالص.'])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.currentStatus.0', 'PENDING');
});

it('refuses a second dispute rather than quietly replacing the first', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'السبب الأول بالتفصيل.'])
        ->assertOk();

    // A second reason arriving after a reviewer has started reading the first would
    // replace the thing they are deciding about.
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'سبب تاني مختلف خالص.'])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.dispute.0', 'ALREADY_RAISED');
});

it('wants a reason a reviewer can act on', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    // "no" gives a reviewer nothing to decide on, and wastes the window it was raised in.
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'لا'])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Who may do what
|--------------------------------------------------------------------------
*/

it('never lets a passenger mark themselves present', function () {
    underway($this->driverToken, $this->tripId);

    // 🔒 The whole of D18: the driver records, because she is the only one who knows who
    // got in. There is no route that lets the other side do it.
    checkIn($this->paxToken, $this->tripId, ['bookingId' => $this->bookingId])->assertStatus(404);

    expect(Attendance::sole()->status)->toBe(AttendanceStatus::Pending);
});

it('never lets a driver dispute her own record', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    // A dispute goes to somebody who is neither party. A driver dismissing or raising one
    // would be deciding her own case.
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'أنا مش موافقة على ده.'])
        ->assertStatus(404);
});

it('never lets one driver check in on another driver run', function () {
    underway($this->driverToken, $this->tripId);

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);

    checkIn($otherDriver, $this->tripId, ['bookingId' => $this->bookingId])->assertStatus(404);
});

it('refuses a booking from another day pointed at this run', function () {
    $second = ScheduledTrip::query()->where('id', '!=', $this->tripId)->orderBy('departure_at')->first();

    fakeOtpSender();
    $otherPax = verifiedPassenger('01223339999', device: 'pax-2');
    $otherBooking = approveSeat($this->driverToken, requestSeat($otherPax, $this->commuteId, [
        'scheduledTripId' => $second->id,
    ])->assertStatus(201)->json('data.id'));

    underway($this->driverToken, $this->tripId);

    // 404 rather than a refusal naming the mismatch, so a booking id cannot be probed by
    // pointing it at a run the caller does drive.
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $otherBooking])->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| The driver's list
|--------------------------------------------------------------------------
*/

it('gives the driver the attendance list for her run', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    $rows = test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/attendance")
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('PRESENT')
        // The person, with the restraint that applies everywhere: a public first name and
        // what was verified, never a full name.
        ->and($rows[0]['person'])->toHaveKey('publicFirstName')
        ->and($rows[0]['person'])->not->toHaveKey('fullName');
});

/**
 * 🔒 Not offered to passengers. Who was marked absent this morning is not something to hand
 * a fellow passenger — the group endpoints serve what members may know about each other.
 */
it('does not show the attendance list to a passenger on the run', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->paxToken)->getJson("/api/v1/trips/{$this->tripId}/attendance")
        ->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| Completion, once somebody really was aboard
|--------------------------------------------------------------------------
*/

it('closes the journey for everybody who was on it at the same moment', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();

    test()->travelTo(now()->addMinutes(8));

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $attendance = Attendance::sole();

    expect($attendance->status)->toBe(AttendanceStatus::Present)
        ->and($attendance->checked_out_at)->not->toBeNull()
        ->and($attendance->checked_out_at->isAfter($attendance->checked_in_at))->toBeTrue();
});

it('leaves a no-show without a checkout, because they were never aboard', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect(Attendance::sole()->checked_out_at)->toBeNull();
});

/**
 * The booking is completed either way — a booking's status is about the booking's life
 * ending, and attendance is the record of who travelled. That is why they are two tables.
 */
it('completes the booking of somebody who did not travel', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    expect(Booking::query()->whereKey($this->bookingId)->sole()->status->value)->toBe('completed')
        ->and(Attendance::sole()->status)->toBe(AttendanceStatus::PassengerNoShow);
});

it('keeps the attendance state machine honest about what is final', function () {
    // The enum is the single source for what may follow what, and money is read off it.
    expect(AttendanceStatus::Pending->canTransitionTo(AttendanceStatus::Present))->toBeTrue()
        ->and(AttendanceStatus::Pending->canTransitionTo(AttendanceStatus::PassengerNoShow))->toBeTrue()
        // 🔒 Final once decided: a passenger charged on Monday cannot be recorded absent on
        // Friday, which is what makes the dispute window mean anything.
        ->and(AttendanceStatus::Present->isTerminal())->toBeTrue()
        ->and(AttendanceStatus::PassengerNoShow->isTerminal())->toBeTrue()
        ->and(AttendanceStatus::Present->canTransitionTo(AttendanceStatus::PassengerNoShow))->toBeFalse()
        // What Phase 8 will collect on.
        ->and(AttendanceStatus::Present->travelled())->toBeTrue()
        ->and(AttendanceStatus::Late->travelled())->toBeTrue()
        ->and(AttendanceStatus::Pending->travelled())->toBeFalse()
        ->and(AttendanceStatus::PassengerNoShow->travelled())->toBeFalse();
});
