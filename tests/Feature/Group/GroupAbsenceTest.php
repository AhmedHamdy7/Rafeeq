<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupAbsence;
use App\Domains\Notification\Models\Notification;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Planned absences: "I'm away from the 20th to the 27th."
 *
 * 🔴 `releases_seat` is the column with teeth. Set, the seat genuinely goes back on
 * offer — the bookings are cancelled and the waiting list is offered them. Left unset,
 * the seat stays the member's and the car travels with it empty. These tests assert
 * that asymmetry, because a client that showed the two the same way would be hiding
 * the only part of the decision that costs anybody anything.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    // A recurring member, so there are several days for an absence to cover.
    $requestId = requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $this->groupId = CommuteGroup::sole()->id;
});

function planAbsence(string $token, string $groupId, array $overrides = [])
{
    return test()->withToken($token)->postJson("/api/v1/groups/{$groupId}/absences", array_merge([
        'fromDate' => CarbonImmutable::tomorrow()->toDateString(),
        'toDate' => CarbonImmutable::tomorrow()->addDays(6)->toDateString(),
        'reason' => 'إجازة',
    ], $overrides));
}

it('records an absence without touching the seat by default', function () {
    $before = Booking::query()->where('status', BookingStatus::Confirmed->value)->count();

    planAbsence($this->paxToken, $this->groupId)
        ->assertStatus(201)
        ->assertJsonPath('data.releasesSeat', false)
        ->assertJsonPath('data.reason', 'إجازة');

    // The seat stays theirs: the field defaults to false precisely because releasing
    // one gives it away, possibly for good.
    expect(Booking::query()->where('status', BookingStatus::Confirmed->value)->count())->toBe($before);
});

/**
 * 🔴 The substantive case: releasing the seat actually releases it.
 */
it('cancels the bookings in range and frees the seats when asked', function () {
    $from = CarbonImmutable::tomorrow();
    $to = $from->addDays(6);

    $inRange = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])
        ->where('departure_at', '>', now())
        ->pluck('id');

    $bookedInRange = Booking::query()
        ->whereIn('scheduled_trip_id', $inRange)
        ->where('status', BookingStatus::Confirmed->value)
        ->count();

    expect($bookedInRange)->toBeGreaterThan(0, 'The week should contain booked days to release.');

    planAbsence($this->paxToken, $this->groupId, ['releasesSeat' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.releasesSeat', true);

    expect(Booking::query()
        ->whereIn('scheduled_trip_id', $inRange)
        ->where('status', BookingStatus::Confirmed->value)
        ->count())->toBe(0);

    // And the seats are genuinely back: every released day has room again.
    ScheduledTrip::query()->whereIn('id', $inRange)->get()->each(
        fn (ScheduledTrip $trip) => expect($trip->seats_taken)->toBe(0)
    );

    // One decision, not one notice per day: the bulk path does not send the driver a
    // "booking cancelled" for every released day (Phase 12).
    expect(Notification::query()->where('type', 'booking_cancelled')->count())->toBe(0);

    // Recorded as the passenger's own cancellation with the reason attached, so the
    // audit trail says why rather than only who.
    expect(Booking::query()->where('status', BookingStatus::CancelledByPassenger->value)->first()->cancelled_reason)
        ->toBe('إجازة');
});

it('leaves the days outside the absence alone', function () {
    $from = CarbonImmutable::tomorrow();
    $to = $from->addDays(2);

    planAbsence($this->paxToken, $this->groupId, [
        'toDate' => $to->toDateString(),
        'releasesSeat' => true,
    ])->assertStatus(201);

    $stillBooked = Booking::query()
        ->where('status', BookingStatus::Confirmed->value)
        ->with('scheduledTrip')
        ->get();

    expect($stillBooked)->not->toBeEmpty();

    $stillBooked->each(fn (Booking $booking) => expect(
        $booking->scheduledTrip->trip_date->greaterThan($to)
    )->toBeTrue());
});

/**
 * A freed seat is offered to whoever was waiting for that day, as a question for the
 * driver rather than a booking.
 */
