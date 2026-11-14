<?php

use App\Domains\Admin\Actions\ResolveFeeAppealAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserConsent;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Rating\Enums\RatingKind;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Actions\AppealReliabilityEventAction;
use App\Domains\Reliability\Enums\AppealDeclineReason;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->vehicleId = Vehicle::sole()->id;
    $this->commuteId = readyCommute($this->driverToken, $this->vehicleId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    $this->paxToken = verifiedPassenger('01112223344');

    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

function driverEvents(string $token): array
{
    return test()->flushHeaders()->withToken($token)
        ->getJson('/api/v1/account/reliability')->assertOk()->json('data.asDriver.events');
}

function pausedWithFee(): ReliabilityEvent
{
    repriceBooking(test()->bookingId, 10000);
    runLeavingIn(test()->tripId, 120);

    test()->flushHeaders()->withToken(test()->driverToken)
        ->postJson('/api/v1/commutes/'.test()->commuteId.'/pause')->assertOk();

    return ReliabilityEvent::query()->where('user_id', test()->driver->id)->sole();
}

function appealAs(string $token, string $eventId, string $reason = 'The car broke down on the ring road.')
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/account/reliability/events/{$eventId}/appeal", ['reason' => $reason]);
}

function attachTo(string $token, string $eventId)
{
    return test()->flushHeaders()->withToken($token)
        ->post("/api/v1/account/reliability/events/{$eventId}/evidence", [
            'kind' => 'photo',
            'file' => UploadedFile::fake()->image('receipt.jpg', 800, 600),
        ], ['Accept' => 'application/json']);
}

it('describes a pause as one act, with its cause, the passenger and the fee', function () {
    $event = pausedWithFee();

    $row = driverEvents($this->driverToken)[0];

    expect($row['id'])->toBe($event->id)
        ->and($row['cause'])->toBe('pause')
        ->and($row['groupId'])->not->toBeNull()
        ->and($row['person']['publicFirstName'])->not->toBeNull()
        ->and($row['fee']['amountPiastres'])->toBeGreaterThan(0)
        ->and($row['fee']['reversed'])->toBeFalse()
        ->and($row['canAppeal'])->toBeTrue()
        ->and($row['appealsLeft'])->toBe(2)
        ->and($row['appealBy'])->not->toBeNull()
        ->and($row['appeal'])->toBeNull();
});

it('reverses the fee together with the event when the appeal is approved', function () {
    $event = pausedWithFee();
    $owed = DebtLedger::balanceOf($this->driver, DebtSide::Driver);

    appealAs($this->driverToken, $event->id)->assertOk()->assertJsonPath('data.appeal.status', 'pending');

    app(AppealReliabilityEventAction::class)->approve(adminWithRole(AdminRole::SafetyLead), $event->refresh(), 'Tow receipt checked.');

    $row = driverEvents($this->driverToken)[0];

    expect($row['counts'])->toBeFalse()
        ->and($row['appeal']['status'])->toBe('approved')
        ->and($row['fee']['reversed'])->toBeTrue()
        ->and($row['reversedReason'])->toBeNull()
        ->and(DebtLedger::balanceOf($this->driver, DebtSide::Driver))->toBe($owed - $row['fee']['amountPiastres']);
});

it('reverses the event when the fee appeal is overturned', function () {
    $event = pausedWithFee();
    $entry = DebtEntry::query()->where('idempotency_key', "driver_cancel:{$this->bookingId}")->sole();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/account/debt/entries/{$entry->id}/appeal", ['reason' => 'The car broke down on the ring road.'])
        ->assertSuccessful();

    app(ResolveFeeAppealAction::class)->driverFee(FeeAppeal::sole(), adminWithRole(AdminRole::SuperAdmin), true, 'Checked.');

    expect($event->refresh()->reversed_at)->not->toBeNull();
});

it('takes a second appeal only with new evidence, then none', function () {
    $event = pausedWithFee();
    $reviewer = adminWithRole(AdminRole::SafetyLead);

    appealAs($this->driverToken, $event->id)->assertOk();
    appealAs($this->driverToken, $event->id)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'ALREADY_APPEALED');

    app(AppealReliabilityEventAction::class)->decline($reviewer, $event->refresh(), 'Internal note naming a passenger.', AppealDeclineReason::NotEnoughEvidence);

    $row = driverEvents($this->driverToken)[0];

    expect($row['appeal']['status'])->toBe('declined')
        ->and($row['appeal']['declineReasonCode'])->toBe('not_enough_evidence')
        ->and($row['appeal']['decidedAt'])->not->toBeNull()
        ->and($row['reversedReason'])->toBeNull()
        ->and($row['appealsLeft'])->toBe(1)
        ->and($row['needsNewEvidence'])->toBeTrue()
        ->and($row['canAppeal'])->toBeFalse();

    appealAs($this->driverToken, $event->id)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'NEW_EVIDENCE_REQUIRED');

    attachTo($this->driverToken, $event->id)->assertStatus(201)->assertJsonPath('data.kind', 'photo');

    $row = driverEvents($this->driverToken)[0];

    expect($row['canAppeal'])->toBeTrue()->and($row['evidence'])->toHaveCount(1);

    appealAs($this->driverToken, $event->id)->assertOk()->assertJsonPath('data.appeal.round', 2);

    app(AppealReliabilityEventAction::class)->decline($reviewer, $event->refresh(), 'Still not enough.');

    attachTo($this->driverToken, $event->id)->assertStatus(422);
    appealAs($this->driverToken, $event->id)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'NO_APPEALS_LEFT');
});

