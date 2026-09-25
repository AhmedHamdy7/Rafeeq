<?php

use App\Domains\Commute\Actions\GenerateScheduledTripsAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Chapter 4 §4: "publishing creates scheduled trips", and passengers always book
 * those, never a recurrence rule.
 *
 * Generation is driven directly here rather than through HTTP: the rules being
 * pinned down are about dates, timezones and idempotency, and a full publish flow
 * around each case would obscure which of them failed.
 */
function scheduleFor(CommuteOffer $offer, array $overrides = []): CommuteSchedule
{
    return CommuteSchedule::factory()->create(array_merge([
        'commute_offer_id' => $offer->id,
        'days_mask' => DaysMask::weekdaysSunToThu()->value,
        'departure_time' => '07:05:00',
        'timezone' => 'Africa/Cairo',
        'start_date' => CarbonImmutable::today()->toDateString(),
        'end_date' => CarbonImmutable::today()->addMonths(6)->toDateString(),
        'generated_until' => null,
    ], $overrides));
}

function publishedOffer(array $overrides = []): CommuteOffer
{
    return CommuteOffer::factory()->create(array_merge([
        'status' => CommuteOfferStatus::Published->value,
        'seats_total' => 3,
        'price_per_seat_piastres' => 8000,
    ], $overrides));
}

