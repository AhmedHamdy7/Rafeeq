<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Actions\AddDriverCapabilityAction;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Actions\SweepDelayedTripsAction;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
});

function driverHome(): array
{
    return test()->withToken(test()->driverToken)->getJson('/api/v1/driver/home')->assertOk()->json('data');
}

function waitingFor(string $commuteId, array $attributes = []): SeatRequest
{
    return SeatRequest::factory()->waitlisted()->create([
        'commute_offer_id' => $commuteId,
        ...$attributes,
    ]);
}

it('keeps a late run on the card until the sweep expires it', function (int $late) {
    runLeavingIn($this->tripId, -$late);

    $run = driverHome()['nextRun'];

    expect($run['tripId'])->toBe($this->tripId)
        ->and($run['departsInMinutes'])->toBe(-$late)
        ->and($run['leaveBy'])->toBeNull()
        ->and($run['canStart'])->toBeTrue();
})->with([1, 10, 19]);

it('moves on once the sweep has expired the run, and shows it as expired today', function () {
    runLeavingIn($this->tripId, -21);

    app(SweepDelayedTripsAction::class)->sweep();

    $trip = ScheduledTrip::query()->findOrFail($this->tripId);
    $home = driverHome();

    expect($trip->status)->toBe(ScheduledTripStatus::Expired)
        ->and($trip->expired_at)->not->toBeNull()
        ->and($home['nextRun']['tripId'] ?? null)->not->toBe($this->tripId)
        ->and($home['expiredRun']['tripId'])->toBe($this->tripId)
        ->and($home['expiredRun']['commuteId'])->toBe($this->commuteId)
        ->and($home['expiredRun']['originLabel'])->toBe('Rehab Gate 2')
        ->and($home['expiredRun']['expiredAt'])->toBe($trip->expired_at->toIso8601String());
});

it('stops showing an expired run on the next local day', function () {
    runLeavingIn($this->tripId, -21);
    app(SweepDelayedTripsAction::class)->sweep();

    ScheduledTrip::query()->whereKey($this->tripId)->update([
        'expired_at' => now('Africa/Cairo')->startOfDay()->subMinute()->utc(),
    ]);

    expect(driverHome()['expiredRun'])->toBeNull();
});

it('does not bring back a late run from yesterday', function () {
    runLeavingIn($this->tripId, -60 * 24);

    expect(driverHome()['nextRun']['tripId'] ?? null)->not->toBe($this->tripId);
});

it('publishes the phase for each moment of the run', function (int $minutes, string $phase) {
    runLeavingIn($this->tripId, $minutes);

    expect(driverHome()['nextRun']['phase'])->toBe($phase);
})->with([
    [130, 'scheduled'],
    [25, 'starting_soon'],
    [-1, 'not_started'],
    [-10, 'late'],
]);

