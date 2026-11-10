<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Actions\WarnExpiringLicencesAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Domains\Trip\Actions\SendTripRemindersAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\Storage;

/**
 * The pushes Feature 03 files §8 found missing: the 1-hour and 10-minute reminders, "heading to
 * you", "arrived", documents approved / rejected and the licence warning. (The +10 "still not set
 * off" is held with the rest of the delay ladder, in DelayLadderTest.)
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 3]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
});

function typed(User $user, string $type): int
{
    return Notification::query()->where('user_id', $user->id)->where('channel', 'in_app')->where('type', $type)->count();
}

/**
 * @return array{token: string, bookingId: string, user: User}
 */
function bookedRider(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);

    return [
        'token' => $token,
        'bookingId' => approveSeat($driverToken, requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
            ->assertStatus(201)->json('data.id')),
        'user' => User::query()->where('phone_e164', '+2'.$phone)->sole(),
    ];
}

/*
|--------------------------------------------------------------------------
| An hour and ten minutes before
|--------------------------------------------------------------------------
*/

it('reminds both sides an hour before and again ten minutes before, once each', function () {
    $her = bookedRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    runLeavingIn($this->tripId, 55);
    expect(app(SendTripRemindersAction::class)->sweep())->toBe(['early' => 1, 'final' => 0]);
    app(SendTripRemindersAction::class)->sweep();

    expect(typed($her['user'], 'trip_starts_in_hour'))->toBe(1)
        ->and(typed($this->driver, 'trip_starts_in_hour'))->toBe(1);

    runLeavingIn($this->tripId, 8);
    expect(app(SendTripRemindersAction::class)->sweep())->toBe(['early' => 0, 'final' => 1]);
    app(SendTripRemindersAction::class)->sweep();

    expect(typed($her['user'], 'trip_starts_soon'))->toBe(1)
        ->and(typed($this->driver, 'trip_starts_soon'))->toBe(1);

    $push = Notification::query()->where('user_id', $her['user']->id)->where('type', 'trip_starts_soon')
        ->where('channel', 'in_app')->sole();

    expect($push->data)->toEqual(['bookingId' => $her['bookingId'], 'tripId' => $this->tripId]);
});

it('sends only the final reminder when both are due at once', function () {
    $her = bookedRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    runLeavingIn($this->tripId, 5);
    app(SendTripRemindersAction::class)->sweep();

    expect(typed($her['user'], 'trip_starts_soon'))->toBe(1)
        ->and(typed($her['user'], 'trip_starts_in_hour'))->toBe(0);

    // And the hour one is never sent late.
    runLeavingIn($this->tripId, 4);
    app(SendTripRemindersAction::class)->sweep();

    expect(typed($her['user'], 'trip_starts_in_hour'))->toBe(0);
});

it('does not remind a driver about a day nobody booked', function () {
    runLeavingIn($this->tripId, 55);

    app(SendTripRemindersAction::class)->sweep();

    expect(typed($this->driver, 'trip_starts_in_hour'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| On the day
|--------------------------------------------------------------------------
*/

/**
 * Feature 03 files T-3: the next passenger in route order is told when the driver finishes the
 * pickup before hers — and never the first one, who was told the run started.
 */
it('tells the next passenger in route order when the pickup before hers is done', function () {
    $a = bookedRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $b = bookedRider($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    underway($this->driverToken, $this->tripId);

    $order = app(BuildNavigationItineraryAction::class)
        ->forTrip(ScheduledTrip::query()->findOrFail($this->tripId))
        ->filter(fn (NavigationStop $stop) => $stop->kind === NavigationStopKind::PassengerPickup)
        ->pluck('booking_id')->map(fn ($id) => (string) $id)->values()->all();

    $riders = collect([$a, $b])->keyBy('bookingId');
    $first = $riders[$order[0]];
    $second = $riders[$order[1]];

    checkIn($this->driverToken, $this->tripId, ['bookingId' => $first['bookingId']])->assertOk();

    expect(typed($second['user'], 'driver_heading_to_you'))->toBe(1)
        ->and(typed($first['user'], 'driver_heading_to_you'))->toBe(0);

    // A no-show of the last pickup tells nobody: there is nobody after her.
    arrivedAndWaited($this->driverToken, $this->tripId, $second['bookingId']);
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $second['bookingId']])->assertOk();

    expect(Notification::query()->where('type', 'driver_heading_to_you')->where('channel', 'in_app')->count())->toBe(1);
});

it('tells her once that the driver has arrived', function () {
    $her = bookedRider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)
        // "I've arrived" is checked by GPS at her pickup (TRIP-10), so the driver says where he is.
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers", ['bookingId' => $her['bookingId'], ...atPickupOf($her['bookingId'])])
        ->assertStatus(201);

    expect(typed($her['user'], 'driver_arrived'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The driver's papers
|--------------------------------------------------------------------------
*/

it('tells a driver her application was approved', function () {
    expect(typed($this->driver, 'driver_verified'))->toBe(1);
});

it('tells an applicant her application was sent back, without the reason on the lock screen', function () {
    $token = readyDriverApplicant('01033334444', 'dev-2');
    completeDriverApplication($token, 2);
    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    app(ReviewDriverApplicationAction::class)->reject(
        DriverProfile::query()->where('status', DriverProfileStatus::PendingReview->value)->sole(),
        AdminUser::factory()->create(),
        'The licence photo is blurred — retake it in daylight.',
    );

    $applicant = User::query()->where('phone_e164', '+201033334444')->sole();
    $notice = Notification::query()->where('user_id', $applicant->id)
        ->where('type', 'driver_documents_rejected')->where('channel', 'in_app')->sole();

    expect($notice->body)->not->toContain('blurred');
});

it('warns about an expiring licence once per expiry date', function () {
    UserVerification::query()->where('user_id', $this->driver->id)
        ->where('type', VerificationType::DrivingLicence->value)->delete();

    $licence = UserVerification::factory()->approved()->create([
        'user_id' => $this->driver->id,
        'type' => VerificationType::DrivingLicence,
        'expires_at' => now()->addDays(10),
    ]);

    $warn = fn () => app(WarnExpiringLicencesAction::class)->sweep();

    expect($warn())->toBe(1)
        ->and($warn())->toBe(0)
        ->and(typed($this->driver, 'driver_item_expiring'))->toBe(1);

    // Renewed to a date still inside the window: that one is a new expiry, warned about once.
    $licence->forceFill(['expires_at' => now()->addDays(20)])->save();

    expect($warn())->toBe(1)
        ->and(typed($this->driver, 'driver_item_expiring'))->toBe(2);

    // Far off: nothing.
    $licence->forceFill(['expires_at' => now()->addDays(200)])->save();

    expect($warn())->toBe(0);
});
