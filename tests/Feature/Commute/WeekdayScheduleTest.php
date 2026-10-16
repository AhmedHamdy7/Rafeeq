<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

/**
 * Feature 03 v3: "Driver route fixed across recurring weekdays; **each weekday has independent
 * departure, promised arrival, price within route range, capacity and bookings**."
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
        ->and($rows->pluck('departure_time')->unique()->all())->toBe(['07:05:00'])
        // Null means "follow the commute" — not a copy of its price, which would stop following it.
        ->and($rows->pluck('price_per_seat_piastres')->unique()->all())->toBe([null]);
});

/**
 * 🔴 The feature itself: one day genuinely different from the rest.
 */
it('lets one day leave earlier, cost less and carry fewer people', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'perDay' => [
            (string) DaysMask::SUNDAY => [
                'departureTime' => '06:30:00',
                'pricePerSeatPiastres' => 2500,
                'seatsTotal' => 2,
            ],
        ],
    ])->assertOk();

    $sunday = CommuteWeekdaySchedule::query()->where('weekday_bit', DaysMask::SUNDAY)->sole();
    $monday = CommuteWeekdaySchedule::query()->where('weekday_bit', DaysMask::MONDAY)->sole();

    expect($sunday->departure_time)->toBe('06:30:00')
        ->and($sunday->price_per_seat_piastres)->toBe(2500)
        ->and($sunday->seats_total)->toBe(2)
        // And the other days are untouched.
        ->and($monday->departure_time)->toBe('07:05:00')
        ->and($monday->price_per_seat_piastres)->toBeNull();
});

/**
 * 🔴 Where the independence actually takes effect. Recording it is worthless if generation ignores
 * it, so this follows the values all the way into the occurrences a passenger books.
 */
it('generates each day`s trip from that day`s own time, price and capacity', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'perDay' => [
            (string) DaysMask::SUNDAY => [
                'departureTime' => '06:30:00',
                'pricePerSeatPiastres' => 2500,
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
        ->and($sundays->pluck('price_snapshot_piastres')->unique()->all())->toBe([2500])
        ->and($sundays->pluck('seats_total')->unique()->all())->toBe([2]);

    // The rest of the week kept the commute's own price and capacity.
    $offer = CommuteOffer::findOrFail($this->commuteId);

    expect($others->pluck('price_snapshot_piastres')->unique()->all())
        ->toBe([(int) $offer->price_per_seat_piastres]);
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

/**
 * No promise, no breach. A commute published without an arrival time must not acquire one, because
 * missing it counts against the driver.
 */
it('leaves the promise null when she never made one', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
    ])->assertOk();

    test()->withToken($this->token)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    expect(CommuteWeekdaySchedule::query()->whereNotNull('promised_arrival_time')->count())->toBe(0)
        ->and(ScheduledTrip::query()->whereNotNull('promised_arrival_at')->count())->toBe(0);
});

/**
 * 🔴 The derived cache cannot drift, in either direction.
 */
it('keeps the parent mask and time derived from the rows that exist', function () {
    saveSchedule($this->token, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'perDay' => [(string) DaysMask::SUNDAY => ['departureTime' => '06:30:00']],
    ])->assertOk();

    $schedule = CommuteOffer::findOrFail($this->commuteId)->schedule;

    expect($schedule->days_mask)->toBe(DaysMask::weekdaysSunToThu()->value)
        /*
         * The EARLIEST departure across the week, which is the honest single answer to "when does
         * this leave": it is the time a passenger has to be ready by on her earliest day.
         */
        ->and($schedule->departure_time)->toBe('06:30:00');
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
