<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();
});

function p19bPublished(string $driverToken): array
{
    $commuteId = readyCommute($driverToken, Vehicle::query()->where('is_active', true)->sole()->id, ['seatsTotal' => 3]);
    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$commuteId, ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->firstOrFail()->id];
}

function p19bRider(string $driverToken, string $commuteId, string $tripId): array
{
    $token = verifiedPassenger('01112223344', device: 'pax-1');
    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    return [$token, approveSeat($driverToken, $requestId)];
}

it('expires an open change proposal now, for the driver and the passenger to read', function () {
    $driver = approvedDriver();
    [$commuteId, $tripId] = p19bPublished($driver);
    CommuteWeekdaySchedule::query()->where('commute_offer_id', $commuteId)->update(['departure_is_computed' => false]);
    [$rider] = p19bRider($driver, $commuteId, $tripId);

    $proposalId = test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/commutes/{$commuteId}/change-proposals", [
        'scheduledTripId' => $tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($rider)->postJson("/api/v1/testing/change-proposals/{$proposalId}/expire")
        ->assertOk()
        ->assertJsonPath('data.status', 'EXPIRED');

    expect(ChangeProposal::query()->findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Expired)
        ->and(ChangeProposal::query()->findOrFail($proposalId)->expires_at->isPast())->toBeTrue();

    test()->flushHeaders()->withToken($rider)->getJson("/api/v1/change-proposals/{$proposalId}")
        ->assertOk()->assertJsonPath('data.status', 'EXPIRED');
    expect(collect(test()->flushHeaders()->withToken($driver)->getJson('/api/v1/driver/change-proposals')->json('data'))->firstWhere('id', $proposalId)['status'])
        ->toBe('EXPIRED');

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/testing/change-proposals/{$proposalId}/expire")
        ->assertStatus(409)->assertJsonPath('error.code', 'CHANGE_PROPOSAL_NOT_LIVE');

    $stranger = verifiedPassenger('01112223399', device: 'pax-9');
    test()->flushHeaders()->withToken($stranger)->postJson("/api/v1/testing/change-proposals/{$proposalId}/expire")->assertNotFound();
});

it('sweeps overdue change proposals every minute with the seat requests', function () {
    $driver = approvedDriver();
    [$commuteId, $tripId] = p19bPublished($driver);
    CommuteWeekdaySchedule::query()->where('commute_offer_id', $commuteId)->update(['departure_is_computed' => false]);
    p19bRider($driver, $commuteId, $tripId);

    $proposalId = test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/commutes/{$commuteId}/change-proposals", [
        'scheduledTripId' => $tripId,
        'scope' => 'only_this_occurrence',
        'departureTime' => '07:30:00',
    ])->assertStatus(201)->json('data.id');

    ChangeProposal::query()->whereKey($proposalId)->update(['expires_at' => now()->subMinute()]);

    $this->artisan('requests:expire')->assertSuccessful();

    expect(ChangeProposal::query()->findOrFail($proposalId)->status)->toBe(ChangeProposalStatus::Expired);
});

