<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Enums\ReliabilityRole;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Support\ReliabilityLedger;
use Illuminate\Support\Facades\Route;

/**
 * The Reliability Score, and the ledger it is derived from (Feature 03 v5).
 *
 * > *"Cancellation/displacement reduces Reliability Score **even when fee paid**."* · *"Admin
 * > monthly reviews low Reliability/repeat violations for drivers **and passengers**."* ·
 * > *"approved appeal reverses relevant fee/score impact"* · *"Score/history persist after
 * > reinstatement."*
 *
 * 🔴 Why a ledger rather than a number on a profile: v5 routes a low score to a temporary
 * suspension and to a permanent ban. A figure that can end somebody's account has to be
 * reconstructible line by line in front of whoever is ending it — and a running total maintained
 * incrementally drifts the first time a job runs twice, which is the reasoning
 * `RecomputeRatingStatsAction` already sets out for averages.
 *
 * The weights are the partners' (Feature 03 files X-3, decisions D3/D4): driver/passenger no-show
 * −10/−5, cancel < 10h −5/−2, 10–16h −3/—, ≥ 16h −1/0, lateness −2/−1, ending a trip −8. They are
 * written out here as numbers on purpose, so a change to the shipped defaults is a visible change
 * to these tests. ⚠️ The score gates nothing (D4).
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['seatsTotal' => 3]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
});

/**
 * @return array{token: string, bookingId: string, user: User}
 */
function rider(string $driverToken, string $commuteId, string $tripId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);

    $requestId = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    return [
        'token' => $token,
        'bookingId' => approveSeat($driverToken, $requestId),
        // `+2` prefixed to the local number as dialled, leading zero and all — the form
        // `verifiedPassenger()` stores, and the same shape the other suites look up by.
        'user' => User::query()->where('phone_e164', '+2'.$phone)->sole(),
    ];
}

/**
 * Nobody starts in the red. The same choice `PersonSummary` makes for an unrated driver: a new
 * member has not failed anybody, and starting her at zero would make the number say she had.
 */
it('starts everybody at a perfect score', function () {
    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))
        ->toBe(ReliabilityLedger::PERFECT);
});

/**
 * Moves only the day's departure, not its date — `(commute_offer_id, trip_date)` is unique, and the
 * notice a cancellation gave is read from `departure_at` and nothing else.
 */
function departsInHours(string $tripId, float $hours): void
{
    ScheduledTrip::query()->whereKey($tripId)->update([
        'departure_at' => now()->addMinutes((int) round($hours * 60)),
    ]);
}

/**
 * 🔴 The most pointed act: she looked at one woman's booking and ended it.
 */
it('marks a driver who cancels one passenger’s seat', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    departsInHours($this->tripId, 5);

    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$her['bookingId']}/cancel")->assertOk();

    $event = ReliabilityEvent::sole();

    expect($event->kind)->toBe(ReliabilityEventKind::DriverCancelledBooking)
        ->and($event->user_id)->toBe($this->driver->id)
        ->and($event->role)->toBe(ReliabilityRole::Driver)
        ->and($event->booking_id)->toBe($her['bookingId'])
        // The weight is snapshotted, so a later policy change cannot rewrite her record.
        // Under ten hours' notice: −5.
        ->and($event->weight)->toBe(5);

    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))->toBe(95);
});

/**
 * 🔴 X-3: a driver's cancellation costs by the notice she gave — < 10h −5, 10–16h −3, ≥ 16h −1.
 * The boundaries are `reliability.late_cancellation_hours` and the schedule lock.
 */
it('weighs a driver’s cancellation by the notice she gave', function (float $hours, int $weight) {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    departsInHours($this->tripId, $hours);

    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$her['bookingId']}/cancel")->assertOk();

    expect(ReliabilityEvent::sole()->weight)->toBe($weight);
})->with([
    'short notice' => [9.5, 5],
    'between the short-notice line and the lock' => [12, 3],
    'beyond the lock' => [40, 1],
]);

/**
 * 🔒 An operator who sets a weight to 0 switches that event off: a row worth nothing would sit in
 * her history looking like a mark against her.
 */
