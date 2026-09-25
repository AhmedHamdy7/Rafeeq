<?php

use App\Domains\Booking\Actions\ApproveSeatRequestAction;
use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 🔴 The mandatory concurrency tests (Bible §8.2):
 *
 *     it('allows only one booking for the last seat under 10 concurrent requests')
 *     it('never lets seats_taken exceed seats_total')
 *
 * Pitfall #1, stated plainly: two approvals arrive in the same millisecond, both
 * read `seats_taken = 2` of 3, both pass the check, and a three-seat car carries
 * four people.
 *
 * ---
 *
 * A NOTE ON WHAT THESE TESTS CAN AND CANNOT PROVE, because this was attempted once
 * before in Phase 1 and abandoned honestly rather than faked.
 *
 * A true test would run ten approvals in ten parallel connections. That is not
 * possible inside this suite: `RefreshDatabase` wraps every test in a transaction
 * that is never committed, so a second connection cannot see the fixtures at all
 * and would block on the first lock until it timed out.
 *
 * So the guarantee is pinned down from three directions instead, and each one is
 * honest about which layer it covers:
 *
 *   1. serially, ten approvals against three seats — proves the CHECK inside the
 *      lock is the thing that refuses, not luck;
 *   2. the database CHECK constraint directly — proves that even if the lock were
 *      bypassed entirely, `seats_taken` cannot exceed `seats_total`;
 *   3. the lock is really taken — asserted against the SQL the Action emits, so
 *      removing `lockForUpdate()` fails a test rather than passing quietly.
 *
 * Together those cover both layers of defence. A real parallel-connection test
 * belongs in an integration environment with a committed database, and is noted in
 * the progress file rather than pretended here.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    // The reference commute offers 3 seats.
    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->first();
});

/**
 * Creates a pending seat request straight in the database.
 *
 * Deliberately not through the endpoint: each passenger would need their own
 * verified account, and ten of those would make the test about registration rather
 * than about the lock.
 */
function pendingRequestFor(string $commuteId, string $tripId, int $index): SeatRequest
{
    $passenger = User::factory()->create([
        'phone_e164' => '+2011100000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        'gender' => 'woman',
        'full_name' => "Passenger {$index}",
        'public_first_name' => "P{$index}",
        'registered_role' => 'passenger',
        'profile_status' => 'basic_complete',
        'trust_level' => 2,
    ]);

    $request = new SeatRequest;

    $request->fill([
        'passenger_user_id' => $passenger->id,
        'commute_offer_id' => $commuteId,
        'scheduled_trip_id' => $tripId,
        'commitment' => 'trial',
        'seats' => 1,
        'meeting_preference' => 'gate',
        'agreed_to_rules_at' => now(),
        'payment_type' => 'cash',
    ]);

    $request->status = SeatRequestStatus::Pending->value;
    $request->save();

    return $request;
}

/**
 * 🔴 The first mandatory test.
 */
it('allows only three bookings for three seats under ten approval attempts', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $requests = [];

    foreach (range(1, 10) as $index) {
        $requests[] = pendingRequestFor($this->commuteId, $this->trip->id, $index);
    }

    $approved = 0;
    $refused = 0;

    foreach ($requests as $request) {
        try {
            app(ApproveSeatRequestAction::class)->execute($request, $driver);
            $approved++;
        } catch (DomainException $e) {
            // The refusal comes from the availability check INSIDE the lock, which
            // is the thing under test.
            expect($e->errorCode)->toBe(ErrorCode::SeatUnavailable);
            $refused++;
        }
    }

    expect($approved)->toBe(3)
        ->and($refused)->toBe(7)
        ->and(Booking::count())->toBe(3)
        ->and($this->trip->refresh()->seats_taken)->toBe(3);
});

it('allows only one booking for the last seat', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    // Two seats already gone, one left, ten people asking.
    $this->trip->forceFill(['seats_taken' => 2])->save();

    $approved = 0;

    foreach (range(1, 10) as $index) {
        try {
            app(ApproveSeatRequestAction::class)
                ->execute(pendingRequestFor($this->commuteId, $this->trip->id, $index), $driver);
            $approved++;
        } catch (DomainException) {
            // expected for nine of them
        }
    }

    expect($approved)->toBe(1)
        ->and($this->trip->refresh()->seats_taken)->toBe(3);
});

/**
 * 🔴 The second mandatory test, at the layer below the application.
 *
 * Even if the lock were removed, or a future code path forgot it, the database
 * itself refuses. That is what makes this a guarantee rather than a convention.
 */
it('never lets seats_taken exceed seats_total, even bypassing the application', function () {
    expect(fn () => $this->trip->forceFill(['seats_taken' => 4])->save())
        ->toThrow(QueryException::class);

    expect($this->trip->refresh()->seats_taken)->toBe(0);
});

it('refuses an increment past capacity written straight in SQL', function () {
    $this->trip->forceFill(['seats_taken' => 3])->save();

    expect(fn () => DB::table('scheduled_trips')
        ->where('id', $this->trip->id)
        ->increment('seats_taken'))
        ->toThrow(QueryException::class);
});