it('publishes the deadlines from the settings, within 3 hours or late, and null otherwise', function () {
    config([
        'rafeeq.trip.delay_passenger_warning_minutes' => 6,
        'rafeeq.trip.delay_violation_minutes' => 11,
        'rafeeq.trip.delay_expiry_minutes' => 21,
    ]);

    $trip = runLeavingIn($this->tripId, 60);
    $deadlines = driverHome()['nextRun']['startDeadlines'];

    expect($deadlines['warningAt'])->toBe($trip->departure_at->copy()->addMinutes(6)->toIso8601String())
        ->and($deadlines['lateAt'])->toBe($trip->departure_at->copy()->addMinutes(11)->toIso8601String())
        ->and($deadlines['expiresAt'])->toBe($trip->departure_at->copy()->addMinutes(21)->toIso8601String())
        ->and($deadlines['warningLocal'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');

    runLeavingIn($this->tripId, 200);

    expect(driverHome()['nextRun']['startDeadlines'])->toBeNull();
});

it('publishes the day promise, and null when there is none', function () {
    $trip = runLeavingIn($this->tripId, 60);
    $trip->forceFill(['promised_arrival_at' => now()->addMinutes(130)])->save();

    expect(driverHome()['nextRun']['promisedArrivalAt'])->toBe($trip->refresh()->promised_arrival_at->toIso8601String());

    $trip->forceFill(['promised_arrival_at' => null])->save();

    $run = driverHome()['nextRun'];

    expect($run['promisedArrivalAt'])->toBeNull()->and($run['promisedArrivalLocal'])->toBeNull();
});

it('publishes the start time and in_progress once started', function () {
    runLeavingIn($this->tripId, 30);

    expect(driverHome()['nextRun']['startedAt'])->toBeNull();

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertCreated();

    $run = driverHome()['nextRun'];

    expect($run['startedAt'])->not->toBeNull()
        ->and($run['startedLocal'])->not->toBeNull()
        ->and($run['phase'])->toBe('in_progress')
        ->and($run['canStart'])->toBeFalse()
        ->and($run['startOpensAt'])->toBeNull()
        ->and($run['startDeadlines'])->toBeNull();
});

it('says canStart exactly when the start endpoint would accept', function (int $minutes, bool $expected) {
    $trip = runLeavingIn($this->tripId, $minutes);
    $run = driverHome()['nextRun'];

    expect($run['canStart'])->toBe($expected)
        ->and($run['startOpensAt'])->toBe($trip->departure_at->copy()->subMinutes(90)->toIso8601String());

    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")
        ->assertStatus($expected ? 201 : 422);
})->with([[130, false], [80, true]]);

it('says canStart false when the licence has expired', function () {
    runLeavingIn($this->tripId, 30);
    DriverProfile::query()->sole()->forceFill(['licence_expiry' => now()->subDay()])->save();

    expect(driverHome()['nextRun']['canStart'])->toBeFalse();
});

it('says canStart false for a suspended account', function () {
    runLeavingIn($this->tripId, 30);
    User::query()->whereKey(DriverProfile::query()->sole()->user_id)->update(['account_status' => AccountStatus::Suspended->value]);

    expect(driverHome()['nextRun']['canStart'])->toBeFalse();
});

it('counts the waiting list per day and across the driver, one person once', function () {
    $trip = runLeavingIn($this->tripId, 60);
    $bit = DaysMask::bitForDate($trip->trip_date);
    $otherDays = DaysMask::fromBits(127 & ~$bit)->value;

    $both = waitingFor($this->commuteId, ['requested_days_mask' => null, 'scheduled_trip_id' => null]);
    waitingFor($this->commuteId, ['requested_days_mask' => $otherDays, 'scheduled_trip_id' => null]);
    waitingFor($this->commuteId, ['requested_days_mask' => null, 'scheduled_trip_id' => $this->tripId]);
    waitingFor($this->commuteId, ['requested_days_mask' => null, 'scheduled_trip_id' => null, 'paused_at' => now()]);

    SeatRequest::factory()->create(['commute_offer_id' => $this->commuteId, 'passenger_user_id' => $both->passenger_user_id, 'status' => SeatRequestStatus::Pending]);

    $home = driverHome();

    expect($home['nextRun']['waitlistCount'])->toBe(2)
        ->and($home['waitlist']['count'])->toBe(4);

    foreach ($home['laterThisWeek'] as $row) {
        expect($row)->toHaveKey('waitlistCount');
    }
});

it('reads a zero waiting list as 0, not null', function () {
    $home = driverHome();

    expect($home['nextRun']['waitlistCount'])->toBe(0)->and($home['waitlist'])->toBe(['count' => 0]);
});

it('says whether a passenger who switched rejoined the list', function () {
    runLeavingIn($this->tripId, 200);

    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $booking = Booking::query()->sole();
    $booking->forceFill([
        'status' => BookingStatus::CancelledByPassenger->value,
        'cancelled_at' => now(),
        'cancelled_reason' => SwitchReason::Voluntary->value,
    ])->save();

    $notice = driverHome()['notices'][0];

    expect($notice['kind'])->toBe('passenger_switched')->and($notice['rejoinedWaitlist'])->toBeFalse();

    waitingFor($this->commuteId, ['passenger_user_id' => $booking->passenger_user_id, 'scheduled_trip_id' => $this->tripId]);

    expect(driverHome()['notices'][0]['rejoinedWaitlist'])->toBeTrue();
});

it('carries the checklist summary, with no items', function () {
    $verification = driverHome()['verification'];

    expect($verification['overall'])->toBe('verified')
        ->and($verification['of'])->toBe(5)
        ->and($verification)->not->toHaveKey('items')
        ->and($verification['actionItem'])->toBeNull()
        ->and($verification['suspensionReason'])->toBeNull();
});

it('gives no verification block to an account that is not a driver', function () {
    fakeOtpSender();
    $token = passenger('01112223344');

    $data = test()->withToken($token)->getJson('/api/v1/driver/home')->assertOk()->json('data');

    expect($data['verification'])->toBeNull()
        ->and($data['expiredRun'])->toBeNull()
        ->and($data['waitlist'])->toBe(['count' => 0]);
});

it('names the item at fault in the block', function () {
    $licence = UserVerification::query()->where('type', VerificationType::DrivingLicence->value)->sole();
    $licence->forceFill(['status' => 'pending'])->save();

    app(ReviewVerificationAction::class)->requestMoreInfo($licence->refresh(), adminWithRole(AdminRole::Verification), 'The licence photo is too blurry.');

    expect(driverHome()['verification']['actionItem'])->toBe([
        'key' => 'driving_licence',
        'status' => 'action_needed',
        'rejectionReason' => 'The licence photo is too blurry.',
    ]);
});

it('gives a suspended driver the reason on both endpoints', function () {
    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::query()->sole(), adminWithRole(AdminRole::Verification), 'Repeated no-show reports.');

    $checklist = test()->withToken($this->driverToken)->getJson('/api/v1/driver/verification')->assertOk()->json('data');

    expect($checklist['overall'])->toBe('suspended')
        ->and($checklist['suspensionReason'])->toBe('Repeated no-show reports.')
        ->and(driverHome()['verification']['suspensionReason'])->toBe('Repeated no-show reports.');
});

it('reads a rejected car as action required, with the reason', function () {
    Vehicle::query()->sole()->forceFill([
        'verification_status' => VehicleVerificationStatus::Rejected->value,
        'rejection_reason' => 'The car photo is unreadable.',
    ])->save();

    $checklist = test()->withToken($this->driverToken)->getJson('/api/v1/driver/verification')->assertOk()->json('data');
    $items = collect($checklist['items'])->keyBy('key');

    expect($checklist['overall'])->toBe('action_required')
        ->and($items['vehicle_details']['status'])->toBe('action_needed')
        ->and($items['vehicle_details']['rejectionReason'])->toBe('The car photo is unreadable.')
        ->and($items['vehicle_registration']['status'])->toBe('action_needed');
});

it('reads a rejected application as action required, not under review', function () {
    $profile = DriverProfile::query()->sole();
    $profile->forceFill(['status' => DriverProfileStatus::Rejected->value, 'rejection_reason' => 'The registration is unreadable.'])->save();
    Vehicle::query()->sole()->forceFill(['verification_status' => VehicleVerificationStatus::Pending->value])->save();

    $checklist = test()->withToken($this->driverToken)->getJson('/api/v1/driver/verification')->assertOk()->json('data');

    expect($checklist['overall'])->toBe('action_required')
        ->and($checklist['actionItem'])->toBe([
            'key' => 'application',
            'status' => 'action_needed',
            'rejectionReason' => 'The registration is unreadable.',
        ]);
});

it('reads a complete draft as in progress until it is submitted', function () {
    $token = readyDriverApplicant(phone: '01012345999', devicePublicId: 'applicant-ready');
    completeDriverApplication($token, seed: 2);

    $data = test()->withToken($token)->getJson('/api/v1/driver/verification')->assertOk()->json('data');
    $items = collect($data['items'])->keyBy('key');

    expect($data['overall'])->toBe('in_progress')
        ->and($items['vehicle_details']['status'])->toBe('in_progress')
        ->and($items['vehicle_registration']['status'])->toBe('in_progress');

    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $data = test()->withToken($token)->getJson('/api/v1/driver/verification')->assertOk()->json('data');
    $items = collect($data['items'])->keyBy('key');

    expect($data['overall'])->toBe('under_review')
        ->and($items['vehicle_details']['status'])->toBe('under_review');
});

it('falls back to the typed licence date once the licence is approved', function () {
    $expiry = now()->addDays(10)->startOfDay();

    DriverProfile::query()->sole()->forceFill(['licence_expiry' => $expiry])->save();
    UserVerification::query()->where('type', VerificationType::DrivingLicence->value)->sole()
        ->forceFill(['status' => 'approved', 'expires_at' => null])->save();

    $checklist = test()->withToken($this->driverToken)->getJson('/api/v1/driver/verification')->assertOk()->json('data');
    $licence = collect($checklist['items'])->firstWhere('key', 'driving_licence');

    expect($checklist['overall'])->toBe('expiring')
        ->and($checklist['licenceExpiresAt'])->not->toBeNull()
        ->and($licence['expiresAt'])->not->toBeNull();
});

it('reads an expired typed date as renewal required, naming the item', function () {
    DriverProfile::query()->sole()->forceFill(['licence_expiry' => now()->subDay()])->save();
    UserVerification::query()->where('type', VerificationType::DrivingLicence->value)->sole()
        ->forceFill(['status' => 'approved', 'expires_at' => null])->save();

    $verification = driverHome()['verification'];

    expect($verification['overall'])->toBe('renewal_required')
        ->and($verification['actionItem']['key'])->toBe('driving_licence')
        ->and($verification['actionItem']['status'])->toBe('expired');
});

it('copies the typed date onto the licence at approval', function () {
    $expiry = now()->addYear()->startOfDay();
    DriverProfile::query()->sole()->forceFill(['licence_expiry' => $expiry])->save();

    $licence = UserVerification::query()->where('type', VerificationType::DrivingLicence->value)->sole();
    $licence->forceFill(['status' => 'pending', 'expires_at' => null])->save();

    app(ReviewVerificationAction::class)->approve($licence->refresh(), adminWithRole(AdminRole::Verification));

    expect($licence->refresh()->expires_at->toDateString())->toBe($expiry->toDateString());
});

it('keeps the passenger capability when a passenger adds driving', function () {
    $user = User::factory()->woman()->create([
        'registered_role' => RegisteredRole::Passenger,
        'initial_role_selected_at' => now(),
    ]);

    app(AddDriverCapabilityAction::class)->execute($user);

    expect($user->capabilities()->pluck('capability')->map(fn ($capability) => $capability->value)->sort()->values()->all())
        ->toBe([Capability::Driver->value, Capability::Passenger->value]);
});

it('backfills capabilities from the registered role and the driver profile', function () {
    $migration = require base_path('database/migrations/2026_11_04_100100_backfill_capabilities_and_licence_expiry.php');

    $passenger = User::factory()->woman()->create(['registered_role' => RegisteredRole::Passenger, 'initial_role_selected_at' => now()]);
    $driverPassenger = User::factory()->woman()->create(['registered_role' => RegisteredRole::Passenger, 'initial_role_selected_at' => now()]);
    DriverProfile::factory()->create(['user_id' => $driverPassenger->id]);

    $migration->up();
    $migration->up();

    $values = fn (User $user) => $user->capabilities()->pluck('capability')->map(fn ($capability) => $capability->value)->sort()->values()->all();

    expect($values($passenger))->toBe(['passenger'])
        ->and($values($driverPassenger))->toBe(['driver', 'passenger']);
});
