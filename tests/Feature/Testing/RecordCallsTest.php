<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Enums\RatingKind;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();
});

function cancelledByDriver(): array
{
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $paxToken = verifiedPassenger('01112223344');
    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->json('data.id'));

    runLeavingIn($tripId, 5 * 60);

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/bookings/{$bookingId}/cancel", ['reason' => 'Changed my plans.'])
        ->assertOk();

    return [$driverToken, ReliabilityEvent::query()->sole()->id, $bookingId, $commuteId];
}

function fileAppeal(string $token, string $eventId): string
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/account/reliability/events/{$eventId}/appeal", ['reason' => 'The passenger asked me to cancel.'])
        ->assertOk()
        ->json('data.appeal.id');
}

function driverOwes(): int
{
    return DebtLedger::balanceOf(User::query()->where('phone_e164', '+201012345678')->sole(), DebtSide::Driver);
}

it('answers 404 for the new calls while testing is off', function () {
    config(['rafeeq.testing.api' => false]);
    $token = verifiedPassenger('01112223344');

    foreach ([
        ['post', '/api/v1/testing/reliability/appeals/x/decide'],
        ['post', '/api/v1/testing/reliability/reset'],
        ['post', '/api/v1/testing/reliability/events/x/age'],
        ['post', '/api/v1/testing/reliability/events'],
        ['post', '/api/v1/testing/ratings/reports/x/decide'],
        ['delete', '/api/v1/testing/driver/vehicles/x'],
    ] as [$method, $url]) {
        test()->withToken($token)->json($method, $url, ['decision' => 'approved', 'hours' => 1, 'kind' => 'driver_late'])->assertStatus(404);
    }
});