it('records nothing for an event whose weight is set to zero', function () {
    PlatformSetting::query()->create([
        'setting_key' => 'reliability.weights.driver_cancel_long_notice',
        'setting_value' => 0,
        'value_type' => 'integer',
        'description' => 'Switched off',
    ]);

    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    departsInHours($this->tripId, 40);

    test()->flushHeaders()->withToken($this->driverToken)
        ->patchJson("/api/v1/bookings/{$her['bookingId']}/cancel")->assertOk();

    expect(ReliabilityEvent::query()->count())->toBe(0);
});

/**
 * 🔴 v5: a displacement counts too — "Cancellation/displacement reduces Reliability Score" — and it
 * is recorded PER PASSENGER, because five women rearranging their mornings is five times the harm
 * of one. X-3 has one "driver cancels" row, so it takes the same notice ladder.
 */
it('marks a pause once for every passenger it displaces', function () {
    rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    rider($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    departsInHours($this->tripId, 12);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    $events = ReliabilityEvent::query()
        ->where('kind', ReliabilityEventKind::DriverDisplacedPassenger->value)
        ->get();

    expect($events)->toHaveCount(2)
        // 10–16 hours' notice: −3 each.
        ->and($events->pluck('weight')->all())->toBe([3, 3]);

    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))->toBe(94);
});

it('marks a driver who calls off a whole day, once per passenger on it', function () {
    rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');
    rider($this->driverToken, $this->commuteId, $this->tripId, '01155667788', 'pax-2');

    runLeavingIn($this->tripId, 120);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/cancel", ['reason' => 'Car trouble'])->assertOk();

    expect(ReliabilityEvent::query()
        ->where('kind', ReliabilityEventKind::DriverCancelledDay->value)
        ->count())->toBe(2);
});

/**
 * 🔴 v5's monthly review covers "drivers **and passengers**" — and a no-show is the passenger's.
 */
it('marks a passenger the driver recorded as absent', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    underway($this->driverToken, $this->tripId);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $her['bookingId']])
        ->assertOk();

    $event = ReliabilityEvent::sole();

    expect($event->kind)->toBe(ReliabilityEventKind::PassengerNoShow)
        ->and($event->user_id)->toBe($her['user']->id)
        ->and($event->role)->toBe(ReliabilityRole::Passenger)
        ->and($event->weight)->toBe(5);
});

/**
 * 🔴 **Silence is still not an absence.** `CompleteTripAction` leaves an unconfirmed row `pending`
 * precisely so a driver who forgot to tap does not mark anybody absent — and a score built on
 * silence would undo that protection by another route.
 */
it('marks nobody when the driver simply never tapped', function () {
    rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    journeyCompleted($this->driverToken, $this->tripId);

    expect(ReliabilityEvent::query()->count())->toBe(0);
});

/**
 * 🔴 A passenger cancelling a week out costs nobody anything — the seat goes back to the list and
 * somebody takes it. A score that punished ordinary planning would teach people to keep seats they
 * did not want.
 */
it('leaves an early passenger cancellation off the record entirely', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    /*
     * Only `departure_at` is moved, not the trip's date — `runLeavingIn()` moves both, and pushing
     * the date five days out collides with the day already generated for it
     * (`commute_offer_id, trip_date` is unique). The lateness rule reads `departure_at` and nothing
     * else, so this is the whole of what the test needs.
     */
    ScheduledTrip::query()->whereKey($this->tripId)->update([
        'departure_at' => now()->addDays(5),
        'booking_deadline_at' => now()->addDays(5),
    ]);

    test()->flushHeaders()->withToken($her['token'])
        ->patchJson("/api/v1/bookings/{$her['bookingId']}/cancel")->assertOk();

    expect(ReliabilityEvent::query()->count())->toBe(0)
        ->and(ReliabilityLedger::scoreFor($her['user']->id, ReliabilityRole::Passenger))
        ->toBe(ReliabilityLedger::PERFECT);
});

/**
 * And inside the window it does count: the driver is unlikely to fill it, and somebody drives to a
 * kerb for nobody.
 */
it('marks a passenger who cancels close to departure', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    runLeavingIn($this->tripId, 60);

    test()->flushHeaders()->withToken($her['token'])
        ->patchJson("/api/v1/bookings/{$her['bookingId']}/cancel")->assertOk();

    $event = ReliabilityEvent::sole();

    expect($event->kind)->toBe(ReliabilityEventKind::PassengerCancelledLate)
        ->and($event->user_id)->toBe($her['user']->id)
        ->and($event->weight)->toBe(2);
});

