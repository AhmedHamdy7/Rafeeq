<?php

use App\Domains\Admin\Actions\HandleIncidentAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\SafetyCaseQueue;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentAppeal;
use App\Domains\Safety\Models\SafetyEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Report a problem / My reports, as the Trips history uses them (the HISTORY group, 2026-11-01).
 *
 * The app's report form offers six reasons — Safety concern, Unsafe driving, Harassment, False
 * arrival, No-show, Other — and the past trip shows "My reports" for that one journey. These pin
 * what the server does with each of those, so the form's wording and the queue's ordering cannot
 * drift apart.
 */
beforeEach(function () {
    Storage::fake('documents');

    fakeOtpSender();
    $this->token = signIn('01112223344')['session']['accessToken'];
});

/**
 * 🔴 HISTORY-9. "Safety concern" was sent as `other`, which the queue ranks medium — below what a
 * passenger means by "safety". It has its own category now, read the same morning.
 */
it('ranks a safety concern high, with a four-hour deadline', function () {
    $incident = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'safety_concern',
        'description' => 'حسيت إني مش في أمان في الرحلة.',
    ])->assertStatus(201)->json('data');

    expect($incident['category'])->toBe('safety_concern')
        ->and($incident['severity'])->toBe('high')
        ->and(SafetyEvent::sole()->severity)->toBe(SafetySeverity::High);

    $hours = now()->diffInMinutes(Carbon::parse($incident['slaDueAt'])) / 60;

    expect(round($hours))->toBe(4.0);
});

it('ranks a safety concern above other', function () {
    $other = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201)->json('data');

    $concern = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'safety_concern'])
        ->assertStatus(201)->json('data');

    expect($other['severity'])->toBe('medium')
        ->and($concern['slaDueAt'] < $other['slaDueAt'])->toBeTrue();
});

/**
 * HISTORY-9's second half: `false_arrival` (X-7) is live, not planned. Medium — it is a dispute
 * about a fee (TRIP-17), and what it does to her no-show is pinned in IncidentMoneyTest.
 */
it('accepts a false arrival report today', function () {
    test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'false_arrival'])
        ->assertStatus(201)
        ->assertJsonPath('data.category', 'false_arrival')
        ->assertJsonPath('data.severity', 'medium');
});

/*
|--------------------------------------------------------------------------
| HISTORY-8 — "My reports" for one journey
|--------------------------------------------------------------------------
*/

/**
 * A passenger with a confirmed seat, and the driver who approved it.
 *
 * @return array{driver: string, pax: string, bookingId: string, tripId: string}
 */
function reportableJourney(): array
{
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $tripId,
    ])->assertStatus(201)->json('data.id'));

    return ['driver' => $driverToken, 'pax' => $paxToken, 'bookingId' => $bookingId, 'tripId' => $tripId];
}

it('lists only her reports about that journey', function () {
    $journey = reportableJourney();

    $onIt = test()->flushHeaders()->withToken($journey['pax'])->postJson('/api/v1/incidents', [
        'category' => 'unsafe_driving',
        'bookingId' => $journey['bookingId'],
    ])->assertStatus(201)->json('data.id');

    // Another of hers, about no journey at all.
    test()->flushHeaders()->withToken($journey['pax'])->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201);

    test()->flushHeaders()->withToken($journey['pax'])
        ->getJson("/api/v1/incidents?bookingId={$journey['bookingId']}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $onIt)
        ->assertJsonPath('data.0.bookingId', $journey['bookingId']);

    // Without the filter, both.
    test()->flushHeaders()->withToken($journey['pax'])->getJson('/api/v1/incidents')
        ->assertOk()->assertJsonCount(2, 'data');
});

/**
 * 🔒 The filter narrows the own-reports rule; it never widens it. The driver asking about the same
 * booking sees nothing — not even that a report exists.
 */
