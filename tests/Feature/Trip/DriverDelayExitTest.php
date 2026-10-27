<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Illuminate\Support\Facades\Storage;

/**
 * Walking away from a journey the driver never came for (Feature 03 v5 §241).
 *
 * > *"Passenger can exit without fee if driver hasn't started by departure or missed agreed
 * > pickup."*
 *
 * 🔴 She could always cancel — the button existed. What it cost her was a cancellation fee, a line
 * in the cancellation history a future driver reads, and a mark on her reliability record, for a
 * morning somebody else ruined. Every test here is about those three landing on the right person.
 *
 * 🔒 And about the entitlement being the SERVER's finding. A client that could assert "my driver
 * was late" would have a free cancellation for the asking, paid for by a driver who was on time.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $this->bookingId = approveSeat(
        $this->driverToken,
        requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
            ->assertStatus(201)->json('data.id'),
    );

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

function exitWithoutFee(string $token, string $bookingId, array $body = [])
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/bookings/{$bookingId}/exit-without-fee", $body);
}

/**
 * Gets the run out on the road WITHOUT moving departure, which `underway()` in Pest.php does — and
 * every test here is about a departure that has already passed.
 */
function runStartedLate(string $driverToken, string $tripId): void
{
    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/start")->assertStatus(201);

    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'EN_ROUTE'])->assertOk();
}

/*
|--------------------------------------------------------------------------
| The driver never set off
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The whole feature in one test: her booking ends, it costs her nothing, and nothing about it
 * reads as her decision.
 */
it('lets her leave for free once departure has passed with no run', function () {
    runLeavingIn($this->tripId, -1);

    exitWithoutFee($this->paxToken, $this->bookingId)
        ->assertOk()
        /*
         * Its own status. `cancelled_by_passenger` would charge her and put a cancellation on the
         * record a future driver reads; `cancelled_by_driver` would report a cancellation that
         * never happened.
         */
        ->assertJsonPath('data.status', 'EXITED_DUE_TO_DRIVER_DELAY')
        ->assertJsonPath('data.cancellationFeePiastres', 0)
        // The server's finding, recorded as the reason.
        ->assertJsonPath('data.cancelledReason', 'not_started');

    $booking = Booking::query()->whereKey($this->bookingId)->sole();

    expect($booking->status)->toBe(BookingStatus::ExitedDueToDriverDelay)
        ->and((int) $booking->cancellation_fee_piastres)->toBe(0);
});

/**
 * ⚠️ No grace period, deliberately. A tolerance here would be the platform deciding how long a
 * woman should stand in the street — and nothing happens automatically, it only becomes HER option.
 */