it('approves an appeal: the event stops counting, the fee comes back and the score recovers', function () {
    [$token, $eventId] = cancelledByDriver();
    $owed = driverOwes();
    expect($owed)->toBeGreaterThan(0);

    $appealId = fileAppeal($token, $eventId);

    test()->withToken($token)->postJson("/api/v1/testing/reliability/appeals/{$appealId}/decide", ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.id', $eventId)
        ->assertJsonPath('data.counts', false)
        ->assertJsonPath('data.appeal.status', 'approved')
        ->assertJsonPath('data.fee.reversed', true)
        ->assertJsonPath('data.reversedAt', fn ($v) => $v !== null);

    expect(driverOwes())->toBe(0);
    test()->withToken($token)->getJson('/api/v1/account/reliability')->assertJsonPath('data.asDriver.score', 100);

    test()->withToken($token)->postJson("/api/v1/testing/reliability/appeals/{$appealId}/decide", ['decision' => 'declined'])
        ->assertStatus(409)->assertJsonPath('error.code', 'APPEAL_NOT_PENDING');
});

it('declines an appeal with a reason and opens the second round, which needs new evidence', function () {
    [$token, $eventId] = cancelledByDriver();
    $appealId = fileAppeal($token, $eventId);

    test()->withToken($token)->postJson("/api/v1/testing/reliability/appeals/{$appealId}/decide", [
        'decision' => 'declined',
        'declineReasonCode' => 'evidence_does_not_match',
    ])
        ->assertOk()
        ->assertJsonPath('data.counts', true)
        ->assertJsonPath('data.appeal.status', 'declined')
        ->assertJsonPath('data.appeal.declineReasonCode', 'evidence_does_not_match')
        ->assertJsonPath('data.appealsLeft', 1)
        ->assertJsonPath('data.needsNewEvidence', true)
        ->assertJsonPath('data.appealRefusal', 'NEW_EVIDENCE_REQUIRED');

    test()->withToken($token)->postJson("/api/v1/testing/reliability/appeals/{$appealId}/decide", ['decision' => 'declined', 'declineReasonCode' => 'nope'])
        ->assertStatus(422);
});

it('refuses somebody else\'s appeal', function () {
    [$token, $eventId] = cancelledByDriver();
    $appealId = fileAppeal($token, $eventId);

    test()->flushHeaders()->withToken(verifiedPassenger('01112229999', device: 'other'))
        ->postJson("/api/v1/testing/reliability/appeals/{$appealId}/decide", ['decision' => 'approved'])
        ->assertStatus(404);
});

it('ages an event past the 48-hour window, and past the 90-day score', function () {
    [$token, $eventId] = cancelledByDriver();

    test()->withToken($token)->postJson("/api/v1/testing/reliability/events/{$eventId}/age", ['hours' => 49])
        ->assertOk()
        ->assertJsonPath('data.canAppeal', false)
        ->assertJsonPath('data.appealRefusal', 'APPEAL_WINDOW_CLOSED')
        ->assertJsonPath('data.appealBy', null);

    test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/account/reliability/events/{$eventId}/appeal", ['reason' => 'Too late.'])
        ->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');

    test()->withToken($token)->postJson("/api/v1/testing/reliability/events/{$eventId}/age", ['hours' => 2400])->assertOk();

    test()->withToken($token)->getJson('/api/v1/account/reliability')
        ->assertJsonPath('data.asDriver.score', 100)
        ->assertJsonCount(1, 'data.asDriver.events');
});

it('reopens the second-round window from the decline when an event is aged', function () {
    [$token, $eventId] = cancelledByDriver();
    $appealId = fileAppeal($token, $eventId);
    test()->withToken($token)->postJson("/api/v1/testing/reliability/appeals/{$appealId}/decide", ['decision' => 'declined'])->assertOk();

    test()->withToken($token)->postJson("/api/v1/testing/reliability/events/{$eventId}/age", ['hours' => 49])
        ->assertOk()
        ->assertJsonPath('data.appealRefusal', 'APPEAL_WINDOW_CLOSED');
});

it('resets the caller\'s reliability record and takes its fees off', function () {
    [$token, $eventId] = cancelledByDriver();

    test()->flushHeaders()->withToken($token)
        ->post("/api/v1/account/reliability/events/{$eventId}/evidence", [
            'kind' => 'photo',
            'file' => UploadedFile::fake()->image('receipt.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertStatus(201);
    fileAppeal($token, $eventId);

    test()->withToken($token)->postJson('/api/v1/testing/reliability/reset')
        ->assertOk()
        ->assertJsonPath('data.asDriver.score', 100)
        ->assertJsonPath('data.asDriver.events', [])
        ->assertJsonPath('data.asPassenger.events', []);

    expect(ReliabilityEvent::query()->count())->toBe(0)
        ->and(driverOwes())->toBe(0);

    test()->withToken($token)->postJson('/api/v1/testing/reliability/reset')->assertOk();
    expect(driverOwes())->toBe(0);
});

it('writes events of any kind, grouped, with no cause, and with a fee on a booking', function () {
    [$token, , $bookingId] = cancelledByDriver();
    test()->withToken($token)->postJson('/api/v1/testing/reliability/reset')->assertOk();

    $written = test()->withToken($token)->postJson('/api/v1/testing/reliability/events', [
        'kind' => 'DRIVER_LATE', 'count' => 3, 'grouped' => true, 'minutesAgo' => 30,
    ])->assertStatus(201)->json('data');

    expect($written)->toHaveCount(3)
        ->and(collect($written)->pluck('kind')->unique()->all())->toBe(['DRIVER_LATE'])
        ->and(collect($written)->pluck('groupId')->unique())->toHaveCount(1)
        ->and($written[0]['groupId'])->not->toBeNull()
        ->and($written[0]['cause'])->toBeNull()
        ->and($written[0]['person'])->toBeNull()
        ->and($written[0]['canAppeal'])->toBeTrue();

    $single = test()->withToken($token)->postJson('/api/v1/testing/reliability/events', ['kind' => 'driver_cancelled_day'])
        ->assertStatus(201)->json('data.0');
    expect($single['groupId'])->toBeNull();

    $fee = test()->withToken($token)->postJson('/api/v1/testing/reliability/events', [
        'kind' => 'DRIVER_LATE_CAUSED_EXIT', 'cause' => 'cancelled_booking', 'bookingId' => $bookingId, 'withFee' => true, 'feePercent' => 20,
    ])->assertStatus(201)->json('data.0');

    expect($fee['fee']['amountPiastres'])->toBe(intdiv(Booking::query()->findOrFail($bookingId)->price_snapshot_piastres * 20, 100))
        ->and($fee['person']['userId'])->not->toBeNull();

    test()->withToken($token)->postJson('/api/v1/testing/reliability/events', ['kind' => 'DRIVER_FELL_ASLEEP'])
        ->assertStatus(422);
    test()->withToken($token)->postJson('/api/v1/testing/reliability/events', ['kind' => 'driver_late', 'withFee' => true])
        ->assertStatus(422)->assertJsonPath('error.fields.withFee.0', 'NEEDS_DRIVER_BOOKING_AND_FEE_KIND');
});

it('refuses driver kinds for somebody who is not an approved driver', function () {
    $token = verifiedPassenger('01112223344');

    test()->withToken($token)->postJson('/api/v1/testing/reliability/events', ['kind' => 'driver_late'])
        ->assertStatus(422)->assertJsonPath('error.fields.kind.0', 'DRIVER_NOT_APPROVED');

    test()->withToken($token)->postJson('/api/v1/testing/reliability/events', ['kind' => 'passenger_no_show', 'count' => 2])
        ->assertStatus(201)->assertJsonCount(2, 'data');
});

function reportedReview(): array
{
    [$driverToken, , $bookingId] = cancelledByDriver();
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $passenger = User::query()->where('phone_e164', '+201112223344')->sole();

    $rating = new Rating;
    $rating->forceFill([
        'booking_id' => $bookingId,
        'reviewer_user_id' => $passenger->id,
        'reviewed_user_id' => $driver->id,
        'direction' => RatingDirection::PassengerToDriver->value,
        'kind' => RatingKind::CompletedRide->value,
        'stars' => 1,
        'comment' => 'Rude.',
        'visible_at' => now(),
    ])->save();

    $reportId = test()->withToken($driverToken)->postJson("/api/v1/ratings/{$rating->id}/report", ['reason' => 'Not true.'])
        ->assertStatus(201)->json('data.id');

    return [$driverToken, $reportId, $rating->id];
}

it('removes a reported review the way a moderator would', function () {
    [$token, $reportId, $ratingId] = reportedReview();

    test()->withToken($token)->postJson("/api/v1/testing/ratings/reports/{$reportId}/decide", ['decision' => 'removed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'UPHELD')
        ->assertJsonPath('data.reviewVisible', false);

    test()->withToken($token)->getJson('/api/v1/ratings/about-me')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.byKind.completed_ride.count', 0);

    test()->withToken($token)->postJson("/api/v1/testing/ratings/reports/{$reportId}/decide", ['decision' => 'kept'])
        ->assertStatus(409)->assertJsonPath('error.code', 'REVIEW_REPORT_NOT_PENDING');
});

it('keeps a reported review, and it still reads as reported by her', function () {
    [$token, $reportId, $ratingId] = reportedReview();

    test()->withToken($token)->postJson("/api/v1/testing/ratings/reports/{$reportId}/decide", ['decision' => 'kept'])
        ->assertOk()
        ->assertJsonPath('data.status', 'DISMISSED')
        ->assertJsonPath('data.reviewVisible', true);

    test()->withToken($token)->getJson('/api/v1/ratings/about-me')
        ->assertOk()
        ->assertJsonPath('data.0.id', $ratingId)
        ->assertJsonPath('data.0.reportedByMe', true);
});

function replacementCar(string $token, int $seats = 5, string $plate = 'XYZ 4321'): string
{
    return test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2021,
        'colour' => 'White', 'plateNumber' => $plate, 'seats' => $seats,
    ])->assertStatus(201)->json('data.id');
}

it('approves a replacement car with the reviewer\'s seats, tier and AC', function () {
    $token = approvedDriver();
    $carId = replacementCar($token);

    test()->withToken($token)->postJson("/api/v1/testing/driver/vehicles/{$carId}/approve", [
        'approvedSeats' => 3, 'tier' => 'standard', 'hasAc' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.approvedSeats', 3)
        ->assertJsonPath('data.tier', 'STANDARD')
        ->assertJsonPath('data.hasAc', false)
        ->assertJsonPath('data.verificationStatus', 'APPROVED');

    $other = replacementCar($token, plate: 'XYZ 5555');
    test()->withToken($token)->postJson("/api/v1/testing/driver/vehicles/{$other}/approve", ['approvedSeats' => 9])
        ->assertStatus(422);
    test()->withToken($token)->postJson("/api/v1/testing/driver/vehicles/{$other}/approve")
        ->assertOk()->assertJsonPath('data.approvedSeats', 5)->assertJsonPath('data.tier', 'COMFORT');
});

it('deletes a spare test car, never the active one or one a commute uses', function () {
    $token = approvedDriver();
    $activeId = Vehicle::sole()->id;
    readyCommute($token, $activeId);
    $spareId = replacementCar($token);

    test()->withToken($token)->deleteJson("/api/v1/testing/driver/vehicles/{$activeId}")
        ->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_IS_ACTIVE');

    test()->withToken($token)->deleteJson("/api/v1/testing/driver/vehicles/{$spareId}")
        ->assertOk()->assertJsonPath('data.deleted', true);

    expect(Vehicle::query()->whereKey($spareId)->exists())->toBeFalse();
    test()->withToken($token)->deleteJson("/api/v1/testing/driver/vehicles/{$spareId}")->assertStatus(404);

    $movedFrom = $activeId;
    $newId = replacementCar($token, plate: 'XYZ 7777');
    test()->withToken($token)->postJson("/api/v1/testing/driver/vehicles/{$newId}/approve", ['approvedSeats' => 3])->assertOk();
    test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$newId}/activate")->assertOk();

    test()->withToken($token)->deleteJson("/api/v1/testing/driver/vehicles/{$movedFrom}")->assertOk();
});

it('says in the replace preview when the switch would be refused for seats', function () {
    $token = approvedDriver();
    $smallId = Vehicle::sole()->id;
    $bigId = replacementCar($token, seats: 5);
    test()->withToken($token)->postJson("/api/v1/testing/driver/vehicles/{$bigId}/approve", ['approvedSeats' => 5])->assertOk();
    test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$bigId}/activate")->assertOk();

    $commuteId = readyCommute($token, $bigId, ['seatsTotal' => 4]);
    test()->withToken($token)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();
    test()->withToken($token)->postJson("/api/v1/commutes/{$commuteId}/pause")->assertSuccessful();

    test()->withToken($token)->getJson("/api/v1/driver/vehicles/replace-preview?vehicle={$smallId}")
        ->assertOk()
        ->assertJsonPath('data.blocked.code', 'COMMUTE_SEATS_EXCEED_APPROVED')
        ->assertJsonPath('data.blocked.commuteIds', [$commuteId])
        ->assertJsonPath('data.blocked.approvedSeats', 3);

    test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$smallId}/activate")
        ->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_SEATS_EXCEED_APPROVED');

    $spareId = replacementCar($token, plate: 'XYZ 8888');
    test()->withToken($token)->getJson("/api/v1/driver/vehicles/replace-preview?vehicle={$spareId}")
        ->assertJsonPath('data.blocked.code', 'VEHICLE_NOT_ALLOWED');

    test()->withToken($token)->getJson('/api/v1/driver/vehicles/replace-preview')
        ->assertJsonPath('data.blocked', null);
});

it('sets a car\'s approval date through the registration expiry call', function () {
    $token = approvedDriver();
    $carId = Vehicle::sole()->id;

    test()->withToken($token)->postJson('/api/v1/testing/driver/documents/vehicle_registration/expiry', ['expiresAt' => '2027-03-03'])->assertOk();
    test()->withToken($token)->getJson('/api/v1/driver/vehicles')->assertJsonPath('data.0.approvalValidUntil', '2027-03-03');

    test()->withToken($token)->postJson('/api/v1/testing/driver/documents/vehicle_registration/expiry', ['expiresAt' => now()->subDay()->toDateString()])->assertOk();
    test()->withToken($token)->getJson('/api/v1/driver/vehicles')
        ->assertJsonPath('data.0.id', $carId)
        ->assertJsonPath('data.0.status', 'SUSPENDED')
        ->assertJsonPath('data.0.suspensionReason', 'registration_expired');
});