it('generates only the days the schedule actually runs on', function () {
    $offer = publishedOffer();
    scheduleFor($offer, ['days_mask' => DaysMask::fromDays([DaysMask::SUNDAY])->value]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $trips = ScheduledTrip::orderBy('trip_date')->get();

    expect($trips)->not->toBeEmpty();

    foreach ($trips as $trip) {
        expect($trip->trip_date->dayOfWeek)->toBe(CarbonImmutable::SUNDAY);
    }
});

/**
 * The rolling horizon. Generating to the end date would put 5,000 offers ×
 * ~110 workdays into the fastest-growing table in the system on day one.
 */
it('stops at the rolling horizon rather than running to the end date', function () {
    $offer = publishedOffer();
    scheduleFor($offer, ['end_date' => CarbonImmutable::today()->addYear()->toDateString()]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $horizon = CarbonImmutable::today()->addDays((int) config('rafeeq.commute.generation_horizon_days'));

    expect(ScheduledTrip::max('trip_date'))->toBeLessThanOrEqual($horizon->toDateString())
        // And it recorded how far it got, so tomorrow's run starts from there.
        ->and($offer->schedule->refresh()->generated_until->toDateString())->toBe($horizon->toDateString());
});

it('never generates past the schedule end date', function () {
    $offer = publishedOffer();
    $endsIn = CarbonImmutable::today()->addDays(5);

    scheduleFor($offer, ['end_date' => $endsIn->toDateString(), 'days_mask' => 127]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    expect(ScheduledTrip::max('trip_date'))->toBe($endsIn->toDateString());
});

it('never generates a day that is already in the past', function () {
    $offer = publishedOffer();
    scheduleFor($offer, [
        'start_date' => CarbonImmutable::today()->subMonth()->toDateString(),
        'days_mask' => 127,
    ]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    expect(ScheduledTrip::min('trip_date'))->toBe(CarbonImmutable::today()->toDateString());
});

/**
 * A retry, an overlapping manual run, or a job firing twice must not produce two
 * trips for one day.
 */
it('is idempotent across repeated runs', function () {
    $offer = publishedOffer();
    scheduleFor($offer, ['days_mask' => 127]);

    $first = app(GenerateScheduledTripsAction::class)->execute($offer->refresh());
    $second = app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(ScheduledTrip::count())->toBe($first);
});

it('is the database that forbids two trips on one day, not just the code', function () {
    $offer = publishedOffer();
    scheduleFor($offer, ['days_mask' => 127]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $existing = ScheduledTrip::orderBy('trip_date')->first();

    expect(fn () => ScheduledTrip::factory()->create([
        'commute_offer_id' => $offer->id,
        'commute_schedule_id' => $offer->schedule->id,
        'trip_date' => $existing->trip_date->toDateString(),
    ]))->toThrow(QueryException::class);
});

it('generates nothing for a commute that is not published', function (string $status) {
    $offer = publishedOffer(['status' => $status]);
    scheduleFor($offer, ['days_mask' => 127]);

    expect(app(GenerateScheduledTripsAction::class)->execute($offer->refresh()))->toBe(0)
        ->and(ScheduledTrip::count())->toBe(0);
})->with(['draft', 'paused', 'archived']);

/**
 * 🔴 Pitfall #6, the most dangerous bug in the project. Egypt has observed real
 * DST by law since 2023: last Friday of April to last Thursday of October.
 *
 * A schedule says "07:05, Africa/Cairo" — a time on a wall clock. If that were
 * converted to a fixed UTC offset when the schedule was saved, every trip after a
 * transition would depart an hour wrong, which for a commuter means missing work.
 */
it('keeps the same local departure time across a DST transition', function () {
    // Summer time starts the last Friday of April 2026 (the 24th).
    $this->travelTo(CarbonImmutable::parse('2026-04-20 06:00', 'Africa/Cairo'));

    $offer = publishedOffer();
    scheduleFor($offer, [
        'days_mask' => 127,
        'start_date' => '2026-04-21',
        'end_date' => '2026-04-30',
        'departure_time' => '07:05:00',
    ]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $before = ScheduledTrip::where('trip_date', '2026-04-23')->sole();   // winter time
    $after = ScheduledTrip::where('trip_date', '2026-04-26')->sole();    // summer time

    // The person reads 07:05 on both days.
    expect($before->departure_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('07:05')
        ->and($after->departure_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('07:05');

    // And the UTC instants differ by an hour, which is the whole point: a fixed
    // offset stored up front would have made these identical.
    expect($before->departure_at->format('H:i'))->toBe('05:05')
        ->and($after->departure_at->format('H:i'))->toBe('04:05');
});

it('keeps the same local departure time across the autumn transition too', function () {
    // Summer time ends the last Thursday of October 2026 (the 29th).
    $this->travelTo(CarbonImmutable::parse('2026-10-25 06:00', 'Africa/Cairo'));

    $offer = publishedOffer();
    scheduleFor($offer, [
        'days_mask' => 127,
        'start_date' => '2026-10-26',
        'end_date' => '2026-11-05',
        'departure_time' => '07:05:00',
    ]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $summer = ScheduledTrip::where('trip_date', '2026-10-28')->sole();
    $winter = ScheduledTrip::where('trip_date', '2026-11-02')->sole();

    expect($summer->departure_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('07:05')
        ->and($winter->departure_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('07:05')
        ->and($summer->departure_at->format('H:i'))->toBe('04:05')
        ->and($winter->departure_at->format('H:i'))->toBe('05:05');
});

it('snapshots the seats and price so a later change cannot move a booked day', function () {
    $offer = publishedOffer(['seats_total' => 3, 'price_per_seat_piastres' => 8000]);
    scheduleFor($offer, ['days_mask' => 127]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $trip = ScheduledTrip::orderBy('trip_date')->first();

    expect($trip->seats_total)->toBe(3)
        ->and($trip->price_snapshot_piastres)->toBe(8000);

    // The offer changes; the day already generated does not.
    $offer->forceFill(['price_per_seat_piastres' => 12000])->save();

    expect($trip->refresh()->price_snapshot_piastres)->toBe(8000);
});

it('sets a booking deadline the evening before, in the driver timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 06:00', 'Africa/Cairo'));

    $offer = publishedOffer();
    scheduleFor($offer, ['days_mask' => 127, 'start_date' => '2026-06-02', 'end_date' => '2026-06-10']);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $trip = ScheduledTrip::where('trip_date', '2026-06-03')->sole();

    $deadline = $trip->booking_deadline_at->setTimezone('Africa/Cairo');

    expect($deadline->toDateString())->toBe('2026-06-02')
        ->and($deadline->format('H:i'))->toBe(sprintf('%02d:00', config('rafeeq.commute.booking_deadline_hour')));
});

it('continues from where it left off instead of walking the same days again', function () {
    $offer = publishedOffer();
    scheduleFor($offer, ['days_mask' => 127, 'end_date' => CarbonImmutable::today()->addYear()->toDateString()]);

    app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    $firstRun = ScheduledTrip::count();

    // A day passes, and the horizon moves with it.
    $this->travel(1)->day();

    $created = app(GenerateScheduledTripsAction::class)->execute($offer->refresh());

    expect($created)->toBe(1)
        ->and(ScheduledTrip::count())->toBe($firstRun + 1);
});

/**
 * The daily job is what makes recurring commutes keep working. Without it the
 * 31st day never exists and a commute silently stops being bookable a month after
 * it was created.
 */
it('rolls the horizon forward from the scheduled command', function () {
    $offer = publishedOffer();
    scheduleFor($offer, ['days_mask' => 127, 'end_date' => CarbonImmutable::today()->addYear()->toDateString()]);

    $this->artisan('commutes:generate-trips')->assertSuccessful();

    $before = ScheduledTrip::count();

    $this->travel(3)->days();

    $this->artisan('commutes:generate-trips')->assertSuccessful();

    expect(ScheduledTrip::count())->toBe($before + 3);
});

/**
 * Chapter 4's edge case: "recurring end date reached → archive automatically".
 */
it('archives a commute whose end date has passed', function () {
    $offer = publishedOffer();
    scheduleFor($offer, [
        'days_mask' => 127,
        'end_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
    ]);

    $this->artisan('commutes:generate-trips')->assertSuccessful();

    $this->travel(5)->days();

    $this->artisan('commutes:generate-trips')->assertSuccessful();

    expect($offer->refresh()->status)->toBe(CommuteOfferStatus::Archived)
        ->and($offer->archived_at)->not->toBeNull();
});