it('is not available one minute before departure', function () {
    runLeavingIn($this->tripId, 1);

    exitWithoutFee($this->paxToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.driverDelay.0', 'NOT_DELAYED');

    expect(Booking::query()->whereKey($this->bookingId)->sole()->status)
        ->toBe(BookingStatus::Confirmed);
});

/**
 * 🔒 And the entitlement cannot be claimed. A body asserting lateness changes nothing — the server
 * reads the clock and the run.
 */
it('ignores a client that simply claims the driver is late', function () {
    runLeavingIn($this->tripId, 30);

    exitWithoutFee($this->paxToken, $this->bookingId, [
        'delayed' => true,
        'driverDelay' => 'not_started',
        'reason' => 'she is late, honestly',
    ])->assertStatus(409);

    expect(Booking::query()->whereKey($this->bookingId)->sole()->status)
        ->toBe(BookingStatus::Confirmed);
});

/**
 * Once the car is moving the question stops being "has she set off" — it becomes whether the car
 * reaches her, which is the other condition.
 */
it('is not available merely because the driver set off late', function () {
    runLeavingIn($this->tripId, -20);

    runStartedLate($this->driverToken, $this->tripId);

    exitWithoutFee($this->paxToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.driverDelay.0', 'NOT_DELAYED');
});

/*
|--------------------------------------------------------------------------
| The car drove past her
|--------------------------------------------------------------------------
*/

/**
 * 🔴 v5 says "missed agreed pickup", and nothing in this system stores an agreed pickup TIME — only
 * a place. So the question asked is the one the data can answer honestly: has the car driven past
 * her? A stop later in the route reached while hers is not. That is a record of being skipped, not
 * a prediction about when she would have been collected.
 */
it('lets her leave for free when the car has reached a stop beyond hers', function () {
    /*
     * A second passenger, so being driven past is a real state rather than a contrived one: the car
     * collected somebody whose pickup comes AFTER hers, which is the morning v5 is describing.
     */
    $otherToken = verifiedPassenger('01222220007', device: 'pax-7');

    $otherBooking = approveSeat(
        $this->driverToken,
        requestSeat($otherToken, $this->commuteId, [
            'scheduledTripId' => $this->tripId,
            // Further along the route than hers, so it is ordered after her pickup.
            'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
            'dropoff' => ['lat' => 30.0710, 'lng' => 30.9750, 'label' => 'Smart Village gate'],
        ])->assertStatus(201)->json('data.id'),
    );

    runLeavingIn($this->tripId, -20);
    runStartedLate($this->driverToken, $this->tripId);

    // Reading the route is what builds the itinerary.
    test()->flushHeaders()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$this->tripId}/navigation-route")
        ->assertOk();

    $hers = NavigationStop::query()
        ->where('scheduled_trip_id', $this->tripId)
        ->where('booking_id', $this->bookingId)
        ->where('kind', NavigationStopKind::PassengerPickup->value)
        ->sole();

    $theirs = NavigationStop::query()
        ->where('scheduled_trip_id', $this->tripId)
        ->where('booking_id', $otherBooking)
        ->where('kind', NavigationStopKind::PassengerPickup->value)
        ->sole();

    expect($theirs->sequence)->toBeGreaterThan($hers->sequence);

    // The driver collected the second woman and never stopped for the first.
    $theirs->forceFill(['reached_at' => now()])->save();

    exitWithoutFee($this->paxToken, $this->bookingId)
        ->assertOk()
        ->assertJsonPath('data.status', 'EXITED_DUE_TO_DRIVER_DELAY')
        // A different reason, because the two read completely differently on a screen: only this
        // one means stop waiting.
        ->assertJsonPath('data.cancelledReason', 'passed_your_stop')
        ->assertJsonPath('data.cancellationFeePiastres', 0);
});

it('is not available while her stop is still ahead of the car', function () {
    runLeavingIn($this->tripId, -20);

    runStartedLate($this->driverToken, $this->tripId);

    // Nothing reached at all: the car is on its way to her.
    exitWithoutFee($this->paxToken, $this->bookingId)->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| Where the cost lands
|--------------------------------------------------------------------------
*/

/**
 * 🔴 On the driver's record, and nothing on hers. A morning somebody else wasted is exactly what
 * the Reliability Score exists to measure.
 */
it('records it against the driver and nothing against her', function () {
    runLeavingIn($this->tripId, -1);

    exitWithoutFee($this->paxToken, $this->bookingId)->assertOk();

    $event = ReliabilityEvent::query()->sole();

    expect($event->user_id)->toBe($this->driver->id)
        ->and($event->kind)->toBe(ReliabilityEventKind::DriverLateCausedExit)
        ->and($event->booking_id)->toBe($this->bookingId)
        // Nothing at all on the passenger.
        ->and(ReliabilityEvent::query()->where('user_id', $this->pax->id)->count())->toBe(0);
});

/**
 * The driver is told, and told WHY — unlike an ordinary early exit, where the reason is withheld
 * because it may be about her. Here it IS about her driving, and a driver told she lost somebody
 * without being told why cannot act on it.
 */
it('tells the driver she has lost a passenger', function () {
    runLeavingIn($this->tripId, -1);

    exitWithoutFee($this->paxToken, $this->bookingId)->assertOk();

    $notification = Notification::query()
        ->where('user_id', $this->driver->id)
        ->where('type', 'passenger_exited_driver_delay')
        ->sole();

    expect($notification->data['reason'])->toBe('not_started')
        ->and($notification->data['bookingId'])->toBe($this->bookingId);
});

/**
 * The seat goes back, so the day's count is honest — but it is NOT announced to the waiting list.
 * "A seat just opened" for a run that is already late reaches people who could not take it, one of
 * whom may be watching the same driver fail to arrive.
 */
it('frees the seat without announcing it', function () {
    runLeavingIn($this->tripId, -1);

    $before = ScheduledTrip::query()->whereKey($this->tripId)->sole()->seats_taken;

    exitWithoutFee($this->paxToken, $this->bookingId)->assertOk();

    expect(ScheduledTrip::query()->whereKey($this->tripId)->sole()->seats_taken)
        ->toBe($before - 1)
        ->and(SeatOffer::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Guards
|--------------------------------------------------------------------------
*/

it('publishes the entitlement on her booking so the button can be shown', function () {
    runLeavingIn($this->tripId, 30);

    test()->flushHeaders()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        // Null means "do not show the button".
        ->assertJsonPath('data.driverDelay', null);

    runLeavingIn($this->tripId, -1);

    test()->flushHeaders()->withToken($this->paxToken)
        ->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->assertJsonPath('data.driverDelay', 'not_started');
});

/**
 * 🔒 Hers alone. The driver has her own paths for a run she cannot make, and routing hers through
 * here would record her own failure as the passenger's exit.
 */
it('refuses the driver and refuses a stranger', function () {
    runLeavingIn($this->tripId, -1);

    exitWithoutFee($this->driverToken, $this->bookingId)->assertStatus(404);

    $stranger = verifiedPassenger('01299887766', device: 'pax-9');

    exitWithoutFee($stranger, $this->bookingId)->assertStatus(404);

    expect(Booking::query()->whereKey($this->bookingId)->sole()->status)
        ->toBe(BookingStatus::Confirmed);
});

it('refuses a booking that has already ended', function () {
    runLeavingIn($this->tripId, -1);

    exitWithoutFee($this->paxToken, $this->bookingId)->assertOk();

    exitWithoutFee($this->paxToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.status.0', 'EXITED_DUE_TO_DRIVER_DELAY');
});

/*
|--------------------------------------------------------------------------
| The latent bug this slice also fixed
|--------------------------------------------------------------------------
*/

/**
 * 🔴 A driver-fault cancellation must charge the passenger nothing.
 *
 * `CancelBookingAction` used to write the configured fee whoever cancelled and for whatever
 * reason — harmless only because the number is currently zero. The day somebody sets it, a woman
 * whose seat the DRIVER cancelled would be charged for it. v5 is explicit that driver fault costs
 * the passenger nothing, and three of the four cancellation paths already wrote a deliberate zero;
 * this was the fourth.
 */
it('charges nothing when the driver cancels her seat', function () {
    config(['rafeeq.booking.cancellation_fee_piastres' => 2_500]);

    /*
     * The same endpoint the passenger uses — which side is cancelling is read from who the caller
     * IS, never from the request.
     */
    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Car trouble'])
        ->assertOk();

    $booking = Booking::query()->whereKey($this->bookingId)->sole();

    expect($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and((int) $booking->cancellation_fee_piastres)->toBe(0);
});

/**
 * And the passenger's own cancellation still carries the policy's number, so the fix did not simply
 * turn fees off.
 */
it('still charges her when she cancels her own seat', function () {
    config(['rafeeq.booking.cancellation_fee_piastres' => 2_500]);

    test()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Plans changed'])
        ->assertOk();

    expect((int) Booking::query()->whereKey($this->bookingId)->sole()->cancellation_fee_piastres)
        ->toBe(2_500);
});