/**
 * 🔴 That the lock is actually taken.
 *
 * Without this, someone could delete `lockForUpdate()` and every test above would
 * still pass — they run serially, so nothing else would notice. Asserting on the
 * emitted SQL is what makes the removal fail.
 */
it('takes a row lock on the trip before reading its seat count', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $request = pendingRequestFor($this->commuteId, $this->trip->id, 1);

    $locking = [];

    DB::listen(function ($query) use (&$locking): void {
        if (str_contains($query->sql, 'for update')) {
            $locking[] = $query->sql;
        }
    });

    app(ApproveSeatRequestAction::class)->execute($request, $driver);

    expect($locking)->not->toBeEmpty('The approval ran without taking a row lock.');

    // And on the trip specifically — locking the offer instead would stop every
    // booking on every day of that commute (pitfall #4).
    expect($locking[0])->toContain('scheduled_trips');
});

/**
 * Pitfall #2: `$model->seats_taken + $n` is a read and a write with a gap between
 * them. Only one atomic statement is safe.
 */
it('increments the seat count in one atomic statement', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $request = pendingRequestFor($this->commuteId, $this->trip->id, 1);

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(ApproveSeatRequestAction::class)->execute($request, $driver);

    $incremented = array_filter(
        $statements,
        fn (string $sql) => str_contains($sql, 'update `scheduled_trips`')
            && str_contains($sql, 'seats_taken` = `seats_taken` +'),
    );

    expect($incremented)->not->toBeEmpty(
        'The seat count was not incremented in SQL — a read-then-write loses updates.'
    );
});

/**
 * Pitfall #3: a notification sent inside the transaction survives a rollback, and
 * holds the lock open for the length of a network call.
 */
it('sends nothing from inside the locked transaction', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $request = pendingRequestFor($this->commuteId, $this->trip->id, 1);

    Notification::fake();
    Mail::fake();

    app(ApproveSeatRequestAction::class)->execute($request, $driver);

    // Notifications belong to Phase 12, on a listener outside the transaction.
    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

it('releases the seat again when a booking is cancelled', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $booking = app(ApproveSeatRequestAction::class)
        ->execute(pendingRequestFor($this->commuteId, $this->trip->id, 1), $driver)
        ->booking();

    expect($this->trip->refresh()->seats_taken)->toBe(1);

    app(CancelBookingAction::class)->byPassenger($booking);

    expect($this->trip->refresh()->seats_taken)->toBe(0);
});

/**
 * 🔴 A passenger who cancelled a day must be able to be seated on it again.
 *
 * `assertNoDuplicateBooking()` has always allowed it — "someone who cancelled and
 * changed their mind should be able to rebook" — but the unique index behind it did
 * not: it covered (trip, passenger) with no regard for status, so the approval hit a
 * constraint violation and the person got a 500 instead of a seat.
 *
 * The path that reaches it is somebody leaving a group and rejoining later, which is
 * what exposed this. Reproduced here at the Action level: the first request is marked
 * `ended` exactly as completing a leave notice marks it, which is what frees them to
 * ask again at all.
 */
it('lets a passenger be seated again on a day they cancelled', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $first = pendingRequestFor($this->commuteId, $this->trip->id, 1);

    $booking = app(ApproveSeatRequestAction::class)->execute($first, $driver)->booking();

    app(CancelBookingAction::class)->byPassenger($booking, 'Changed my mind');

    // As completing a leave notice does: the spent approval stops holding this
    // person's one open request for the commute.
    $first->forceFill(['status' => SeatRequestStatus::Ended->value])->save();

    // The SAME person asking again about the SAME day.
    $second = new SeatRequest;

    $second->fill([
        'passenger_user_id' => $first->passenger_user_id,
        'commute_offer_id' => $this->commuteId,
        'scheduled_trip_id' => $this->trip->id,
        'commitment' => 'trial',
        'seats' => 1,
        'meeting_preference' => 'gate',
        'agreed_to_rules_at' => now(),
        'payment_type' => 'cash',
    ]);

    $second->status = SeatRequestStatus::Pending->value;
    $second->save();

    $again = app(ApproveSeatRequestAction::class)->execute($second, $driver)->booking();

    expect($again->id)->not->toBe($booking->id)
        ->and($again->passenger_user_id)->toBe($booking->passenger_user_id)
        ->and($this->trip->refresh()->seats_taken)->toBe(1);
});

/**
 * And the narrowed index still refuses what it was always meant to: two seats held by
 * one person on one day.
 */
it('still refuses two live bookings for one person on one day', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $booking = app(ApproveSeatRequestAction::class)
        ->execute(pendingRequestFor($this->commuteId, $this->trip->id, 1), $driver)
        ->booking();

    expect(fn () => Booking::query()->insert([
        ...$booking->replicate()->getAttributes(),
        'id' => (string) Str::ulid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('never drives the seat count below zero', function () {
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $booking = app(ApproveSeatRequestAction::class)
        ->execute(pendingRequestFor($this->commuteId, $this->trip->id, 1), $driver)
        ->booking();

    // The count is tampered with behind the application's back.
    $this->trip->forceFill(['seats_taken' => 0])->save();

    app(CancelBookingAction::class)->byPassenger($booking);

    // A negative count would make the trip look bookable past its capacity.
    expect($this->trip->refresh()->seats_taken)->toBe(0);
});
