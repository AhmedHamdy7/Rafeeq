<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Rating\Models\Rating;
use App\Domains\Trip\Actions\AutoFinishTripsAction;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

/**
 * The three ends of a shared journey (Feature 03 v5's trip-lifecycle section).
 *
 * > *"`/driver/bookings/{id}/dropped-off` — Complete that booking; rating enabled."*
 * > *"`/bookings/{id}/end-early` — Passenger safety exit, immediate location-access revocation."*
 * > *"+15m past promised arrival show still-driving/finish, +5m auto-finish only if no
 * > onboard/awaiting passengers. Still Driving extends next check by 15m."*
 *
 * 🔴 All three exist because a commute is a SHARED journey and the system was treating it as one
 * event. Everybody was checked out at the same instant, so a woman set down at the start of the
 * Mehwar could not rate her ride for the twenty minutes it took the driver to reach the last stop;
 * a woman who wanted out of a car had only "cancel", which records a journey she never took; and a
 * driver who forgot to tap "complete" left a run open with somebody's live location in it for ever.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 3], [
        'promisedArrivalTime' => '08:00:00',
    ]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

/**
 * A rider seated, and the run out on the road with her aboard.
 *
 * @return array{token: string, bookingId: string}
 */
function aboard(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);

    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    return ['token' => $token, 'bookingId' => approveSeat($driverToken, $requestId)];
}

/**
 * The run out on the road with one named passenger aboard.
 *
 * Built on the shared `underway()` helper rather than hand-rolling the status sequence, so this
 * file cannot drift from the rest of the trip tests about what starting a run means.
 */
function onTheRoad(string $driverToken, string $tripId, string $bookingId): void
{
    underway($driverToken, $tripId);

    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'AT_PICKUP', 'bookingId' => $bookingId, ...atPickupOf($bookingId)])
        ->assertOk();

    checkIn($driverToken, $tripId, ['bookingId' => $bookingId, ...position()])->assertOk();

    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
}

/**
 * 🔴 The whole point of a per-passenger drop-off: her booking completes, so she can rate the ride
 * she has actually finished, while the driver carries on to the next stop.
 */
it('completes one passenger’s booking and lets her rate, mid-run', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $other = aboard($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $other['bookingId'], ...position()])->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $her['bookingId']])
        ->assertOk();

    expect(Booking::findOrFail($her['bookingId'])->status)->toBe(BookingStatus::Completed)
        ->and(Attendance::query()->whereKey($her['bookingId'])->sole()->checked_out_at)->not->toBeNull()
        // 🔴 The run is NOT over: the other passenger is still in the car.
        ->and(TripSession::sole()->current_status)->toBe(TripSessionStatus::InProgress)
        ->and(Booking::findOrFail($other['bookingId'])->status)->toBe(BookingStatus::Confirmed);

    // And she can rate it now rather than when somebody else's journey ends.
    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/bookings/{$her['bookingId']}/rating", ['stars' => 5])
        ->assertStatus(201);

    expect(Rating::query()->count())->toBe(1);
});

/**
 * The stop stops being somewhere the driver has to go. v5 §3: the live itinerary reflects
 * "remaining actionable stops ... not completed stops".
 */
it('marks the drop-off stop reached so the live route moves on', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    // Build the itinerary before the run, as the driver's screen would.
    test()->flushHeaders()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->tripId}/navigation-route")->assertOk();

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $her['bookingId']])
        ->assertOk();

    expect(NavigationStop::query()
        ->where('booking_id', $her['bookingId'])
        ->where('kind', 'passenger_dropoff')
        ->sole()
        ->reached_at)->not->toBeNull();
});

/**
 * 🔒 Only somebody who was actually in the car. A `pending` row means nobody recorded whether she
 * travelled, and setting her down would invent a journey — the same class of mistake as turning
 * silence into a no-show.
 */
it('refuses to set down somebody nobody picked up', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $never = aboard($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $never['bookingId']])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_NOT_CONFIRMABLE');

    expect(Booking::findOrFail($never['bookingId'])->status)->toBe(BookingStatus::Confirmed);
});

it('is idempotent, because a second tap on a moving car changes nothing', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    foreach ([1, 2] as $ignored) {
        test()->flushHeaders()->withToken($this->driverToken)
            ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $her['bookingId']])
            ->assertOk();
    }

    expect(Booking::findOrFail($her['bookingId'])->status)->toBe(BookingStatus::Completed);
});

/**
 * 🔴 The safety exit. Her booking becomes `ended_early` — neither a completion, which would say she
 * was carried where she agreed, nor a cancellation, which would say she never travelled and would
 * land on the history a future driver reads.
 */
it('lets a passenger end her journey early, with no fee and no reason required', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/bookings/{$her['bookingId']}/end-early")
        ->assertOk()
        ->assertJsonPath('data.status', 'ENDED_EARLY');

    $booking = Booking::findOrFail($her['bookingId']);

    expect($booking->status)->toBe(BookingStatus::EndedEarly)
        ->and($booking->cancellation_fee_piastres)->toBe(0)
        ->and(Attendance::query()->whereKey($her['bookingId'])->sole()->checked_out_at)->not->toBeNull()
        // The run carries on without her.
        ->and(TripSession::sole()->current_status)->toBe(TripSessionStatus::InProgress);
});