it('never shows the other party a report through the filter', function () {
    $journey = reportableJourney();

    test()->flushHeaders()->withToken($journey['pax'])->postJson('/api/v1/incidents', [
        'category' => 'harassment',
        'bookingId' => $journey['bookingId'],
    ])->assertStatus(201);

    test()->flushHeaders()->withToken($journey['driver'])
        ->getJson("/api/v1/incidents?bookingId={$journey['bookingId']}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses a booking id that is not an id', function () {
    test()->withToken($this->token)->getJson('/api/v1/incidents?bookingId=nope')->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| HISTORY-11 — id, createdAt, order, and the late report's code
|--------------------------------------------------------------------------
*/

it('returns an id and createdAt on the report and in the list, newest first', function () {
    $first = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201)->json('data');

    $this->travel(1)->minutes();

    $second = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'lost_item'])
        ->assertStatus(201)->json('data');

    expect($first)->toHaveKeys(['id', 'createdAt'])
        ->and($first['id'])->toHaveLength(26);

    $list = test()->withToken($this->token)->getJson('/api/v1/incidents')->assertOk()->json('data');

    expect(array_column($list, 'id'))->toBe([$second['id'], $first['id']])
        ->and($list[0]['createdAt'])->toBe($second['createdAt']);
});

it('counts the files attached on the list, not only on one report', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'harassment'])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->token)->postJson("/api/v1/incidents/{$id}/evidence", [
        'kind' => 'photo',
        'file' => UploadedFile::fake()->image('scene.jpg', 1600, 1200),
    ])->assertStatus(201);

    test()->withToken($this->token)->getJson('/api/v1/incidents')->assertOk()
        ->assertJsonPath('data.0.evidenceCount', 1);
});

