<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Safety\Support\SafetyEventLog;
use Illuminate\Support\Facades\Storage;

/**
 * The Safety Centre (Chapter 10) — SOS, trusted contacts, reports, blocking.
 *
 * 🔴 What these really guard is that the safety path cannot be closed by any of the rules the rest
 * of the API rightly enforces. Somebody in trouble at a roadside will not finish uploading a
 * national ID first, and a 403 at that moment is the worst answer this platform could give. Several
 * tests below exist for no other reason than to prove a gate is NOT there.
 */
beforeEach(function () {
    Storage::fake('documents');

    fakeOtpSender();
    // Deliberately a bare, unverified account with no complete profile: the least privileged caller
    // the API has, and the one most of these must still serve.
    $this->token = signIn('01112223344')['session']['accessToken'];
});

/*
|--------------------------------------------------------------------------
| SOS
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The most important test in this file. No verification, no complete profile, no active trip —
 * and it still records.
 */
it('accepts an SOS from an account that has done nothing but sign in', function () {
    $sos = test()->withToken($this->token)->postJson('/api/v1/sos')
        ->assertStatus(201)->json('data');

    expect($sos['id'])->not->toBeEmpty()
        ->and($sos['countdownSeconds'])->toBe(10)
        ->and($sos['isDiscreet'])->toBeFalse()
        ->and($sos['cancelledAt'])->toBeNull()
        // Nobody has picked it up yet, and the person is told so honestly.
        ->and($sos['respondedAt'])->toBeNull();
});

/**
 * 🔴 The row exists BEFORE the countdown finishes. The countdown runs on the phone and guards
 * against an accidental tap; it does not gate the record. If the phone is taken or its battery dies
 * during those ten seconds, a design that waited for confirmation would have no trace at all.
 */
it('records the emergency immediately, not after the countdown', function () {
    test()->withToken($this->token)->postJson('/api/v1/sos')->assertStatus(201);

    // Written now, with the countdown still notionally running.
    expect(SosEvent::count())->toBe(1)
        ->and(SafetyEvent::sole()->type)->toBe(SafetyEventType::Sos);
});

it('accepts an SOS with an empty body', function () {
    // Every required field is another way for this endpoint to return 422 instead of recording an
    // emergency. There are none.
    test()->withToken($this->token)->postJson('/api/v1/sos', [])->assertStatus(201);
});

/**
 * 🔒 A silent alert is not a smaller emergency — it is the same emergency raised by somebody who
 * cannot afford to be seen raising it.
 */
it('records a discreet alert as its own type, at the same severity', function () {
    test()->withToken($this->token)->postJson('/api/v1/sos', ['isDiscreet' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.isDiscreet', true);

    $event = SafetyEvent::sole();

    expect($event->type)->toBe(SafetyEventType::DiscreetAlert)
        ->and($event->severity)->toBe(SafetySeverity::Critical);
});

it('records where they were when the phone knew', function () {
    test()->withToken($this->token)->postJson('/api/v1/sos', [
        'lat' => 30.0654,
        'lng' => 31.2314,
    ])->assertStatus(201);

    $sos = SosEvent::sole();

    expect($sos->location_at_trigger)->not->toBeNull()
        ->and(round($sos->location_at_trigger->lat, 4))->toBe(30.0654)
        ->and(SafetyEvent::sole()->metadata['hasLocation'])->toBeTrue();
});

it('still records when the phone had no position', function () {
    test()->withToken($this->token)->postJson('/api/v1/sos')->assertStatus(201);

    expect(SosEvent::sole()->location_at_trigger)->toBeNull()
        ->and(SafetyEvent::sole()->metadata['hasLocation'])->toBeFalse();
});

/**
 * 🔴 A stale session id must not turn an emergency into a 404.
 */
it('ignores a trip id it does not recognise rather than refusing', function () {
    test()->withToken($this->token)->postJson('/api/v1/sos', [
        'tripSessionId' => '01aaaaaaaaaaaaaaaaaaaaaaaa',
    ])->assertStatus(201);

    expect(SafetyEvent::sole()->trip_session_id)->toBeNull();
});

it('links the emergency to the run the person is actually on', function () {
    Storage::fake('documents');

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    approveSeat($driverToken, requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
    ])->assertStatus(201)->json('data.id'));

    underway($driverToken, $tripId);

    // She does not have to tell us which journey she is on — the answer is knowable.
    test()->withToken($paxToken)->postJson('/api/v1/sos')->assertStatus(201);

    $event = SafetyEvent::sole();

    expect($event->trip_session_id)->not->toBeNull()
        ->and($event->booking_id)->not->toBeNull();
});