/**
 * 🔴 The loophole, closed. `AcceptSeatOfferAction` releases the old seat itself rather than going
 * through `CancelBookingAction`, so a passenger could have dodged the mark by switching to another
 * commute the night before instead of cancelling. The seat is just as empty either way.
 */
it('marks a late switch exactly as it marks a late cancellation', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    // A second commute with a free seat, and an offer she can accept.
    $otherDriver = approvedDriver('01033334444', devicePublicId: 'dev-2', seed: 2);
    $otherVehicle = Vehicle::query()->whereKeyNot(Vehicle::query()->oldest('id')->first()->id)->sole();
    $otherCommute = readyCommute($otherDriver, $otherVehicle->id);

    test()->flushHeaders()->withToken($otherDriver)
        ->postJson("/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    $otherTrip = ScheduledTrip::query()->where('commute_offer_id', $otherCommute)
        ->orderBy('trip_date')->firstOrFail();

    $otherRequest = requestSeat($her['token'], $otherCommute, ['scheduledTripId' => $otherTrip->id])
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($otherDriver)
        ->postJson("/api/v1/driver/seat-requests/{$otherRequest}/waitlist")->assertOk();

    app(OfferSeatToWaitlistAction::class)->forTrip($otherTrip, 1);

    // Her old day is close enough that leaving it costs her.
    runLeavingIn($this->tripId, 60);

    $offerId = SeatOffer::query()
        ->where('seat_request_id', $otherRequest)->sole()->getKey();

    test()->flushHeaders()->withToken($her['token'])
        ->postJson("/api/v1/seat-offers/{$offerId}/accept", [
            'switchFromBookingId' => $her['bookingId'],
            'switchReason' => 'voluntary',
        ])->assertOk();

    expect(ReliabilityEvent::query()
        ->where('user_id', $her['user']->id)
        ->where('kind', ReliabilityEventKind::PassengerCancelledLate->value)
        ->count())->toBe(1);
});

/**
 * 🔒 And a driver-delay exit carries nothing, on either record. v5 exempts it from the fee, and
 * `SwitchReason::DriverDelay` already records the old booking as cancelled by the driver so her
 * lateness stays out of the passenger's history — that exclusion has to reach this ledger or it is
 * cosmetic.
 */
it('charges nobody when a passenger leaves because the driver was late', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    runLeavingIn($this->tripId, 60);

    app(CancelBookingAction::class)->byDriver(
        Booking::findOrFail($her['bookingId']),
        $this->driver->id,
        SwitchReason::DriverDelay->value,
    );

    expect(ReliabilityEvent::query()->count())->toBe(0);
});

/**
 * 🔴 One mark per morning. The cancelling paths are retried — an overlapping sweep, a replayed job
 * — and two marks on one person for one booking would be two strikes she only earned once.
 */
it('never records the same event twice for one booking', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    foreach ([1, 2, 3] as $ignored) {
        ReliabilityLedger::record(
            $this->driver->id,
            ReliabilityEventKind::DriverCancelledBooking,
            booking: Booking::findOrFail($her['bookingId']),
        );
    }

    expect(ReliabilityEvent::query()->count())->toBe(1);
});

/**
 * 🔴 v5: *"approved appeal reverses relevant fee/score impact"* — reversed, and still readable.
 * That she appealed and won is exactly what a second appeal and a reinstatement turn on.
 */
it('stops counting a reversed event but keeps it on the record', function () {
    ReliabilityEvent::factory()->for($this->driver)->create();
    $reversed = ReliabilityEvent::factory()->for($this->driver)->reversed()->create();

    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))
        ->toBe(ReliabilityLedger::PERFECT - ReliabilityEventKind::DriverCancelledBooking->weight())
        // Still there, and still visible in the history.
        ->and(ReliabilityEvent::query()->count())->toBe(2)
        ->and($reversed->refresh()->counts())->toBeFalse()
        ->and(ReliabilityLedger::historyFor($this->driver->id, ReliabilityRole::Driver))->toHaveCount(2);
});

/**
 * 🔴 v5: *"Score/history persist after reinstatement."* So the rows are permanent and only the
 * SCORE is windowed — a cancellation from last year is part of her history without being part of
 * whether she can be relied on this month.
 */
