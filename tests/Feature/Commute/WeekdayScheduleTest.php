<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

/**
 * Feature 03 v3: "Driver route fixed across recurring weekdays; **each weekday has independent
 * departure, promised arrival, price within route range, capacity and bookings**." — minus the
 * price since D-0016: it is automatic and the same every day (P-1, PAY-42).
 *
 * 🔴 The parent `commute_schedules.days_mask` and `departure_time` are now DERIVED from these rows
 * and maintained by `SaveCommuteScheduleAction`. Search compares the mask inside its query and must
 * not join to do it, so the mask stays on the parent as a cache with exactly one writer. Several
 * tests below exist to prove that cache cannot drift.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = approvedDriver();
    $this->vehicleId = Vehicle::sole()->id;
    $this->commuteId = createCommute($this->token, $this->vehicleId)->assertStatus(201)->json('data.id');
    saveRoute($this->token, $this->commuteId)->assertOk();
});

it('writes one row per day in the mask', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
    ])->assertOk();

    $rows = CommuteWeekdaySchedule::query()->orderBy('weekday_bit')->get();

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('weekday_bit')->all())->toBe([
            DaysMask::SUNDAY, DaysMask::MONDAY, DaysMask::TUESDAY,
            DaysMask::WEDNESDAY, DaysMask::THURSDAY,
        ])
        // Every day gets the single time when no per-day override is sent, which is exactly how
        // this behaved before the table existed.
        ->and($rows->pluck('promised_arrival_time')->unique()->all())->toBe(['09:00:00'])
        ->and($rows->pluck('departure_is_computed')->unique()->all())->toBe([true])
        // Null means "follow the commute" — not a copy of its seats, which would stop following it.
        ->and($rows->pluck('seats_total')->unique()->all())->toBe([null]);
});

/**
 * 🔴 The feature itself: one day genuinely different from the rest.
 */
it('lets one day arrive earlier and carry fewer people', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedArrivalTime' => '09:00:00',
        'perDay' => [
            (string) DaysMask::SUNDAY => [
                'promisedArrivalTime' => '08:25:00',
                'seatsTotal' => 2,
            ],
        ],
    ])->assertOk();

    $sunday = CommuteWeekdaySchedule::query()->where('weekday_bit', DaysMask::SUNDAY)->sole();
    $monday = CommuteWeekdaySchedule::query()->where('weekday_bit', DaysMask::MONDAY)->sole();

    expect($sunday->promised_arrival_time)->toBe('08:25:00')
        ->and($sunday->seats_total)->toBe(2)
        ->and($sunday->departure_time < $monday->departure_time)->toBeTrue()
        ->and($monday->promised_arrival_time)->toBe('09:00:00');
});

/**
 * 🔒 P-1, D-0016: the driver cannot price a day. Refused rather than ignored, so an app still on
 * the old contract finds out on its first try.
 */
it('refuses a per-day price', function () {
    saveSchedule($this->token, $this->commuteId, [
        'perDay' => [(string) DaysMask::SUNDAY => ['pricePerSeatPiastres' => 2500]],
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['perDay.'.DaysMask::SUNDAY.'.pricePerSeatPiastres']]]);
});

/**
 * 🔴 Where the independence actually takes effect. Recording it is worthless if generation ignores
 * it, so this follows the values all the way into the occurrences a passenger books.
 */
it('generates each day`s trip from that day`s own time and capacity, at one automatic price', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'perDay' => [
            (string) DaysMask::SUNDAY => [
                'departureTime' => '06:30:00',
                'seatsTotal' => 2,
            ],
        ],
    ])->assertOk();

    test()->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $trips = ScheduledTrip::query()->orderBy('trip_date')->get();

    $sundays = $trips->filter(fn (ScheduledTrip $t): bool => $t->trip_date->dayOfWeek === 0);
    $others = $trips->reject(fn (ScheduledTrip $t): bool => $t->trip_date->dayOfWeek === 0);

    expect($sundays)->not->toBeEmpty()
        ->and($others)->not->toBeEmpty()
        ->and($sundays->pluck('seats_total')->unique()->all())->toBe([2]);

    // Every day costs the same: the price comes from the route and the car (PAY-42).
    $offer = CommuteOffer::findOrFail($this->commuteId);

    expect($trips->pluck('seat_price_piastres')->unique()->all())
        ->toBe([(int) $offer->seat_price_piastres]);
});

it('stores a promised arrival on each day it was given for', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'promisedArrivalTime' => '08:00:00',
    ])->assertOk();

    test()->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    expect(CommuteWeekdaySchedule::query()->pluck('promised_arrival_time')->unique()->all())
        ->toBe(['08:00:00'])
        // And it reaches the occurrence, which is what an on-time score is measured against.
        ->and(ScheduledTrip::query()->whereNull('promised_arrival_at')->count())->toBe(0);
});

it('refuses a day with no promised arrival, even with a departure', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedArrivalTime' => null,
        'departureTime' => '07:05:00',
    ])->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_ARRIVAL_REQUIRED');
});

it('computes the departure even when one is sent with the arrival', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedArrivalTime' => '09:00:00',
        'departureTime' => '05:00:00',
    ])->assertOk();

    expect(CommuteWeekdaySchedule::query()->pluck('departure_time')->unique()->all())->not->toContain('05:00:00')
        ->and(CommuteWeekdaySchedule::query()->where('departure_is_computed', false)->count())->toBe(0);
});

/**
 * 🔴 The derived cache cannot drift, in either direction.
 */
it('keeps the parent mask and time derived from the rows that exist', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedArrivalTime' => '09:00:00',
        'perDay' => [(string) DaysMask::SUNDAY => ['promisedArrivalTime' => '08:25:00']],
    ])->assertOk();

    $earliest = CommuteWeekdaySchedule::query()->where('weekday_bit', DaysMask::SUNDAY)->sole()->departure_time;

    $schedule = CommuteOffer::findOrFail($this->commuteId)->schedule;

    expect($schedule->days_mask)->toBe(DaysMask::weekdaysSunToThu()->value)
        /*
         * The EARLIEST departure across the week, which is the honest single answer to "when does
         * this leave": it is the time a passenger has to be ready by on her earliest day.
         */
        ->and($schedule->departure_time)->toBe($earliest);
});

it('replaces the whole set rather than leaving a day behind', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
    ])->assertOk();

    // She drops to three days.
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::SUNDAY | DaysMask::TUESDAY | DaysMask::THURSDAY,
        'departureTime' => '07:05:00',
    ])->assertOk();

    $rows = CommuteWeekdaySchedule::query()->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('weekday_bit')->sort()->values()->all())
        ->toBe([DaysMask::SUNDAY, DaysMask::TUESDAY, DaysMask::THURSDAY])
        // And the derived mask followed it down rather than keeping the old five.
        ->and(CommuteOffer::findOrFail($this->commuteId)->schedule->days_mask)
        ->toBe(DaysMask::SUNDAY | DaysMask::TUESDAY | DaysMask::THURSDAY);
});

/**
 * Search reads the derived mask inside its query, so a commute whose days changed has to become
 * findable and unfindable accordingly — this is the reason the cache exists at all.
 */
it('is still found by a search for the days it actually runs', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
    ])->assertOk();

    test()->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01099887766');

    expect(search($paxToken)->assertOk()->json('data'))->not->toBeEmpty();
});