/**
 * 🔒 Cancelling does not delete. A pattern of presses cancelled seconds later — same route, same
 * driver — is exactly the signal a safety team needs, and it is invisible if each one erases itself.
 */
it('keeps the record when an accidental press is cancelled', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/sos')
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->token)->postJson("/api/v1/sos/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.cancelledAt', fn ($value) => $value !== null);

    expect(SosEvent::count())->toBe(1)
        ->and(SafetyEvent::count())->toBe(1);
});

it('refuses to cancel the same emergency twice', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/sos')
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->token)->postJson("/api/v1/sos/{$id}/cancel")->assertOk();

    test()->withToken($this->token)->postJson("/api/v1/sos/{$id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SOS_ALREADY_RESOLVED');
});

/**
 * "I cancelled it" and "an operator is calling me" are different situations to be in, and the person
 * needs to know which one they are in.
 */
it('refuses to cancel once a human has picked it up', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/sos')
        ->assertStatus(201)->json('data.id');

    SosEvent::query()->whereKey($id)->update(['first_touch_at' => now()]);

    test()->withToken($this->token)->postJson("/api/v1/sos/{$id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.fields.respondedAt.0', fn ($value) => $value !== null);
});

/**
 * 🔒 404 rather than 403 — an SOS id must not be probeable for existence.
 */
it('never lets one person cancel another person emergency', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/sos')
        ->assertStatus(201)->json('data.id');

    fakeOtpSender();
    $stranger = signIn('01555556666', devicePublicId: 'other')['session']['accessToken'];

    test()->withToken($stranger)->postJson("/api/v1/sos/{$id}/cancel")->assertStatus(404);

    expect(SosEvent::sole()->cancelled_at)->toBeNull();
});

it('follows the countdown from settings rather than from code', function () {
    PlatformSetting::query()->create([
        'setting_key' => 'safety.sos_countdown_seconds',
        'setting_value' => 5,
        'value_type' => 'integer',
        'description' => 'A shorter countdown',
    ]);

    expect(test()->withToken($this->token)->postJson('/api/v1/sos')
        ->assertStatus(201)->json('data.countdownSeconds'))->toBe(5);
});

it('requires a token even for an emergency', function () {
    // The one thing that IS required: we have to know who is in trouble.
    test()->withoutToken()->postJson('/api/v1/sos')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Trusted contacts
|--------------------------------------------------------------------------
*/

it('adds a trusted contact', function () {
    $contact = test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
        'relationship' => 'أختي',
    ])->assertStatus(201)->json('data');

    expect($contact['name'])->toBe('هالة')
        // The full number back to its owner: a masked one cannot be checked for a typo, which is
        // the whole reason somebody opens this screen.
        ->and($contact['phone'])->toBe('+201098765432')
        ->and($contact['autoShareTrips'])->toBeFalse()
        /*
         * Null for everybody today — confirming a number needs an OTP to it (Phase 12). Sent as null
         * rather than omitted so the screen can say "unconfirmed" instead of implying it works.
         */
        ->and($contact['verifiedAt'])->toBeNull();
});

it('normalises the number, so the same contact cannot be added twice', function () {
    test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
    ])->assertStatus(201);

    // The same number written differently. Two rows would mean a removal that looks like it worked
    // while the second one keeps receiving.
    test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة again',
        'phone' => '+20 109 876 5432',
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'EMERGENCY_CONTACT_DUPLICATE');

    expect(EmergencyContact::count())->toBe(1);
});

/**
 * 🔒 A limit because this list is who receives somebody's live location, and an unbounded one is a
 * way to broadcast their movements to a crowd.
 */
it('caps how many people can receive somebody location', function () {
    foreach (range(1, 5) as $i) {
        test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
            'name' => "Contact {$i}",
            'phone' => '0109876543'.$i,
        ])->assertStatus(201);
    }

    test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'One too many',
        'phone' => '01098765439',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'EMERGENCY_CONTACT_LIMIT_REACHED')
        ->assertJsonPath('error.fields.limit.0', '5');
});

/**
 * 🔴 The setting that matters most: a contact with this on has a standing feed of where its owner
 * goes every morning.
 */
it('returns whether a contact sees every trip automatically', function () {
    $contact = test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
        'autoShareTrips' => true,
        'isGuardian' => true,
    ])->assertStatus(201)->json('data');

    expect($contact['autoShareTrips'])->toBeTrue()
        ->and($contact['isGuardian'])->toBeTrue();
});