it('closes the appeal window after 48 hours', function () {
    $event = pausedWithFee();

    ReliabilityEvent::query()->whereKey($event->id)->update(['created_at' => now()->subHours(49)]);

    appealAs($this->driverToken, $event->id)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');
});

it('hides evidence upload on somebody else’s event', function () {
    $event = pausedWithFee();

    attachTo($this->paxToken, $event->id)->assertStatus(404);
});

it('splits what was said about her by kind and remembers what she reported', function () {
    $pax = User::query()->where('phone_e164', '+201112223344')->sole();

    $ride = Rating::factory()->visible()->create([
        'booking_id' => $this->bookingId, 'reviewer_user_id' => $pax->id, 'reviewed_user_id' => $this->driver->id,
        'stars' => 4, 'comment' => 'Fine.', 'kind' => RatingKind::CompletedRide,
    ]);

    $otherBooking = approveSeat($this->driverToken, requestSeat(verifiedPassenger('01299887766', device: 'pax-2'), $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    $feedback = Rating::factory()->visible()->create([
        'booking_id' => $otherBooking, 'reviewer_user_id' => User::query()->where('phone_e164', '+201299887766')->sole()->id,
        'reviewed_user_id' => $this->driver->id, 'stars' => 2, 'comment' => null, 'kind' => RatingKind::CanceledBooking,
    ]);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/ratings/{$ride->id}/report")->assertStatus(201);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/ratings/{$feedback->id}/report")
        ->assertStatus(422)->assertJsonPath('error.fields.rating.0', 'NOT_REPORTABLE');

    $all = test()->flushHeaders()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')->assertOk();

    expect($all->json('meta.byKind.completed_ride'))->toBe(['count' => 1, 'average' => 4])
        ->and($all->json('meta.byKind.canceled_booking.count'))->toBe(1)
        ->and($all->json('meta.byKind.canceled_booking.average'))->toEqual(2);

    $only = test()->flushHeaders()->withToken($this->driverToken)
        ->getJson('/api/v1/ratings/about-me?kind=canceled_booking')->assertOk()->json('data');

    expect($only)->toHaveCount(1)
        ->and($only[0]['kind'])->toBe('canceled_booking')
        ->and($only[0]['reportedByMe'])->toBeFalse();

    $rides = test()->flushHeaders()->withToken($this->driverToken)
        ->getJson('/api/v1/ratings/about-me?kind=completed_ride')->assertOk()->json('data');

    expect($rides[0]['reportedByMe'])->toBeTrue();
});

it('lets an approved driver finish a replacement car and previews what activating it cancels', function () {
    $created = test()->flushHeaders()->withToken($this->driverToken)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2021, 'colour' => 'white',
        'plateNumber' => 'ق ن ر 7788', 'seats' => 5, 'hasAc' => true,
    ])->assertStatus(201);

    $newId = $created->json('data.id');

    expect($created->json('data.declaredAc'))->toBeTrue()
        ->and($created->json('data.hasAc'))->toBeFalse()
        ->and($created->json('data.submittedAt'))->not->toBeNull();

    test()->withToken($this->driverToken)->patchJson("/api/v1/driver/vehicles/{$newId}", ['colour' => 'grey'])
        ->assertOk()->assertJsonPath('data.colour', 'grey');

    test()->withToken($this->driverToken)->post("/api/v1/driver/vehicles/{$newId}/documents", [
        'type' => 'registration_front',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ], ['Accept' => 'application/json'])->assertStatus(201);

    test()->withToken($this->driverToken)->patchJson("/api/v1/driver/vehicles/{$this->vehicleId}", ['colour' => 'grey'])
        ->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_LOCKED');

    Vehicle::findOrFail($newId)->forceFill([
        'verification_status' => VehicleVerificationStatus::Approved->value,
        'approved_seats' => 3,
    ])->save();

    test()->withToken($this->driverToken)->patchJson("/api/v1/driver/vehicles/{$newId}", ['colour' => 'black'])
        ->assertStatus(409);

    repriceBooking($this->bookingId, 10000);
    runLeavingIn($this->tripId, 120);

    $preview = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/vehicles/replace-preview?vehicle={$newId}")->assertOk()->json('data');

    expect($preview['affectedBookings'])->toBe(1)
        ->and($preview['affectedCommutes'])->toBe(1)
        ->and($preview['affectedSeats'])->toBe(1)
        ->and($preview['feePiastres'])->toBeGreaterThan(0)
        ->and($preview['perTrip'])->toHaveCount(1);
});

it('records biometric consent when asked for it', function () {
    test()->flushHeaders()->withToken($this->paxToken)->getJson('/api/v1/account/consents')
        ->assertOk()->assertJsonPath('data.biometric.acceptedAt', null);

    test()->withToken($this->paxToken)->postJson('/api/v1/account/consents', ['type' => 'biometric'])
        ->assertOk()->assertJsonPath('data.biometric.acceptedAt', fn ($at) => $at !== null);

    expect(UserConsent::query()->where('document_type', 'biometric')->count())->toBe(1);

    test()->withToken($this->paxToken)->postJson('/api/v1/account/consents', ['type' => 'nonsense'])->assertStatus(422);
});