it('closes a one-day request as if its day passed, and refuses one that is not open', function () {
    $driver = approvedDriver();
    [$commuteId, $tripId] = p19bPublished($driver);
    $pax = verifiedPassenger('01112223344', device: 'pax-1');
    $requestId = requestSeat($pax, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($pax)->postJson("/api/v1/testing/seat-requests/{$requestId}/close", ['status' => 'APPROVED'])
        ->assertStatus(422);

    test()->flushHeaders()->withToken($pax)->postJson("/api/v1/testing/seat-requests/{$requestId}/close", ['status' => 'CLOSED_DATE_PASSED'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CLOSED_DATE_PASSED');

    expect(SeatRequest::query()->findOrFail($requestId)->status)->toBe(SeatRequestStatus::ClosedDatePassed);
    test()->flushHeaders()->withToken($pax)->getJson("/api/v1/seat-requests/{$requestId}")
        ->assertOk()->assertJsonPath('data.status', 'CLOSED_DATE_PASSED');

    test()->flushHeaders()->withToken($pax)->postJson("/api/v1/testing/seat-requests/{$requestId}/close", ['status' => 'CLOSED_DATE_PASSED'])
        ->assertStatus(409)->assertJsonPath('error.code', 'SEAT_REQUEST_NOT_PENDING');
});

it('cancels a run as staff: no fee or reliability mark on the driver, the passenger told by the code', function () {
    $driver = approvedDriver();
    [$commuteId, $tripId] = p19bPublished($driver);
    [$rider, $bookingId] = p19bRider($driver, $commuteId, $tripId);

    test()->flushHeaders()->withToken($rider)->postJson("/api/v1/testing/trips/{$tripId}/cancel-by-platform", [])->assertStatus(422);

    test()->flushHeaders()->withToken($rider)->postJson("/api/v1/testing/trips/{$tripId}/cancel-by-platform", ['reason' => 'Road closed by the authorities'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')
        ->assertJsonPath('data.cancelledBy', 'PLATFORM')
        ->assertJsonPath('data.bookingsCancelled', 1);

    $booking = Booking::query()->findOrFail($bookingId);
    expect($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and($booking->cancel_code)->toBe('cancelled_by_platform')
        ->and(Notification::query()->where('type', 'trip_day_cancelled_by_platform')->where('channel', 'in_app')->sole()->body)->toContain('YuGo')
        ->and(DebtEntry::query()->count())->toBe(0)
        ->and(ReliabilityEvent::query()->count())->toBe(0);

    $row = collect(test()->flushHeaders()->withToken($driver)->getJson('/api/v1/driver/trips?scope=past')->assertOk()->json('data'))
        ->firstWhere('id', $tripId);
    expect($row['cancelledBy'])->toBe('PLATFORM');

    test()->flushHeaders()->withToken($rider)->getJson("/api/v1/bookings/{$bookingId}")
        ->assertOk()->assertJsonPath('data.cancelledReason', 'cancelled_by_platform');

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/testing/trips/{$tripId}/cancel-by-platform", ['reason' => 'Again'])
        ->assertStatus(409)->assertJsonPath('error.code', 'TRIP_NOT_CANCELLABLE');

    $stranger = verifiedPassenger('01112223399', device: 'pax-9');
    test()->flushHeaders()->withToken($stranger)->postJson("/api/v1/testing/trips/{$tripId}/cancel-by-platform", ['reason' => 'x'])->assertNotFound();
});

it('puts the driver application in each state the checklist knows', function (array $body, string $overall, int $completed, array $statuses, ?array $actionItem) {
    $token = readyDriverApplicant();

    $response = test()->flushHeaders()->withToken($token)->postJson('/api/v1/testing/driver/application/state', $body)
        ->assertOk()
        ->assertJsonPath('data.overall', $overall)
        ->assertJsonPath('data.completed', $completed);

    expect(array_column($response->json('data.items'), 'status'))->toBe($statuses);

    $read = test()->flushHeaders()->withToken($token)->getJson('/api/v1/driver/verification')->assertOk();
    expect($read->json('data.overall'))->toBe($overall)
        ->and($read->json('data.actionItem'))->toBe($actionItem);
})->with([
    'not started' => [['state' => 'not_started'], 'not_started', 0, ['not_started', 'not_started', 'not_started', 'not_started', 'not_started'], null],
    'in progress, 1 of 5' => [['state' => 'in_progress', 'count' => 1], 'in_progress', 1, ['approved', 'not_started', 'not_started', 'not_started', 'not_started'], null],
    'in progress, 4 of 5' => [['state' => 'in_progress', 'count' => 4], 'in_progress', 2, ['approved', 'approved', 'in_progress', 'not_started', 'in_progress'], null],
    'ready to submit' => [['state' => 'ready_to_submit'], 'in_progress', 2, ['approved', 'approved', 'in_progress', 'in_progress', 'in_progress'], null],
    'submitted' => [['state' => 'submitted'], 'under_review', 2, ['approved', 'approved', 'under_review', 'under_review', 'under_review'], null],
    'action on the licence' => [['state' => 'action_required', 'item' => 'driving_licence', 'reason' => 'Blurry photo'], 'action_required', 2, ['approved', 'approved', 'action_needed', 'under_review', 'under_review'], ['key' => 'driving_licence', 'status' => 'action_needed', 'rejectionReason' => 'Blurry photo']],
    'action on the ID' => [['state' => 'action_required', 'item' => 'government_id'], 'action_required', 1, ['action_needed', 'approved', 'under_review', 'under_review', 'under_review'], ['key' => 'government_id', 'status' => 'action_needed', 'rejectionReason' => 'The photo is too blurry to read. Please upload it again.']],
    'action on the registration' => [['state' => 'action_required', 'item' => 'vehicle_registration', 'reason' => 'Expired paper'], 'action_required', 2, ['approved', 'approved', 'in_progress', 'action_needed', 'in_progress'], ['key' => 'vehicle_registration', 'status' => 'action_needed', 'rejectionReason' => 'Expired paper']],
    'action on the application' => [['state' => 'action_required', 'reason' => 'Documents of two people'], 'action_required', 2, ['approved', 'approved', 'in_progress', 'in_progress', 'in_progress'], ['key' => 'application', 'status' => 'action_needed', 'rejectionReason' => 'Documents of two people']],
]);

it('can move the application back and forth, and refuses a driver who already has commutes', function () {
    $token = readyDriverApplicant();

    test()->flushHeaders()->withToken($token)->postJson('/api/v1/testing/driver/application/state', ['state' => 'submitted'])
        ->assertOk()->assertJsonPath('data.overall', 'under_review');
    test()->flushHeaders()->withToken($token)->postJson('/api/v1/testing/driver/application/state', ['state' => 'in_progress', 'count' => 2])
        ->assertOk()->assertJsonPath('data.overall', 'in_progress');
    test()->flushHeaders()->withToken($token)->postJson('/api/v1/testing/driver/application/state', ['state' => 'bogus'])
        ->assertStatus(422);

    $driver = approvedDriver('01012345679', 'dev-2', seed: 2);
    p19bPublished($driver);
    test()->flushHeaders()->withToken($driver)->postJson('/api/v1/testing/driver/application/state', ['state' => 'not_started'])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
});

it('makes eligibility return an under-age or a generic blocker, and takes it away', function () {
    $token = readyDriverApplicant();
    $user = User::query()->where('phone_e164', 'like', '%1012345678')->sole();
    $birth = $user->date_of_birth?->toDateString();

    test()->flushHeaders()->withToken($token)->postJson('/api/v1/testing/driver/blocker', ['kind' => 'under_age'])
        ->assertOk()->assertJsonPath('data.eligible', false);
    expect(collect(test()->flushHeaders()->withToken($token)->getJson('/api/v1/driver/eligibility')->json('data.blockers'))->pluck('reason')->all())
        ->toContain('below_minimum_age');

    test()->flushHeaders()->withToken($token)->postJson('/api/v1/testing/driver/blocker', ['kind' => 'generic'])->assertOk();
    $blockers = collect(test()->flushHeaders()->withToken($token)->getJson('/api/v1/driver/eligibility')->json('data.blockers'));
    expect($blockers->pluck('reason')->all())->toContain('not_eligible')
        ->and($blockers->firstWhere('reason', 'not_eligible')['nextStep'])->toBe('CONTACT_SUPPORT');

    config(['rafeeq.testing.api' => false]);
    expect(collect(test()->flushHeaders()->withToken($token)->getJson('/api/v1/driver/eligibility')->json('data.blockers'))->pluck('reason')->all())
        ->not->toContain('not_eligible');
    config(['rafeeq.testing.api' => true]);

    test()->flushHeaders()->withToken($token)->deleteJson('/api/v1/testing/driver/blocker')->assertOk();
    $reasons = collect(test()->flushHeaders()->withToken($token)->getJson('/api/v1/driver/eligibility')->json('data.blockers'))->pluck('reason')->all();
    expect($reasons)->not->toContain('not_eligible')->not->toContain('below_minimum_age')
        ->and($user->fresh()->date_of_birth?->toDateString())->toBe($birth);
});