/**
 * 🔒 A changed number is an unverified number. Carrying the confirmation across would mean a badge
 * saying "verified" about a number nobody ever reached — and if the change was made by somebody else
 * on an unlocked phone, that badge is what would stop anybody looking twice.
 */
it('drops the confirmation when the number changes', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
    ])->assertStatus(201)->json('data.id');

    EmergencyContact::query()->whereKey($id)->update(['verified_at' => now()]);

    test()->withToken($this->token)->patchJson("/api/v1/safety/emergency-contacts/{$id}", [
        'phone' => '01111111111',
    ])->assertOk()
        ->assertJsonPath('data.verifiedAt', null);
});

it('keeps the confirmation when only the name changes', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
    ])->assertStatus(201)->json('data.id');

    EmergencyContact::query()->whereKey($id)->update(['verified_at' => now()]);

    test()->withToken($this->token)->patchJson("/api/v1/safety/emergency-contacts/{$id}", [
        'name' => 'هالة حسن',
    ])->assertOk()
        ->assertJsonPath('data.verifiedAt', fn ($value) => $value !== null);
});

/**
 * 🔒 Immediate and unconditional. Somebody removing a contact may be doing it quickly and quietly,
 * and every extra step is a step taken while they may be watched.
 */
it('removes a contact in one call, with no confirmation step', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
    ])->assertStatus(201)->json('data.id');

    test()->withToken($this->token)->deleteJson("/api/v1/safety/emergency-contacts/{$id}")
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    expect(EmergencyContact::count())->toBe(0);
});

/**
 * 🔒 There is no endpoint anywhere that returns somebody else's contact list.
 */
it('never shows one person contacts to another', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
    ])->assertStatus(201)->json('data.id');

    fakeOtpSender();
    $stranger = signIn('01555556666', devicePublicId: 'other')['session']['accessToken'];

    expect(test()->withToken($stranger)->getJson('/api/v1/safety/emergency-contacts')
        ->assertOk()->json('data'))->toBe([]);

    test()->withToken($stranger)->patchJson("/api/v1/safety/emergency-contacts/{$id}", ['name' => 'x'])
        ->assertStatus(404);
    test()->withToken($stranger)->deleteJson("/api/v1/safety/emergency-contacts/{$id}")
        ->assertStatus(404);

    expect(EmergencyContact::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Reports
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The severity is the platform's judgement, never the reporter's. Somebody who has just been
 * harassed cannot be asked to rate their own emergency, and a client that could set it would own
 * the ordering of the safety queue.
 */
it('decides the severity from the category, not from the request', function () {
    $harassment = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'harassment',
        'description' => 'حصل معايا تصرف مش مقبول في العربية.',
        // Ignored: there is no such field.
        'severity' => 'low',
    ])->assertStatus(201)->json('data');

    expect($harassment['severity'])->toBe('critical');

    $lostItem = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'lost_item',
        'description' => 'نسيت الشنطة في العربية.',
    ])->assertStatus(201)->json('data');

    expect($lostItem['severity'])->toBe('low');
});

it('gives a harsher category a tighter deadline', function () {
    $critical = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'harassment',
    ])->assertStatus(201)->json('data.slaDueAt');

    $low = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'lost_item',
    ])->assertStatus(201)->json('data.slaDueAt');

    expect($critical)->toBeLessThan($low);
});

/**
 * 🔒 A report about somebody impersonating a Rafeeq driver has no booking, and that is exactly the
 * report the platform most needs to receive.
 */
it('accepts a report that names no booking and no person', function () {
    $incident = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'identity_mismatch',
        'description' => 'حد قال إنه سواق رفيق والعربية مش اللي في التطبيق.',
    ])->assertStatus(201)->json('data');

    expect($incident['bookingId'])->toBeNull()
        ->and($incident['severity'])->toBe('critical')
        ->and($incident['status'])->toBe('OPEN');
});

it('accepts a report with a category and nothing else', function () {
    // Somebody shaken may not want to type, and refusing them would lose the report entirely.
    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201);
});

/**
 * 🔒 A report that accepted any booking id would let somebody attach a fabricated complaint to a
 * journey between two strangers.
 */
