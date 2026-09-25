<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 4's state machine and edge cases.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = approvedDriver();
    $this->vehicleId = Vehicle::sole()->id;
    $this->commuteId = readyCommute($this->token, $this->vehicleId);

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();
});

/**
 * Chapter 4: "paused commutes keep history". Pausing must not cancel days people
 * have already booked — a driver pausing for a week and resuming should find
 * their group intact.
 */
it('pauses without touching the days already generated', function () {
    $before = ScheduledTrip::count();

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'PAUSED')
        ->assertJsonPath('data.pausedReason', 'by_driver');

    expect(ScheduledTrip::count())->toBe($before)
        ->and(ScheduledTrip::where('status', ScheduledTripStatus::Cancelled->value)->count())->toBe(0);
});

it('stops generating new days while paused', function () {
    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    $before = ScheduledTrip::count();

    $this->travel(5)->days();
    $this->artisan('commutes:generate-trips')->assertSuccessful();

    expect(ScheduledTrip::count())->toBe($before);
});

it('catches up on the missed days when resumed', function () {
    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    $before = ScheduledTrip::count();

    $this->travel(5)->days();

    // The access token is long dead after five days — 15 minutes by design. The
    // driver signing in again is what they would actually do.
    fakeOtpSender();
    $token = signIn(devicePublicId: 'dev-1')['session']['accessToken'];

    $this->withToken($token)->postJson("/api/v1/commutes/{$this->commuteId}/resume")
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED')
        ->assertJsonPath('data.pausedReason', null);

    expect(ScheduledTrip::count())->toBeGreaterThan($before);
});

/**
 * A commute paused because the vehicle was suspended must not come back just
 * because the driver asked.
 */
it('refuses to resume while the vehicle is still unusable', function () {
    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    Vehicle::sole()->forceFill([
        'verification_status' => VehicleVerificationStatus::Suspended->value,
    ])->save();

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/resume")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_VEHICLE_UNAVAILABLE');

    expect(CommuteOffer::sole()->status)->toBe(CommuteOfferStatus::Paused);
});

/**
 * Chapter 4: "archived commutes cannot be booked".
 */
it('archives and cancels the future days nobody has taken', function () {
    $this->withToken($this->token)->deleteJson("/api/v1/commutes/{$this->commuteId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ARCHIVED');

    $future = ScheduledTrip::where('departure_at', '>', now())->get();

    expect($future)->not->toBeEmpty();

    foreach ($future as $trip) {
        expect($trip->status)->toBe(ScheduledTripStatus::Cancelled);
    }
});

/**
 * A day with passengers on it is a commitment. Voiding it silently would leave
 * people expecting a lift that will not come, so it needs the deliberate
 * cancellation — with refunds and notifications — that later phases own.
 */
it('leaves a booked day alone when archiving', function () {
    $booked = ScheduledTrip::orderBy('trip_date')->first();
    $booked->forceFill(['seats_taken' => 1])->save();

    $this->withToken($this->token)->deleteJson("/api/v1/commutes/{$this->commuteId}")->assertOk();

    expect($booked->refresh()->status)->toBe(ScheduledTripStatus::Scheduled);
});

it('allows nothing once archived', function () {
    $this->withToken($this->token)->deleteJson("/api/v1/commutes/{$this->commuteId}")->assertOk();

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertStatus(409);
    $this->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/resume")->assertStatus(409);
    $this->withToken($this->token)->patchJson("/api/v1/commutes/{$this->commuteId}", [
        'pricePerSeatPiastres' => 9000,
    ])->assertStatus(409);
});

/**
 * Chapter 4's edge case: "vehicle suspended after publishing → pause commute
 * automatically".
 */
it('pauses a driver published commutes when they are suspended', function () {
    app(ReviewDriverApplicationAction::class)->suspend(
        DriverProfile::sole(),
        AdminUser::factory()->create(),
        'Safety report under investigation.',
    );

    $paused = app(ChangeCommuteStatusAction::class)->pauseUnpublishableOffers(
        DriverProfile::sole(),
        CommutePausedReason::VehicleSuspended,
    );

    expect($paused)->toBe(1)
        ->and(CommuteOffer::sole()->status)->toBe(CommuteOfferStatus::Paused)
        ->and(CommuteOffer::sole()->paused_reason)->toBe(CommutePausedReason::VehicleSuspended);
});

/**
 * Chapter 4 §5: if seats drop below what is already booked, the change is blocked
 * until the conflict is resolved.
 */
it('refuses to cut seats below what is already booked', function () {
    ScheduledTrip::orderBy('trip_date')->first()->forceFill(['seats_taken' => 3])->save();

    $this->withToken($this->token)->patchJson("/api/v1/commutes/{$this->commuteId}", ['seatsTotal' => 2])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_SEATS_CONFLICT');

    expect(CommuteOffer::sole()->seats_total)->toBe(3);
});

it('allows a seat change that still fits the passengers already booked', function () {
    ScheduledTrip::orderBy('trip_date')->first()->forceFill(['seats_taken' => 2])->save();

    $this->withToken($this->token)->patchJson("/api/v1/commutes/{$this->commuteId}", ['seatsTotal' => 2])
        ->assertOk()
        ->assertJsonPath('data.seatsTotal', 2);
});

/**
 * A corridor is how supply and demand are reasoned about in aggregate — the
 * health statuses and escort windows of later phases hang off it. Matching is
 * loose on geography and strict on time: two people leaving the same gate ten
 * minutes apart share a corridor, twelve hours apart do not.
 */
it('attaches a published commute to a matching corridor', function () {
    $origin = Place::factory()->create(['lat' => 30.0594, 'lng' => 31.4913, 'point' => new Coordinate(30.0594, 31.4913)]);
    $destination = Place::factory()->create(['lat' => 30.0714, 'lng' => 30.9716, 'point' => new Coordinate(30.0714, 30.9716)]);

    $corridor = Corridor::factory()->create([
        'origin_place_id' => $origin->id,
        'destination_place_id' => $destination->id,
        'window_start' => '06:45:00',
        'window_end' => '08:00:00',
        'days_mask' => DaysMask::weekdaysSunToThu()->value,
    ]);

    $second = readyCommute($this->token, $this->vehicleId);

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$second}/publish")
        ->assertOk()
        ->assertJsonPath('data.corridorId', $corridor->id);
});