it('looks back only so far for the score, and keeps the history for ever', function () {
    ReliabilityEvent::factory()->for($this->driver)->outsideTheWindow()->create();

    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))
        ->toBe(ReliabilityLedger::PERFECT)
        ->and(ReliabilityLedger::historyFor($this->driver->id, ReliabilityRole::Driver))->toHaveCount(1);
});

/**
 * 🔴 One account is one person doing two things, and her two records stay apart. Merging them would
 * have a future driver refuse her a seat for something she did at the wheel.
 */
it('keeps her driving record out of her passenger standing', function () {
    ReliabilityEvent::factory()->for($this->driver)->create();

    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))
        ->toBeLessThan(ReliabilityLedger::PERFECT)
        ->and(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Passenger))
        ->toBe(ReliabilityLedger::PERFECT);
});

/**
 * Never negative: "as bad as it gets" is zero, and a score of -40 would read and sort as a
 * different kind of number.
 */
it('bottoms out at zero rather than going negative', function () {
    ReliabilityEvent::factory()->for($this->driver)->count(40)->create();

    expect(ReliabilityLedger::scoreFor($this->driver->id, ReliabilityRole::Driver))->toBe(0);
});

/**
 * 🔴 She can read her own standing, and the reasons. v5 builds a suspension and a ban on this
 * number, so somebody whose account can end on it must be able to see it before the review rather
 * than after.
 */
it('shows her her own score with every event behind it', function () {
    $her = rider($this->driverToken, $this->commuteId, $this->tripId, '01112223344', 'pax-1');

    runLeavingIn($this->tripId, 60);

    test()->flushHeaders()->withToken($her['token'])
        ->patchJson("/api/v1/bookings/{$her['bookingId']}/cancel")->assertOk();

    $data = test()->flushHeaders()->withToken($her['token'])
        ->getJson('/api/v1/account/reliability')
        ->assertOk()->json('data');

    expect($data['asPassenger']['score'])
        ->toBe(ReliabilityLedger::PERFECT - ReliabilityEventKind::PassengerCancelledLate->weight())
        ->and($data['asPassenger']['events'])->toHaveCount(1)
        ->and($data['asPassenger']['events'][0]['kind'])->toBe('PASSENGER_CANCELLED_LATE')
        ->and($data['asPassenger']['events'][0]['counts'])->toBeTrue()
        // Her driving record is untouched and separate.
        ->and($data['asDriver']['score'])->toBe(ReliabilityLedger::PERFECT)
        // The number is readable rather than mysterious.
        ->and($data['windowDays'])->toBe(90);
});

/**
 * 🔒 There is no way to read anybody else's. The number is a judgement, and a judgement about
 * somebody else is not something a member may look up — so the endpoint takes no parameter at all.
 */
it('offers no way to read another member’s reliability', function () {
    $stranger = verifiedPassenger('01199887766', device: 'pax-9');

    $paths = collect(registeredV1Paths())
        ->filter(fn (string $path): bool => str_contains($path, 'reliability'));

    /*
     * 🔒 The rule is about READING, so it is written that way rather than as "one path with no
     * placeholders" — which is what this asserted until the appeal shipped.
     *
     * The appeal takes an event id, so a placeholder now exists and has to: she is answering one
     * specific judgement. What must stay impossible is reading somebody's standing, and that is a
     * property of the GET surface — every readable reliability path takes no parameter at all, so
     * there is nothing to substitute somebody else's id into. The appeal's own scoping (hers only,
     * 404 otherwise) is held in `AppealTest`.
     */
    $readable = collect(Route::getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/')
            && str_contains($route->uri(), 'reliability')
            && in_array('GET', $route->methods(), true))
        ->map(fn ($route): string => $route->uri())
        ->values();

    expect($paths)->not->toBeEmpty()
        ->and($readable)->toHaveCount(1)
        ->and($readable->first())->not->toContain('{');

    // And hers answers about her, not about the driver whose commute she is on.
    expect(test()->flushHeaders()->withToken($stranger)
        ->getJson('/api/v1/account/reliability')->assertOk()->json('data.asDriver.score'))
        ->toBe(ReliabilityLedger::PERFECT);
});