it('refuses a report attached to somebody else journey', function () {
    Storage::fake('documents');

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
    ])->assertStatus(201)->json('data.id'));

    test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'harassment',
        'bookingId' => $bookingId,
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'INCIDENT_NOT_REPORTABLE')
        ->assertJsonPath('error.fields.bookingId.0', 'NOT_YOURS');

    expect(Incident::count())->toBe(0);
});

/**
 * 🔒 The record goes into the table nobody may delete. A report can be resolved, closed or found
 * baseless — and the fact that it was made still happened.
 */
it('writes the report into the record that is never deleted', function () {
    test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'unsafe_driving',
        'description' => 'كانت بتسوق بسرعة جدًا على الدائري.',
    ])->assertStatus(201);

    $event = SafetyEvent::sole();

    expect($event->type)->toBe(SafetyEventType::IncidentCreated)
        ->and($event->severity)->toBe(SafetySeverity::High)
        // The description itself is NOT copied in: see SafetyEventLog on what belongs in a table
        // that can never be deleted.
        ->and($event->metadata)->not->toHaveKey('description')
        ->and($event->metadata['hasDescription'])->toBeTrue();
});

it('lists the caller own reports and hides everybody else', function () {
    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201);

    fakeOtpSender();
    $stranger = signIn('01555556666', devicePublicId: 'other')['session']['accessToken'];

    expect(test()->withToken($stranger)->getJson('/api/v1/incidents')->assertOk()->json('data'))
        ->toBe([]);
});

/**
 * 🔒 404 for the person it was about, too. Showing the subject what was said about them, in the
 * reporter's own words, is how a report becomes a reason for a confrontation.
 */
it('never shows a report to the person it is about', function () {
    Storage::fake('documents');

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
    ])->assertStatus(201)->json('data.id'));

    $incidentId = test()->withToken($paxToken)->postJson('/api/v1/incidents', [
        'category' => 'harassment',
        'bookingId' => $bookingId,
        'description' => 'تصرف مش مقبول.',
    ])->assertStatus(201)->json('data.id');

    // The driver it names cannot read it.
    test()->withToken($driverToken)->getJson("/api/v1/incidents/{$incidentId}")->assertStatus(404);

    // And the reporter can.
    test()->withToken($paxToken)->getJson("/api/v1/incidents/{$incidentId}")->assertOk();
});

/**
 * 🔒 Neither the reviewer nor the reported person's id is echoed back — the first because staff
 * handling a harassment case are not introduced to either party, the second because it would turn a
 * report into a way to resolve somebody's identifier.
 */
it('does not name the reviewer or the reported person', function () {
    $incident = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'harassment',
    ])->assertStatus(201)->json('data');

    expect($incident)->not->toHaveKey('assignedAdminId')
        ->and($incident)->not->toHaveKey('reportedUserId')
        ->and($incident)->not->toHaveKey('reporterUserId');
});

it('rate-limits reports, generously', function () {
    PlatformSetting::query()->create([
        'setting_key' => 'safety.reports_per_hour',
        'setting_value' => 2,
        'value_type' => 'integer',
        'description' => 'Tight, for the test',
    ]);

    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201);
    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201);

    // And the refusal says when to come back, like every other 429 in the API.
    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

/*
|--------------------------------------------------------------------------
| Blocking
|--------------------------------------------------------------------------
*/

it('blocks somebody and lists them back', function () {
    fakeOtpSender();
    $other = signIn('01555556666', devicePublicId: 'other');
    $otherId = $other['user']['id'];

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', [
        'userId' => $otherId,
        'reason' => 'مش مرتاحة',
    ])->assertStatus(201);

    $list = test()->withToken($this->token)->getJson('/api/v1/safety/blocked-users')
        ->assertOk()->json('data');

    expect($list)->toHaveCount(1)
        ->and($list[0]['userId'])->toBe($otherId)
        // Recognisable without naming anybody fully.
        ->and($list[0]['person'])->toHaveKey('publicFirstName')
        ->and($list[0]['person'])->not->toHaveKey('fullName');
});

/**
 * 🔴 Pitfall #27, and the reason it matters is uncomfortable: if only the blocker's direction were
 * checked, somebody who blocks a person they are afraid of would still appear in THAT person's
 * search results. The protection would run the wrong way. The filter has been bidirectional since
 * Phase 6; this proves the row written here feeds it.
 */
