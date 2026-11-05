<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use Database\Seeders\CreateCommuteStatesSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DriverHomeStatesSeeder;
use Database\Seeders\DriverRequestsStatesSeeder;

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

it('seeds every Driver Requests state and resets them when run again', function () {
    $this->artisan('demo:driver-requests-states')->assertSuccessful();
    $this->artisan('demo:driver-requests-states')->assertSuccessful();

    $as = fn (string $state) => $this->actingAs(
        User::query()->where('phone_e164', DriverRequestsStatesSeeder::REQUEST_STATES[$state])->sole(),
        'sanctum',
    );

    $inbox = fn (string $state) => $as($state)->getJson('/api/v1/driver/seat-requests')->assertOk()->json('data');
    $waiting = fn (string $state) => $as($state)->getJson('/api/v1/driver/waitlist')->assertOk()->json();
    $offers = fn (string $state) => $as($state)->getJson('/api/v1/driver/seat-offers')->assertOk()->json('data');

    $rows = collect($inbox('REQ-INBOX'));

    expect($inbox('REQ-EMPTY'))->toBe([])
        ->and($rows->pluck('kind')->unique()->sort()->values()->all())->toBe(['seat_request', 'waitlist_application'])
        ->and($rows->pluck('hasOtherConfirmedCommute')->unique()->count())->toBe(2)
        ->and($rows->pluck('impact.canAccept')->unique()->all())->toBe([true])
        ->and(collect($inbox('REQ-DECIDED'))->pluck('status')->unique()->count())->toBe(11)
        ->and($inbox('REQ-BREAKS-PROMISE')[0]['impact']['canAccept'])->toBeFalse()
        ->and($inbox('REQ-OVER-LIMIT')[0]['impact']['withinLimit'])->toBeFalse()
        ->and($waiting('WL-DAYS')['meta']['total'])->toBe(3)
        ->and(collect($waiting('WL-DAYS')['meta']['days'])->pluck('isFull')->contains(true))->toBeTrue()
        ->and($offers('WL-OFFER-OPEN')[0]['status'])->toBe('OPEN')
        ->and($offers('WL-OFFER-ACCEPTED')[0]['acceptedBy']['publicFirstName'])->toBe('Laila');
});

it('refuses to add Driver Requests states in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:driver-requests-states')->assertFailed();
});

it('seeds every Create Commute state and resets them when run again', function () {
    $this->artisan('demo:create-commute-states')->assertSuccessful();
    $this->artisan('demo:create-commute-states')->assertSuccessful();

    $as = fn (string $state) => $this->actingAs(
        User::query()->where('phone_e164', CreateCommuteStatesSeeder::CREATE_STATES[$state])->sole(),
        'sanctum',
    );

    $draft = fn (string $state) => $as($state)->getJson('/api/v1/commutes?status=draft')->assertOk()->json('data.0.id');
    $publish = fn (string $state) => $as($state)->postJson('/api/v1/commutes/'.$draft($state).'/publish')->json('error.code');

    expect($as('CC-UNVERIFIED')->getJson('/api/v1/commutes')->assertOk()->json('data'))->toBe([])
        ->and($as('CC-NO-CAR')->getJson('/api/v1/driver/vehicles')->assertOk()->json('data'))->toBe([])
        ->and($publish('CC-CAR-NOT-REVIEWED'))->toBe('VEHICLE_SEATS_NOT_APPROVED')
        ->and($publish('CC-CAR-REJECTED'))->toBe('COMMUTE_VEHICLE_UNAVAILABLE')
        ->and($publish('CC-CAR-PENDING'))->toBe('COMMUTE_VEHICLE_UNAVAILABLE')
        ->and($publish('CC-OVERDUE'))->toBe('DRIVER_COMMISSION_OVERDUE')
        ->and($publish('CC-SUSPENDED'))->toBe('DRIVER_NOT_ELIGIBLE')
        ->and($publish('CC-LICENCE-EXPIRED'))->toBe('DRIVER_LICENCE_EXPIRED')
        ->and(Vehicle::query()->whereHas('driverProfile.user', fn ($user) => $user->where('phone_e164', '+201055557410'))->sole()->has_ac)->toBeFalse()
        ->and(User::query()->where('phone_e164', '+201055557411')->sole()->gender)->toBe(Gender::Man)
        ->and($publish('CC-READY'))->toBeNull();
});

it('refuses to add Create Commute states in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:create-commute-states')->assertFailed();
});
