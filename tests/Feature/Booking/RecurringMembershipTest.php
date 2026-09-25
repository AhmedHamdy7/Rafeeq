<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Scene 10 of the Bible's reference story: after a successful trial, Mariam asks to
 * join permanently, Nour agrees, and bookings are generated for every upcoming day
 * she committed to.
 *
 * This is the half of Chapter 6 that makes Rafeeq a commute-sharing product rather
 * than a booking engine — the same people travelling together repeatedly — so it gets
 * the same scrutiny as the concurrency path.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    // The reference commute runs Sunday to Thursday, three seats.
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344');

    // Monday and Wednesday: a real commitment, and fewer days than the commute runs.
    $this->daysMask = DaysMask::MONDAY | DaysMask::WEDNESDAY;
});

/**
 * How many generated days actually fall on the committed pattern.
 *
 * Counted from the data rather than written down, because the answer depends on which
 * weekday the suite happens to run — a hardcoded 8 would pass on a Tuesday and fail
 * on a Friday.
 */
function committedTripCount(string $commuteId, int $daysMask): int
{
    $mask = DaysMask::fromBits($daysMask);

    return ScheduledTrip::query()
        ->where('commute_offer_id', $commuteId)
        ->where('departure_at', '>', now())
        ->where('departure_at', '<=', now()->addDays((int) config('rafeeq.booking.recurring_horizon_days')))
        ->get()
        ->filter(fn (ScheduledTrip $trip) => $mask->includesDate($trip->trip_date))
        ->count();
}

function requestRecurringSeat(string $token, string $commuteId, int $daysMask)
{
    return requestSeat($token, $commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => $daysMask,
    ]);
}

it('seats a recurring member on every committed day in the horizon', function () {
    $requestId = requestRecurringSeat($this->paxToken, $this->commuteId, $this->daysMask)
        ->assertStatus(201)->json('data.id');

    $expected = committedTripCount($this->commuteId, $this->daysMask);

    expect($expected)->toBeGreaterThan(4, 'The horizon should contain several Mondays and Wednesdays.');

    $approval = test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)->json('data');

    expect($approval['seatedDays'])->toBe($expected)
        ->and($approval['skippedDays'])->toBe([])
        ->and(Booking::count())->toBe($expected);

    // And only on those days — not on the Sundays, Tuesdays and Thursdays the
    // commute also runs.
    $mask = DaysMask::fromBits($this->daysMask);

    Booking::query()->with('scheduledTrip')->get()->each(
        fn (Booking $booking) => expect($mask->includesDate($booking->scheduledTrip->trip_date))->toBeTrue()
    );
});

it('makes them a member rather than a trial rider', function () {
    $requestId = requestRecurringSeat($this->paxToken, $this->commuteId, $this->daysMask)
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)
        ->assertJsonPath('data.membership.role', 'member')
        ->assertJsonPath('data.membership.committedDaysMask', $this->daysMask);

    // One trial ride is not a commitment to a week; a recurring request is, and the
    // group's regulars are exactly this distinction.
    expect(GroupMember::query()->where('role', GroupMemberRole::Member->value)->count())->toBe(1);
});

/**
 * A member may commit to fewer days than the commute runs — three out of five is
 * normal. What they may not do is commit to a day it does not run at all, because
 * that day can never be seated.
 */
it('refuses a commitment to a day the commute does not run', function () {
    requestRecurringSeat($this->paxToken, $this->commuteId, DaysMask::FRIDAY)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'BOOKING_RECURRING_DAYS_NOT_OFFERED');
});

/**
 * 🔴 Refusing a whole month's membership because one Wednesday is full would make
 * recurring commitment nearly impossible. The day is skipped — and REPORTED, so the
 * driver is not left to discover it when somebody is standing on the pavement.
 */
it('skips a day that is already full and says which', function () {
    $mask = DaysMask::fromBits($this->daysMask);

    $full = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('departure_at', '>', now())
        ->get()
        ->first(fn (ScheduledTrip $trip) => $mask->includesDate($trip->trip_date));

    $full->forceFill(['seats_taken' => 3])->save();

    $expected = committedTripCount($this->commuteId, $this->daysMask);

    $requestId = requestRecurringSeat($this->paxToken, $this->commuteId, $this->daysMask)
        ->assertStatus(201)->json('data.id');

    $approval = test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)->json('data');

    expect($approval['seatedDays'])->toBe($expected - 1)
        ->and($approval['skippedDays'])->toHaveCount(1)
        ->and($approval['skippedDays'][0]['tripDate'])->toBe($full->trip_date->toDateString())
        ->and($approval['skippedDays'][0]['reason'])->toBe('SEAT_UNAVAILABLE');

    expect(Booking::query()->where('scheduled_trip_id', $full->id)->exists())->toBeFalse();
});

it('refuses the approval outright when not one day can be seated', function () {
    ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->update(['seats_taken' => 3]);

    $requestId = requestRecurringSeat($this->paxToken, $this->commuteId, $this->daysMask)
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_UNAVAILABLE');

    // Rolled back entirely: no membership in a group with no rides in it, and the
    // request stays open for a day when a seat does free up.
    expect(Booking::count())->toBe(0)
        ->and(GroupMember::query()->where('user_id', '!=', null)->where('role', 'member')->count())->toBe(0);
});

/**
 * 🔴 The silent failure this closes: approval seats the member on the days that exist
 * TODAY. A fortnight later the generator has made a fortnight of new days and nobody
 * is booked on them — the member believes they are committed, their group screen says
 * so, and one morning the car does not stop for them.
 */
it('seats a committed member on days generated after they joined', function () {
    $requestId = requestRecurringSeat($this->paxToken, $this->commuteId, $this->daysMask)
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $atJoining = Booking::count();

    // A fortnight passes and the daily jobs run.
    $this->travel(14)->days();

    Artisan::call('commutes:generate-trips');
    Artisan::call('memberships:roll-forward');

    $after = Booking::query()->where('status', BookingStatus::Confirmed->value)->count();

    expect($after)->toBeGreaterThan(
        $atJoining,
        'A committed member stopped being booked once the original horizon ran out.'
    );

    // Still only on their committed days.
    $mask = DaysMask::fromBits($this->daysMask);

    Booking::query()->with('scheduledTrip')->get()->each(
        fn (Booking $booking) => expect($mask->includesDate($booking->scheduledTrip->trip_date))->toBeTrue()
    );
});

/**
 * Re-seating somebody who cancelled would overrule a decision they made.
 */
it('does not put back a day the member cancelled', function () {
    $requestId = requestRecurringSeat($this->paxToken, $this->commuteId, $this->daysMask)
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $cancelled = Booking::query()->with('scheduledTrip')->get()
        ->sortBy(fn (Booking $booking) => $booking->scheduledTrip->trip_date)
        ->last();

    test()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$cancelled->id}/cancel", ['reason' => 'Exam that morning'])
        ->assertOk();

    Artisan::call('memberships:roll-forward');

    expect($cancelled->refresh()->status)->toBe(BookingStatus::CancelledByPassenger)
        ->and(Booking::query()
            ->where('scheduled_trip_id', $cancelled->scheduled_trip_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->exists())->toBeFalse();
});

it('does not seat a trial rider on days they never committed to', function () {
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    Artisan::call('memberships:roll-forward');

    // One day, because that is what a trial is.
    expect(Booking::count())->toBe(1);
});
