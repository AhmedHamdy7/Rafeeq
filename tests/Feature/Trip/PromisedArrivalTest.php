<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Actions\CompleteTripAction;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

/**
 * Product decision, 2026-10-16: a driver who arrives later than she promised counts as not on time,
 * outside a five-minute tolerance — and v3 asks that the metric "expose metric version and counts
 * to avoid silently changing scores".
 *
 * 🔴 The rule is TWO conditions, and arrival was added rather than substituted: departure
 * punctuality is already published and is a real signal, so replacing it would have discarded the
 * metric for every commute published before promises existed.
 *
 * ⚠️ These tests also cover a defect found while wiring this up: the computed driver rate lives in
 * `driver_profiles`, while `PersonSummary` used to read `user_stats.on_time_rate` for both roles —
 * a column no production code writes. A real driver's computed rate was therefore never shown to
 * anybody, for ever, while the guide said "null until computed".
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, [], [
        'promisedArrivalTime' => '08:00:00',
    ]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    $this->bookingId = approveSeat($this->driverToken, $requestId);
});

/**
 * Finishes the run with the arrival landing a chosen number of minutes after the promise.
 */
function arriveMinutesLate(string $driverToken, string $tripId, int $minutesLate): void
{
    journeyCompleted($driverToken, $tripId);

    $trip = ScheduledTrip::findOrFail($tripId);
    $session = TripSession::query()->where('scheduled_trip_id', $tripId)->sole();

    /*
     * The timestamps are written rather than travelled to: an access token does not survive the
     * hours involved, so a time-travelling version of this would be asserting about the session
     * instead of about punctuality.
     */
    $session->forceFill([
        'departed_at' => $trip->departure_at,
        'completed_at' => $trip->promised_arrival_at->addMinutes($minutesLate),
    ])->save();

    app(CompleteTripAction::class)->recomputeOnTimeRate(DriverProfile::sole());
}

it('counts a run that arrives within the tolerance as on time', function () {
    arriveMinutesLate($this->driverToken, $this->tripId, 4);

    $profile = DriverProfile::sole();

    expect((float) $profile->on_time_rate)->toBe(100.0)
        ->and($profile->on_time_sample_size)->toBe(1)
        ->and($profile->on_time_metric_version)
        ->toBe((int) config('rafeeq.trip.on_time_metric_version'));
});

/**
 * 🔴 The decision itself: she left on time and still missed the promise, so it counts against her.
 */
it('counts a late arrival against her even when she left on time', function () {
    arriveMinutesLate($this->driverToken, $this->tripId, 25);

    expect((float) DriverProfile::sole()->on_time_rate)->toBe(0.0);
});

/**
 * No promise, no breach — the principle the column was created under. A commute published without
 * an arrival time is judged exactly as it was before promises existed.
 */
it('judges a day with no promise on its departure alone', function () {
    ScheduledTrip::query()->whereKey($this->tripId)->update(['promised_arrival_at' => null]);

    // Driven directly rather than through the helper, which reads the promise to position the
    // arrival — there isn't one here, which is the whole point of the test.
    journeyCompleted($this->driverToken, $this->tripId);

    $trip = ScheduledTrip::findOrFail($this->tripId);
    $session = TripSession::query()->where('scheduled_trip_id', $this->tripId)->sole();

    // Hours late against nothing, and still on time, because she promised nothing.
    $session->forceFill([
        'departed_at' => $trip->departure_at,
        'completed_at' => $trip->departure_at->addHours(4),
    ])->save();

    app(CompleteTripAction::class)->recomputeOnTimeRate(DriverProfile::sole());

    expect((float) DriverProfile::sole()->on_time_rate)->toBe(100.0);
});

/**
 * Leaving late still fails on its own, so adding arrival did not quietly weaken the old rule.
 */
it('still counts a late departure against her', function () {
    arriveMinutesLate($this->driverToken, $this->tripId, 0);

    $trip = ScheduledTrip::findOrFail($this->tripId);

    TripSession::query()->where('scheduled_trip_id', $this->tripId)->sole()->forceFill([
        'departed_at' => $trip->departure_at->addMinutes(45),
        'completed_at' => $trip->promised_arrival_at,
    ])->save();

    app(CompleteTripAction::class)->recomputeOnTimeRate(DriverProfile::sole());

    expect((float) DriverProfile::sole()->on_time_rate)->toBe(0.0);
});

/**
 * 🔴 The defect this slice uncovered: the number a stranger reads has to be the one we computed.
 */
it('shows a passenger the rate that was actually computed for the driver', function () {
    arriveMinutesLate($this->driverToken, $this->tripId, 30);

    $driver = DriverProfile::sole()->user;

    $data = test()->withToken($this->paxToken)
        ->getJson("/api/v1/users/{$driver->getKey()}/public-profile")
        ->assertOk()->json('data');

    expect((float) $data['asDriver']['onTimeRate'])->toBe(0.0)
        // And how it was computed travels with it, so two drivers at the same percentage under
        // different rules are not silently compared.
        ->and($data['asDriver']['onTimeMetricVersion'])
        ->toBe((int) config('rafeeq.trip.on_time_metric_version'))
        ->and($data['asDriver']['onTimeSampleSize'])->toBe(1);
});