it('offers a released seat to the waiting list', function () {
    $tomorrow = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('departure_at', '>', now())
        ->orderBy('trip_date')
        ->first();

    $tomorrow->forceFill(['seats_taken' => 3])->save();

    $waitingToken = verifiedPassenger('01222220001', device: 'pax-2');

    $waitingId = requestSeat($waitingToken, $this->commuteId, ['scheduledTripId' => $tomorrow->id])
        ->assertStatus(201)->json('data.id');

    expect(SeatRequest::query()->whereKey($waitingId)->sole()->status)
        ->toBe(SeatRequestStatus::Waitlisted);

    planAbsence($this->paxToken, $this->groupId, [
        'fromDate' => $tomorrow->trip_date->toDateString(),
        'toDate' => $tomorrow->trip_date->toDateString(),
        'releasesSeat' => true,
    ])->assertStatus(201);

    expect(SeatRequest::query()->whereKey($waitingId)->sole()->status)
        ->toBe(SeatRequestStatus::Pending);
});

it('refuses an absence that overlaps one already recorded', function () {
    planAbsence($this->paxToken, $this->groupId)->assertStatus(201);

    planAbsence($this->paxToken, $this->groupId, [
        'fromDate' => CarbonImmutable::tomorrow()->addDays(3)->toDateString(),
        'toDate' => CarbonImmutable::tomorrow()->addDays(10)->toDateString(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'GROUP_ABSENCE_OVERLAPS');

    expect(GroupAbsence::count())->toBe(1);
});

/**
 * Beyond the maximum it is not an absence, it is leaving — and calling it an absence
 * would hold a seat nobody is using for a term.
 */
it('refuses an absence longer than an absence can be', function () {
    $max = (int) config('rafeeq.group.max_absence_days');

    planAbsence($this->paxToken, $this->groupId, [
        'toDate' => CarbonImmutable::tomorrow()->addDays($max + 5)->toDateString(),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'GROUP_ABSENCE_TOO_LONG');
});

it('refuses a range that ends before it starts', function () {
    planAbsence($this->paxToken, $this->groupId, [
        'fromDate' => CarbonImmutable::tomorrow()->addDays(5)->toDateString(),
        'toDate' => CarbonImmutable::tomorrow()->toDateString(),
    ])->assertStatus(422);

    expect(GroupAbsence::count())->toBe(0);
});

it('accepts a single day, which is the commonest kind', function () {
    $day = CarbonImmutable::tomorrow()->toDateString();

    planAbsence($this->paxToken, $this->groupId, ['fromDate' => $day, 'toDate' => $day])
        ->assertStatus(201)
        ->assertJsonPath('data.fromDate', $day)
        ->assertJsonPath('data.toDate', $day);
});

it('shows the group everybody absences so they can plan', function () {
    planAbsence($this->paxToken, $this->groupId)->assertStatus(201);

    test()->withToken($this->driverToken)->getJson("/api/v1/groups/{$this->groupId}/absences")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        // 🔒 Still only a public first name.
        ->assertJsonPath('data.0.person.publicFirstName', 'سارة');
});

/**
 * ⚠️ Cancelling an absence restores the member's plans, not other people's: the seats
 * were given back and may already belong to somebody else.
 */
it('lets a member call off their absence without restoring the bookings', function () {
    planAbsence($this->paxToken, $this->groupId, ['releasesSeat' => true])->assertStatus(201);

    $cancelledCount = Booking::query()->where('status', BookingStatus::CancelledByPassenger->value)->count();
    $absenceId = GroupAbsence::sole()->id;

    test()->withToken($this->paxToken)
        ->deleteJson("/api/v1/groups/{$this->groupId}/absences/{$absenceId}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(GroupAbsence::count())->toBe(0)
        ->and(Booking::query()->where('status', BookingStatus::CancelledByPassenger->value)->count())
        ->toBe($cancelledCount);
});

/**
 * 🔒 A driver cannot cancel somebody else's plans, even in their own group: an absence
 * is a statement about where a person will be, and only they can withdraw it.
 */
it('refuses to let the driver delete a member absence', function () {
    planAbsence($this->paxToken, $this->groupId)->assertStatus(201);

    $absenceId = GroupAbsence::sole()->id;

    test()->withToken($this->driverToken)
        ->deleteJson("/api/v1/groups/{$this->groupId}/absences/{$absenceId}")
        ->assertStatus(404);

    expect(GroupAbsence::count())->toBe(1);
});

/**
 * A driver is not absent from their own commute — if they are not driving, the trip
 * does not run, and cancelling the day is a different operation that has to tell every
 * passenger on it.
 */
it('refuses an absence from the driver', function () {
    planAbsence($this->driverToken, $this->groupId)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'GROUP_DRIVER_CANNOT_LEAVE');
});

it('refuses an absence from somebody outside the group', function () {
    $stranger = verifiedPassenger('01223334455', device: 'pax-3');

    planAbsence($stranger, $this->groupId)->assertStatus(404);
});