it('stops the two being matched in both directions', function () {
    Storage::fake('documents');

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    expect(search($paxToken)->assertOk()->json('data'))->not->toBeEmpty();

    // The DRIVER blocks the passenger — the opposite direction from the one being searched.
    $driverUserId = test()->withToken($driverToken)->getJson('/api/v1/auth/me')->json('data.user.id');
    $paxUserId = test()->withToken($paxToken)->getJson('/api/v1/auth/me')->json('data.user.id');

    test()->withToken($driverToken)->postJson('/api/v1/safety/blocked-users', [
        'userId' => $paxUserId,
    ])->assertStatus(201);

    expect(search($paxToken)->assertOk()->json('data'))->toBe([]);

    // And back the other way.
    test()->withToken($driverToken)->deleteJson("/api/v1/safety/blocked-users/{$paxUserId}")->assertOk();
    test()->withToken($paxToken)->postJson('/api/v1/safety/blocked-users', [
        'userId' => $driverUserId,
    ])->assertStatus(201);

    expect(search($paxToken)->assertOk()->json('data'))->toBe([]);
});

it('refuses to block yourself', function () {
    $me = test()->withToken($this->token)->getJson('/api/v1/auth/me')->json('data.user.id');

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', ['userId' => $me])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'CANNOT_BLOCK_SELF');
});

it('refuses to block the same person twice', function () {
    fakeOtpSender();
    $otherId = signIn('01555556666', devicePublicId: 'other')['user']['id'];

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', ['userId' => $otherId])
        ->assertStatus(201);

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', ['userId' => $otherId])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_BLOCKED');

    expect(BlockedUser::count())->toBe(1);
});

/**
 * 🔒 The one thing a block must never do is tell the blocked person about it. The list is
 * one-directional by design: who I blocked, never who blocked me.
 */
it('never tells the blocked person, and never lists who blocked you', function () {
    fakeOtpSender();
    $other = signIn('01555556666', devicePublicId: 'other');

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', [
        'userId' => $other['user']['id'],
        'reason' => 'a private reason',
    ])->assertStatus(201);

    // Their own list is empty — they blocked nobody, and nothing tells them they were blocked.
    expect(test()->withToken($other['session']['accessToken'])
        ->getJson('/api/v1/safety/blocked-users')->assertOk()->json('data'))->toBe([]);
});

it('unblocks, and the match comes back', function () {
    fakeOtpSender();
    $otherId = signIn('01555556666', devicePublicId: 'other')['user']['id'];

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', ['userId' => $otherId])
        ->assertStatus(201);

    test()->withToken($this->token)->deleteJson("/api/v1/safety/blocked-users/{$otherId}")
        ->assertOk()
        ->assertJsonPath('data.unblocked', true);

    expect(BlockedUser::count())->toBe(0);
});

/**
 * 🔒 A block or an unblock writes NO safety event. `safety_events` is read by operators, and a row
 * saying "she blocked him" in a table staff browse turns a private protective act into something
 * discussable.
 */
it('leaves no operator-visible trace of a block', function () {
    fakeOtpSender();
    $otherId = signIn('01555556666', devicePublicId: 'other')['user']['id'];

    test()->withToken($this->token)->postJson('/api/v1/safety/blocked-users', ['userId' => $otherId])
        ->assertStatus(201);

    expect(SafetyEvent::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What the record never contains
|--------------------------------------------------------------------------
*/

/**
 * 🔒 `safety_events` can never be deleted, so anything sensitive put in it is there for ever. The
 * guard is code rather than convention because the pressure to add "just one more useful field" to a
 * JSON column only ever goes one way.
 */
it('strips sensitive keys from a record it can never delete', function () {
    SafetyEventLog::record(
        type: SafetyEventType::Sos,
        user: User::sole(),
        severity: SafetySeverity::Critical,
        metadata: [
            'phone' => '+201098765432',
            'push_token' => 'abc',
            'national_id' => '29001010101010',
            'harmless' => 'kept',
        ],
    );

    $metadata = SafetyEvent::sole()->metadata;

    expect($metadata['phone'])->toBe('[removed]')
        ->and($metadata['push_token'])->toBe('[removed]')
        ->and($metadata['national_id'])->toBe('[removed]')
        // A marker rather than a missing key, so a reader can see something was removed instead of
        // wondering whether it was ever sent.
        ->and($metadata['harmless'])->toBe('kept');
});

it('never deletes a safety event', function () {
    test()->withToken($this->token)->postJson('/api/v1/sos')->assertStatus(201);

    // The model refuses it outright — this is the table the whole chapter rests on.
    expect(fn () => SafetyEvent::sole()->delete())->toThrow(Exception::class);

    expect(SafetyEvent::count())->toBe(1);
});