/**
 * 🔒 v5: "immediate location-access revocation". Most of it is structural — the car's position is
 * only readable on a pending or confirmed booking — which matters because for most commutes where
 * the car goes next is the driver's street.
 */
it('cuts her off from the car’s live position the moment she leaves', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    // While she is aboard she may watch it.
    test()->flushHeaders()->withToken($her['token'])
        ->getJson("/api/v1/trips/{$this->tripId}/location")->assertOk();

    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/bookings/{$her['bookingId']}/end-early")->assertOk();

    // And afterwards she may not — a 404, as for any run that is not hers to watch.
    test()->flushHeaders()->withToken($her['token'])
        ->getJson("/api/v1/trips/{$this->tripId}/location")->assertStatus(404);
});

/**
 * 🔒 The driver cannot use it to put somebody out of her car. That is Cancel Active Trip, with a
 * reason and a safe stop — and recording it here would log her decision as the passenger's own exit.
 */
it('refuses to let the driver end a passenger’s journey through this route', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/bookings/{$her['bookingId']}/end-early")
        ->assertStatus(404);

    expect(Booking::findOrFail($her['bookingId'])->status)->toBe(BookingStatus::Confirmed);
});

/**
 * Before a pickup there is nothing to leave, and v5 is explicit: "Passenger pre-pickup cancel
 * anytime; after pickup end early." The refusal names where she actually is.
 */
it('sends a passenger who has not been picked up to cancel instead', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $other = aboard($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    onTheRoad($this->driverToken, $this->tripId, $other['bookingId']);

    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/bookings/{$her['bookingId']}/end-early")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_NOT_CONFIRMABLE');
});

/**
 * 🔴 She rode part of the way, so she has something to say about it — and a safety exit is the
 * review it matters most to allow.
 */
it('lets her rate the part of the journey she did travel', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/bookings/{$her['bookingId']}/end-early")->assertOk();

    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/bookings/{$her['bookingId']}/rating", ['stars' => 1, 'comment' => 'I got out.'])
        ->assertStatus(201);
});

/**
 * "Still driving" pushes the next look back, as often as the road needs.
 */
it('extends the auto-finish check every time she says she is still driving', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    // Due now, as if the promised arrival had long passed.
    TripSession::query()->update(['auto_finish_check_at' => now()->subMinutes(5)]);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/still-driving")->assertOk();

    $session = TripSession::sole();

    expect($session->still_driving_count)->toBe(1)
        ->and($session->auto_finish_check_at->isFuture())->toBeTrue();

    // And again, because a woman in Cairo traffic is not more suspicious the second time.
    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/still-driving")->assertOk();

    expect(TripSession::sole()->still_driving_count)->toBe(2);
});

/**
 * 🔴 The condition that makes auto-finish safe: nobody aboard, nobody awaiting. Ending a run with
 * somebody in the car would cut her live location while she is moving.
 */
it('refuses to close a run with somebody still in the car', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    TripSession::query()->update(['auto_finish_check_at' => now()->subMinute()]);

    expect(app(AutoFinishTripsAction::class)->sweep())->toBe(0)
        ->and(TripSession::sole()->current_status)->toBe(TripSessionStatus::InProgress);
});

/**
 * 🔴 And refuses on a `pending` row too. Nobody decided whether she travelled, and a sweep that
 * closed over it would freeze that non-decision for ever on somebody who may have been standing on
 * a kerb.
 */
it('refuses to close a run with somebody still awaiting a decision', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    $waiting = aboard($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    // Her own journey is finished; the second passenger was never decided either way.
    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $her['bookingId']])->assertOk();

    TripSession::query()->update(['auto_finish_check_at' => now()->subMinute()]);

    expect(app(AutoFinishTripsAction::class)->sweep())->toBe(0)
        ->and(Attendance::query()->whereKey($waiting['bookingId'])->sole()->status->value)->toBe('pending');
});

/**
 * And it DOES close the run the driver simply forgot, which is the only thing it exists for.
 */
it('closes a run whose passengers are all resolved', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $her['bookingId']])->assertOk();

    TripSession::query()->update(['auto_finish_check_at' => now()->subMinute()]);

    expect(app(AutoFinishTripsAction::class)->sweep())->toBe(1)
        ->and(TripSession::sole()->current_status)->toBe(TripSessionStatus::Completed);
});

/**
 * A run that is not yet due is left alone, however many people have got out.
 */
it('leaves a run alone until its check falls due', function () {
    $her = aboard($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    onTheRoad($this->driverToken, $this->tripId, $her['bookingId']);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $her['bookingId']])->assertOk();

    TripSession::query()->update(['auto_finish_check_at' => now()->addHour()]);

    expect(app(AutoFinishTripsAction::class)->sweep())->toBe(0)
        ->and(TripSession::sole()->current_status)->toBe(TripSessionStatus::InProgress);
});
