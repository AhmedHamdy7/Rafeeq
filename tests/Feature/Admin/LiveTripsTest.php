<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Admin\Support\LiveTripBoard;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\LiveLocationStore;
use App\Domains\Trip\ValueObjects\TripPosition;
use App\Livewire\Admin\LiveTrips;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The live board (Phase 13, the LIVE TRIPS section).
 *
 * 🔴 Most of what is worth testing here is ORDER. A board that lists every car correctly and
 * puts the one with a live SOS on page three has failed at the only thing it is for.
 */
beforeEach(function () {
    $this->ops = adminWithRole(AdminRole::Operations);
});

/**
 * A run on the road, heard from just now unless the test says otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function runOnTheRoad(array $attributes = [], bool $womenOnly = true): TripSession
{
    $offer = $womenOnly
        ? CommuteOffer::factory()->published()->create()
        : CommuteOffer::factory()->published()->anyVerified()->create();

    $trip = ScheduledTrip::factory()->create(['commute_offer_id' => $offer->id]);

    return TripSession::factory()->inProgress()->create(['scheduled_trip_id' => $trip->id] + $attributes);
}

/**
 * The short reference the board prints for a run.
 */
function boardRef(TripSession $session): string
{
    return Str::upper(Str::substr($session->id, -6));
}

it('lists the runs that are on the road and none that have finished', function () {
    $live = runOnTheRoad();
    $done = runOnTheRoad(['current_status' => TripSessionStatus::Completed->value]);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->assertOk()
        ->assertSee(boardRef($live))
        ->assertDontSee(boardRef($done));
});

/**
 * An interrupted run stays in `emergency` until somebody decides what happened. Dropping it
 * from the board would hide the one run that most needs a person.
 */
it('keeps an emergency run on the board', function () {
    $emergency = runOnTheRoad(['current_status' => TripSessionStatus::Emergency->value]);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->assertSee(boardRef($emergency))
        ->assertSee(__('admin.trips.flags.emergency'));
});

it('puts a car with a live SOS above everything else', function () {
    $quiet = runOnTheRoad();
    $offRoute = runOnTheRoad(['deviation_detected_at' => now(), 'deviation_distance_meters' => 2400]);
    $alarmed = runOnTheRoad();

    SosEvent::factory()->create([
        'safety_event_id' => SafetyEvent::factory()->create(['trip_session_id' => $alarmed->id])->id,
    ]);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->assertSeeInOrder([boardRef($alarmed), boardRef($offRoute), boardRef($quiet)])
        ->assertSee(__('admin.trips.flags.alert'))
        ->assertSee(__('admin.trips.flags.deviation', ['meters' => '2,400']));
});

it('stops treating a car as alarmed once its SOS is closed', function () {
    $session = runOnTheRoad();

    SosEvent::factory()->resolved()->create([
        'safety_event_id' => SafetyEvent::factory()->create(['trip_session_id' => $session->id])->id,
    ]);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)->assertDontSee(__('admin.trips.flags.alert'));
});

it('flags a car whose phone has gone quiet', function () {
    $silent = runOnTheRoad(['last_location_at' => now()->subMinutes(5)]);
    $heard = runOnTheRoad(['last_location_at' => now()->subSeconds(10)]);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->assertSeeInOrder([boardRef($silent), boardRef($heard)])
        ->assertSee(__('admin.trips.flags.silent'));
});

/**
 * A run that set off ten minutes ago and has never sent a single point is the most silent of
 * all, not the least.
 */
it('counts a run that never sent a position as silent', function () {
    $session = runOnTheRoad(['last_location_at' => null, 'departed_at' => now()->subMinutes(10)]);

    expect(LiveTripBoard::isSilent($session))->toBeTrue();
});

it('does not call a run silent before it has set off', function () {
    $session = runOnTheRoad([
        'current_status' => TripSessionStatus::Preparing->value,
        'last_location_at' => null,
        'started_at' => now()->subMinutes(30),
    ]);

    expect(LiveTripBoard::isSilent($session))->toBeFalse();
});

it('takes the silence threshold from settings', function () {
    $session = runOnTheRoad(['last_location_at' => now()->subSeconds(90)]);

    expect(LiveTripBoard::isSilent($session))->toBeFalse();

    PlatformSetting::updateOrCreate(
        ['setting_key' => 'trip.gps_silence_alert_seconds'],
        ['setting_value' => 60, 'value_type' => 'integer'],
    );

    expect(LiveTripBoard::isSilent($session->refresh()))->toBeTrue();
});

/**
 * The flagged filter is SQL and the badge is PHP. Two places, one rule — so they are checked
 * against each other on the same rows.
 */
it('agrees with itself about which cars are flagged', function () {
    $silent = runOnTheRoad(['last_location_at' => now()->subMinutes(5)]);
    $offRoute = runOnTheRoad(['deviation_detected_at' => now()]);
    $quiet = runOnTheRoad();
    $waiting = runOnTheRoad([
        'current_status' => TripSessionStatus::Preparing->value,
        'last_location_at' => null,
        'started_at' => now()->subMinutes(30),
    ]);

    $flagged = LiveTripBoard::page(25, flaggedOnly: true)->getCollection()->pluck('id')->all();

    expect($flagged)->toEqualCanonicalizing([$silent->id, $offRoute->id]);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->set('flaggedOnly', true)
        ->assertSee(boardRef($silent))
        ->assertDontSee(boardRef($quiet))
        ->assertDontSee(boardRef($waiting));
});

it('narrows the board to women-only runs', function () {
    $women = runOnTheRoad();
    $mixed = runOnTheRoad(womenOnly: false);

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->set('womenOnly', true)
        ->assertSee(boardRef($women))
        ->assertDontSee(boardRef($mixed));
});

it('shows where a tracked car was last heard from, and who is on it by first name only', function () {
    $session = runOnTheRoad();
    $booking = Booking::factory()->create([
        'scheduled_trip_id' => $session->scheduled_trip_id,
        'status' => BookingStatus::Confirmed->value,
    ]);
    // Pinned rather than faked: a random first name could also appear somewhere else on the page.
    $rider = $booking->passenger;
    $rider->forceFill(['full_name' => 'Mariam Hassan Abdelaziz', 'public_first_name' => 'Mariam'])->save();

    app(LiveLocationStore::class)->putCurrent($session->id, new TripPosition(
        lat: 30.06543,
        lng: 31.23141,
        recordedAt: now()->subSeconds(20),
    ));

    actingAsAdmin($this->ops);

    Livewire::test(LiveTrips::class)
        ->call('track', $session->id)
        ->assertSee('30.06543, 31.23141')
        ->assertSee($rider->public_first_name)
        // 🔒 The board is about the car, not a directory of the people in it.
        ->assertDontSee($rider->phone_e164)
        ->assertDontSee($rider->full_name);
});

it('lets operations and the safety desk see the board, and nobody else', function () {
    runOnTheRoad();

    foreach ([AdminRole::Operations, AdminRole::SafetyLead, AdminRole::SuperAdmin] as $role) {
        actingAsAdmin(adminWithRole($role));
        Livewire::test(LiveTrips::class)->assertOk();
    }

    foreach ([AdminRole::Verification, AdminRole::Finance, AdminRole::Support] as $role) {
        actingAsAdmin(adminWithRole($role));
        Livewire::test(LiveTrips::class)->assertForbidden();
    }
});
