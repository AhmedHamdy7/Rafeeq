<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('rejects a second trip for the same offer on the same day', function () {
    $offer = CommuteOffer::factory()->create();

    ScheduledTrip::factory()->create(['commute_offer_id' => $offer->id, 'trip_date' => '2026-08-09']);
    ScheduledTrip::factory()->create(['commute_offer_id' => $offer->id, 'trip_date' => '2026-08-09']);
})->throws(QueryException::class);

it('rejects seats_taken exceeding seats_total at the database level', function () {
    $trip = ScheduledTrip::factory()->create(['seats_total' => 3, 'seats_taken' => 0]);

    $trip->seats_taken = 4;
    $trip->save();
})->throws(QueryException::class);

it('allows seats_taken up to and including seats_total', function () {
    $trip = ScheduledTrip::factory()->create(['seats_total' => 3, 'seats_taken' => 0]);

    $trip->seats_taken = 3;
    $trip->save();

    expect($trip->fresh()->seats_taken)->toBe(3)
        ->and($trip->fresh()->hasSeatsAvailable())->toBeFalse();
});

it('reports remaining seats correctly', function () {
    $trip = ScheduledTrip::factory()->create(['seats_total' => 3, 'seats_taken' => 1]);

    expect($trip->seatsRemaining())->toBe(2)
        ->and($trip->hasSeatsAvailable())->toBeTrue();
});

it('computes the correct UTC departure across Egypt\'s DST transition in April and October', function () {
    // Egypt DST 2026: last Friday of April (24th) to last Thursday of October (29th).
    $beforeSpringForward = DepartureTimeCalculator::toUtc('2026-04-23', '07:05:00', 'Africa/Cairo');
    $afterSpringForward = DepartureTimeCalculator::toUtc('2026-04-26', '07:05:00', 'Africa/Cairo');
    $beforeFallBack = DepartureTimeCalculator::toUtc('2026-10-28', '07:05:00', 'Africa/Cairo');
    $afterFallBack = DepartureTimeCalculator::toUtc('2026-11-02', '07:05:00', 'Africa/Cairo');

    expect($beforeSpringForward->format('H:i'))->toBe('05:05')  // UTC+2 (winter)
        ->and($afterSpringForward->format('H:i'))->toBe('04:05') // UTC+3 (summer)
        ->and($beforeFallBack->format('H:i'))->toBe('04:05')     // still UTC+3
        ->and($afterFallBack->format('H:i'))->toBe('05:05');     // back to UTC+2

    // The point of the whole exercise: 07:05 local, always.
    foreach ([$beforeSpringForward, $afterSpringForward, $beforeFallBack, $afterFallBack] as $utc) {
        expect($utc->setTimezone('Africa/Cairo')->format('H:i'))->toBe('07:05');
    }
});

it('does not create a duplicate trip when the generator-equivalent runs twice for the same day', function () {
    $offer = CommuteOffer::factory()->published()->create();
    $schedule = CommuteSchedule::factory()->for($offer, 'commuteOffer')->create();

    $attempt = function () use ($offer, $schedule) {
        return ScheduledTrip::query()->insertOrIgnore([[
            'id' => (string) Str::ulid(),
            'commute_offer_id' => $offer->id,
            'commute_schedule_id' => $schedule->id,
            'trip_date' => '2026-08-09',
            'departure_at' => DepartureTimeCalculator::toUtc('2026-08-09', $schedule->departure_time, $schedule->timezone),
            'departure_local' => '2026-08-09 07:05:00',
            'seats_total' => $offer->seats_total,
            'seats_taken' => 0,
            'price_snapshot_piastres' => $offer->price_per_seat_piastres,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    };

    $attempt();
    $attempt();

    expect(ScheduledTrip::where('commute_offer_id', $offer->id)->where('trip_date', '2026-08-09')->count())->toBe(1);
});
