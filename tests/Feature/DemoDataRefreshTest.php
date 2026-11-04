<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DriverHomeStatesSeeder;

/**
 * `demo:refresh` — a long-lived demo environment where the calendar has moved on.
 *
 * The mobile team's Home showed `nextJourney: null` and `topMatches: []` a few days after seeding:
 * every tester's one booking was on a day already past, and their saved requests had never been
 * matched. This is that situation, then the command that fixes it.
 */
beforeEach(function () {
    fakeOtpSender();
    $this->seed(DemoDataSeeder::class);

    $this->omar = User::query()->where('phone_e164', '+201014531739')->sole();
});

it('gives a fresh demo environment matches on Home', function () {
    $this->actingAs($this->omar, 'sanctum')->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.topMatches', fn (array $matches) => count($matches) >= 1);
});

it('puts a tester whose trips are all in the past back on an upcoming one', function () {
    // The calendar moves on: every one of Omar's booked days is now behind him.
    ScheduledTrip::query()
        ->whereIn('id', Booking::query()->where('passenger_user_id', $this->omar->id)->select('scheduled_trip_id'))
        ->update(['departure_at' => now()->subDays(2)]);

    $this->actingAs($this->omar, 'sanctum')->getJson('/api/v1/home')->assertJsonPath('data.nextJourney', null);

    $this->artisan('demo:refresh')->expectsOutputToContain('seated on an upcoming trip')->assertSuccessful();

    $this->actingAs($this->omar, 'sanctum')->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.nextJourney.driver.publicFirstName', fn (?string $name) => $name !== null);
});

it('is refused in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:refresh')->assertFailed();
});

it('adds the match states to a demo environment seeded before them, and only once', function () {
    CommuteLocation::query()->whereIn('type', ['pickup', 'dropoff'])->delete();
    CommuteRule::query()->whereHas('commuteOffer.driverProfile.user', fn ($user) => $user->where('phone_e164', 'like', '+2010555500%'))->delete();
    User::query()->where('phone_e164', '+201055559901')->delete();

    $this->artisan('demo:match-states')->expectsOutputToContain('tester created')->assertSuccessful();

    $points = CommuteLocation::query()->whereIn('type', ['pickup', 'dropoff'])->count();

    expect($points)->toBe(8)
        ->and(User::query()->where('phone_e164', '+201055559901')->sole()->gender->value)->toBe('man');

    $this->artisan('demo:match-states')->assertSuccessful();

    expect(CommuteLocation::query()->whereIn('type', ['pickup', 'dropoff'])->count())->toBe($points)
        ->and(User::query()->where('phone_e164', '+201055559901')->count())->toBe(1);
});

it('refuses to add match states in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:match-states')->assertFailed();
});