it('refuses a report more than 24 hours after the journey with 409 REPORT_WINDOW_CLOSED', function () {
    $journey = reportableJourney();

    // A morning two days ago that never ran: the window is measured from its departure.
    runLeavingIn($journey['tripId'], -60 * 48, dayOffset: -3);

    test()->flushHeaders()->withToken($journey['pax'])->postJson('/api/v1/incidents', [
        'category' => 'unsafe_driving',
        'bookingId' => $journey['bookingId'],
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'REPORT_WINDOW_CLOSED')
        ->assertJsonPath('error.fields.bookingId.0', 'WINDOW_CLOSED');
});

/*
|--------------------------------------------------------------------------
| HISTORY-10 — appealing the decision
|--------------------------------------------------------------------------
*/

/**
 * Files a report and has a member of staff decide it, the way the dashboard does.
 */
function decidedReport(string $token, string $how = 'resolve'): string
{
    $id = test()->flushHeaders()->withToken($token)->postJson('/api/v1/incidents', [
        'category' => 'unsafe_driving',
        'description' => 'كان بيسوق بسرعة على الدائري.',
    ])->assertStatus(201)->json('data.id');

    staffDecide($id, $how);

    return $id;
}

function staffDecide(string $incidentId, string $how = 'resolve'): void
{
    $admin = AdminUser::factory()->create();
    $incident = Incident::query()->findOrFail($incidentId);

    app(HandleIncidentAction::class)->take($incident, $admin);
    app(HandleIncidentAction::class)->{$how}($incident->refresh(), $admin, 'Spoke to the driver; warning issued.');
}

function appealReport(string $token, string $incidentId, string $reason = 'The warning is not enough, he did it again the next day.')
{
    return test()->flushHeaders()->withToken($token)->postJson("/api/v1/incidents/{$incidentId}/appeal", ['reason' => $reason]);
}

it('offers an appeal on a decided report, until 48 hours after the decision', function () {
    $id = decidedReport($this->token);

    $report = test()->withToken($this->token)->getJson("/api/v1/incidents/{$id}")->assertOk()->json('data');

    $expected = Incident::query()->findOrFail($id)->resolved_at->copy()->addHours(48);

    expect($report['status'])->toBe('RESOLVED')
        ->and($report['appeal']['count'])->toBe(0)
        ->and($report['appeal']['needsNewEvidence'])->toBeFalse()
        ->and(Carbon::parse($report['appeal']['appealableUntil'])->toIso8601String())->toBe($expected->toIso8601String());
});

it('reopens the report as APPEALED, unassigned, back in the queue', function () {
    $id = decidedReport($this->token, 'close');

    $report = appealReport($this->token, $id)->assertStatus(201)->json('data');

    $incident = Incident::query()->findOrFail($id);

    expect($report['status'])->toBe('APPEALED')
        ->and($report['appeal']['count'])->toBe(1)
        // Being looked at again, so there is nothing to appeal until it is decided again.
        ->and($report['appeal']['appealableUntil'])->toBeNull()
        ->and($incident->assigned_admin_id)->toBeNull()
        ->and($incident->sla_due_at->isFuture())->toBeTrue()
        ->and(IncidentAppeal::sole()->round)->toBe(1)
        ->and(SafetyEvent::query()->where('type', 'incident_appealed')->count())->toBe(1)
        ->and(SafetyCaseQueue::openReportCount())->toBe(1);
});

it('lists the appealed state in My reports', function () {
    $id = decidedReport($this->token);
    appealReport($this->token, $id)->assertStatus(201);

    test()->withToken($this->token)->getJson('/api/v1/incidents')->assertOk()
        ->assertJsonPath('data.0.status', 'APPEALED')
        ->assertJsonPath('data.0.appeal.count', 1);
});

it('refuses to appeal a report nobody has decided yet', function () {
    $id = test()->withToken($this->token)->postJson('/api/v1/incidents', ['category' => 'other'])
        ->assertStatus(201)->json('data.id');

    appealReport($this->token, $id)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.fields.appeal.0', 'NOT_DECIDED');
});

it('refuses an appeal once 48 hours have passed since the decision', function () {
    $id = decidedReport($this->token);

    // Backdated rather than travelled to: two days on, the access token has expired too.
    Incident::query()->whereKey($id)->update(['resolved_at' => now()->subHours(49)]);

    appealReport($this->token, $id)
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');

    expect(test()->withToken($this->token)->getJson("/api/v1/incidents/{$id}")->json('data.appeal.appealableUntil'))
        ->toBeNull();
});

/**
 * 🔴 D-0021: one appeal, then a second only with new evidence, then final. "New" is measured by
 * the server — more files than when the first appeal was made — not claimed by the reporter.
 */
it('takes a second appeal only with new evidence, and none after that', function () {
    $id = decidedReport($this->token);
    appealReport($this->token, $id)->assertStatus(201);

    // Staff look again and decide again: a fresh 48 hours from this decision.
    staffDecide($id);

    $report = test()->withToken($this->token)->getJson("/api/v1/incidents/{$id}")->json('data');

    expect($report['appeal']['needsNewEvidence'])->toBeTrue()
        ->and($report['appeal']['appealableUntil'])->not->toBeNull();

    appealReport($this->token, $id)
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'NEW_EVIDENCE_REQUIRED');

    // A decided report still takes a file while it can be appealed — that is how the rule is met.
    test()->withToken($this->token)->postJson("/api/v1/incidents/{$id}/evidence", [
        'kind' => 'photo',
        'file' => UploadedFile::fake()->image('dashcam.jpg', 1600, 1200),
    ])->assertStatus(201);

    appealReport($this->token, $id)->assertStatus(201)->assertJsonPath('data.appeal.count', 2);

    staffDecide($id, 'close');

    appealReport($this->token, $id)
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'NO_APPEALS_LEFT');

    // And the case file is closed for good: no more evidence either.
    test()->withToken($this->token)->postJson("/api/v1/incidents/{$id}/evidence", [
        'kind' => 'photo',
        'file' => UploadedFile::fake()->image('late.jpg', 1600, 1200),
    ])->assertStatus(409)->assertJsonPath('error.code', 'INCIDENT_CLOSED');
});

it('answers 404 to an appeal on somebody else’s report', function () {
    $id = decidedReport($this->token);

    $stranger = signIn('01555556666', devicePublicId: 'other')['session']['accessToken'];

    appealReport($stranger, $id)->assertStatus(404);
});

it('asks for a reason', function () {
    $id = decidedReport($this->token);

    appealReport($this->token, $id, 'no')->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['reason']]]);
});