it('leaves the corridor unset when the departure falls outside its window', function () {
    $origin = Place::factory()->create(['lat' => 30.0594, 'lng' => 31.4913, 'point' => new Coordinate(30.0594, 31.4913)]);
    $destination = Place::factory()->create(['lat' => 30.0714, 'lng' => 30.9716, 'point' => new Coordinate(30.0714, 30.9716)]);

    Corridor::factory()->create([
        'origin_place_id' => $origin->id,
        'destination_place_id' => $destination->id,
        // The commute leaves at 07:05, hours before this window opens.
        'window_start' => '17:00:00',
        'window_end' => '19:00:00',
        'days_mask' => DaysMask::weekdaysSunToThu()->value,
    ]);

    $second = readyCommute($this->token, $this->vehicleId);

    // A journey nobody has named a corridor for is still a perfectly good
    // commute, so a miss must never block publishing.
    $this->withToken($this->token)->postJson("/api/v1/commutes/{$second}/publish")
        ->assertOk()
        ->assertJsonPath('data.corridorId', null);
});

it('lists the driver own commutes, newest first', function () {
    // A second apart, so "newest" is a real difference rather than a tie the
    // database breaks however it likes.
    $this->travel(1)->second();

    $second = readyCommute($this->token, $this->vehicleId);

    $listed = $this->withToken($this->token)->getJson('/api/v1/commutes')->assertOk()->json('data');

    expect($listed)->toHaveCount(2)
        ->and($listed[0]['id'])->toBe($second);
});

it('searches the shared place catalogue by name in either script', function () {
    Place::factory()->create([
        'name' => 'Smart Village',
        'name_ar' => 'القرية الذكية',
        'lat' => 30.0714, 'lng' => 30.9716,
        'point' => new Coordinate(30.0714, 30.9716),
    ]);

    $this->withToken($this->token)->getJson('/api/v1/places?q=Smart')
        ->assertOk()->assertJsonPath('data.0.name', 'Smart Village');

    $this->withToken($this->token)->getJson('/api/v1/places?q='.urlencode('الذكية'))
        ->assertOk()->assertJsonPath('data.0.nameAr', 'القرية الذكية');
});

it('finds places near a point and orders them by real distance', function () {
    $near = Place::factory()->create([
        'name' => 'Rehab Gate 2',
        'lat' => 30.0600, 'lng' => 31.4920,
        'point' => new Coordinate(30.0600, 31.4920),
    ]);

    $farther = Place::factory()->create([
        'name' => 'Rehab Gate 8',
        'lat' => 30.0700, 'lng' => 31.5100,
        'point' => new Coordinate(30.0700, 31.5100),
    ]);

    Place::factory()->create([
        'name' => 'Alexandria Corniche',
        'lat' => 31.2001, 'lng' => 29.9187,
        'point' => new Coordinate(31.2001, 29.9187),
    ]);

    $found = $this->withToken($this->token)
        ->getJson('/api/v1/places?lat=30.0594&lng=31.4913&radiusMetres=5000')
        ->assertOk()->json('data');

    expect(collect($found)->pluck('id')->all())->toBe([$near->id, $farther->id]);
});

it('requires both coordinates together when searching near a point', function () {
    $this->withToken($this->token)->getJson('/api/v1/places?lat=30.05')->assertStatus(422);
});