it('seeds every Driver Home state through the real flows, and resets them when run again', function () {
    $this->artisan('demo:driver-home-states')->assertSuccessful();
    $this->artisan('demo:driver-home-states')->assertSuccessful();

    $home = function (string $state): array {
        $driver = User::query()->where('phone_e164', DriverHomeStatesSeeder::STATES[$state][0])->sole();

        return $this->actingAs($driver, 'sanctum')->getJson('/api/v1/driver/home')->assertOk()->json('data');
    };

    expect($home('F3D-01')['nextRun']['phase'])->toBe('scheduled')
        ->and($home('F3D-01')['nextRun']['canStart'])->toBeFalse()
        ->and($home('F3D-01')['verification']['completed'])->toBe(5)
        ->and($home('F3D-01-CAN-START')['nextRun']['canStart'])->toBeTrue()
        ->and($home('F3D-02')['nextRun']['phase'])->toBe('starting_soon')
        ->and($home('F3D-03-PREPARING')['nextRun']['status'])->toBe('PREPARING')
        ->and($home('F3D-03-EN-ROUTE')['nextRun']['status'])->toBe('EN_ROUTE')
        ->and($home('F3D-03-IN-PROGRESS')['nextRun']['status'])->toBe('IN_PROGRESS')
        ->and($home('F3D-04')['nextRun']['phase'])->toBe('not_started')
        ->and($home('F3D-05')['nextRun']['phase'])->toBe('late')
        ->and($home('F3D-06')['expiredRun'])->not->toBeNull()
        ->and($home('F3D-06')['nextRun']['promisedArrivalLocal'])->not->toBeNull()
        ->and($home('NO-RUN-ACTIVITY')['nextRun'])->toBeNull()
        ->and($home('NO-RUN-ACTIVITY')['pausedCommutes'])->toBe(1)
        ->and($home('NO-RUN-ACTIVITY')['waitlist']['count'])->toBe(2)
        ->and($home('NO-RUN-ACTIVITY')['pendingRequests']['count'])->toBe(1)
        ->and($home('NO-RUN-ACTIVITY')['notices'])->toHaveCount(1)
        ->and($home('F3D-07')['notices'][0]['kind'])->toBe('seat_opened')
        ->and($home('F3D-08-REJOINED')['notices'][0]['rejoinedWaitlist'])->toBeTrue()
        ->and($home('F3D-08-LEFT')['notices'][0]['rejoinedWaitlist'])->toBeFalse()
        ->and($home('NO-PROMISE')['nextRun']['promisedArrivalLocal'])->toBeNull()
        ->and(collect($home('LATER-WAITLIST')['laterThisWeek'])->pluck('waitlistCount')->unique()->sort()->values()->all())->toBe([0, 1])
        ->and($home('SAME-PERSON-TWO-DAYS')['waitlist']['count'])->toBe(1)
        ->and($home('NO-COMMUTES')['nextRun'])->toBeNull()
        ->and($home('NO-COMMUTES')['verification']['overall'])->toBe('verified')
        ->and($home('NO-COMMUTES')['verification']['completed'])->toBe(5)
        ->and($home('NO-COMMUTES')['verification']['licenceExpiresAt'])->not->toBeNull()
        ->and($home('NOT-STARTED')['verification']['overall'])->toBe('not_started')
        ->and($home('IN-PROGRESS')['verification']['overall'])->toBe('in_progress')
        ->and($home('COMPLETE-DRAFT')['verification']['overall'])->toBe('in_progress')
        ->and($home('UNDER-REVIEW')['verification']['overall'])->toBe('under_review')
        ->and($home('VERIFIED')['verification']['overall'])->toBe('verified')
        ->and($home('EXPIRING')['verification']['overall'])->toBe('expiring')
        ->and($home('RENEWAL-LICENCE')['verification']['actionItem']['key'])->toBe('driving_licence')
        ->and($home('RENEWAL-LICENCE')['nextRun'])->toBeNull()
        ->and($home('RENEWAL-REGISTRATION')['verification']['actionItem']['key'])->toBe('vehicle_registration')
        ->and($home('REJECTED-LICENCE')['verification']['actionItem']['key'])->toBe('driving_licence')
        ->and($home('REJECTED-REGISTRATION')['verification']['actionItem']['key'])->toBe('vehicle_registration')
        ->and($home('REJECTED-DETAILS')['verification']['actionItem']['key'])->toBe('vehicle_details')
        ->and($home('REJECTED-APPLICATION')['verification']['actionItem']['key'])->toBe('application')
        ->and($home('SUSPENDED')['verification']['suspensionReason'])->toBe('Repeated no-show reports.')
        ->and($home('SUSPENDED')['nextRun'])->toBeNull();

    $this->artisan('demo:driver-home-states', ['--state' => 'F3D-05', '--phone' => '+201055557005'])->assertSuccessful();
    $this->artisan('demo:driver-home-states', ['--state' => 'F3D-05', '--phone' => '+201055557001'])->assertFailed();
    $this->artisan('demo:driver-home-states', ['--state' => 'NOPE'])->assertFailed();
});
