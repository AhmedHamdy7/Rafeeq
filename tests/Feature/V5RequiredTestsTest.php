<?php

/**
 * Feature 03 v5 asks for tests twice, by number and by name, and this is where each one lives.
 *
 * > *"## 6. Acceptance tests — required"* — the twelve in the route and maps contract.
 * > *"## Required concurrency/integration tests"* — the twelve in the inherited v3 baseline.
 *
 * Two separate lists, each numbered 1–12, so every row below says which one it answers. They are
 * asked for by name rather than as properties to satisfy however we like.
 *
 * 🔴 **This file is a map, not a second implementation.** Every one of the twenty-four is covered by
 * a test that was written where the behaviour lives, next to the code it constrains — which is where
 * a test belongs and where somebody changing that code will see it. Re-testing them here would
 * create a second set that drifts from the first, and the day they disagreed neither would be
 * trustworthy.
 *
 * What it does instead is hold the LINK. v5 asks for these specifically, and three things can go
 * wrong silently: a test is renamed and the requirement loses its evidence, a requirement is
 * believed covered because a nearby test sounds similar, or a test is deleted with the feature it
 * grew from while the requirement stays. So each row below names a real test, and the assertion is
 * that the named test still exists and still runs.
 *
 * ⚠️ It deliberately does not assert that the named test PASSES — the suite does that. A map that
 * re-ran its targets would double the suite's slowest tests for no information.
 */

/** v5 § 6 — the route and maps acceptance tests. */
$routeContract = [
    '1. Start + End on the map, polyline shown, publish succeeds with zero suggested points' => [
        'tests/Feature/Commute/PublishCommuteTest.php' => 'publishes: computes the route once, writes the search box, and generates days',
        'tests/Feature/Trip/NavigationRouteTest.php' => 'is the driver’s start and end alone when nobody is confirmed',
        'tests/Feature/Booking/SeatRequestPointsTest.php' => 'lets her request a seat on a commute that advertises no suggested points at all',
    ],
    '2. Suggested pickups are suggestions, not mandatory stops, until somebody is confirmed on one' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => 'leaves an advertised meeting point out of the route until somebody picks it',
    ],
    '3. A request missing either point is refused and not created' => [
        'tests/Feature/Booking/SeatRequestPointsTest.php' => [
            'refuses a request with no pickup point',
            'refuses a request with no drop-off point',
            'does not fill the points in from the driver’s route endpoints',
        ],
    ],
    '4. A suggestion pickup and a custom drop-off: both precise points, with their source types' => [
        'tests/Feature/Booking/SeatRequestPointsTest.php' => 'records a driver suggestion and a custom pin as different sources on one request',
    ],
    '5. Preview may show 4 points, clearly pending; the official route stays at 2' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => 'previews a pending request’s impact without touching the official route',
    ],
    '6. Accepting A gives Start + A pickup + A drop-off + End, and a map link or a documented fallback' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => 'grows to four points when one passenger is confirmed',
    ],
    '7. Accepting B gives 6 points, each pickup before her own drop-off; the order may interleave' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => [
            'interleaves two passengers and still keeps each pickup before her own drop-off',
            'never sets a passenger down before it has collected her',
        ],
    ],
    '8. A refused passenger’s points never appear in the confirmed itinerary' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => 'never puts a refused passenger’s points in the route',
    ],
    '9. A counteroffer moves nothing official until she accepts it in a structured way' => [
        'tests/Feature/Booking/CounterofferTest.php' => [
            'records the driver’s proposed pickup and leaves the agreed points alone',
            'creates no booking while the counteroffer is unanswered',
        ],
    ],
    '10. A rider who would miss an agreed arrival, or break the lock, is blocked — never silently seated' => [
        'tests/Feature/Commute/ScheduleLockTest.php' => [
            'refuses a seat inside the window when the rider would make the commute late',
            'allows the same seat while the day is still outside the window',
        ],
    ],
    '11. A cancelled booking drops its stops and recalculates, while the audit snapshot remains' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => 'removes a cancelled passenger’s stops and keeps the booking’s own snapshot',
    ],
    '12. Past the waypoint limit: no crash and no hidden truncation — an explicit fallback instead' => [
        'tests/Feature/Trip/NavigationRouteTest.php' => [
            'refuses to pretend one map link can carry an itinerary past the waypoint limit',
            'overlaps the fallback segments so no leg goes unnavigated',
        ],
    ],
];

/** v3 baseline, carried into v5 — the concurrency and integration tests. */
$concurrency = [
    '1. 50 eligible waiting-list members accept 1 seat concurrently: exactly one confirmed' => [
        'tests/Feature/Booking/SeatConcurrencyTest.php' => 'allows only one booking for the last seat',
        'tests/Feature/Booking/WaitlistPromotionTest.php' => 'gives the seat to the first acceptance and tells the rest it is gone',
    ],
    '2. Same passenger confirming overlapping commutes: at most one, except an atomic switch' => [
        'tests/Feature/Booking/SeatConcurrencyTest.php' => 'still refuses two live bookings for one person on one day',
        'tests/Feature/Booking/BookingSwitchTest.php' => 'moves her seat in one step, leaving exactly one confirmed booking',
    ],
    '3. Pause/resume, seat release and occurrence materialization concurrently: no duplicates' => [
        'tests/Feature/Commute/ScheduledTripGenerationTest.php' => 'is idempotent across repeated runs',
        'tests/Feature/Booking/WaitlistPromotionTest.php' => 'does not offer the same seat to the same person twice',
    ],
    '4. Trip starts while an offer is open: stays if reachable, closes once unreachable' => [
        'tests/Feature/Booking/DuringRideRequestTest.php' => 'keeps an open offer alive after the run sets off, and closes it once the car passes her',
    ],
    '5. Switches only today: old future recurring seats untouched; opts out of the old waiting list' => [
        'tests/Feature/Booking/RecurringSwitchScopeTest.php' => 'leaves every other morning alone by default',
    ],
    '6. Switches all future weekdays and the old seat is taken: no automatic reclaim' => [
        'tests/Feature/Booking/RecurringSwitchScopeTest.php' => 'releases every future morning she held on the old commute',
    ],
    '7. Driver-delay switch has no passenger fee; voluntary passes the cancellation policy' => [
        'tests/Feature/Booking/BookingSwitchTest.php' => 'charges nothing and blames the driver when the switch is her fault',
    ],
    '8. GPS arrival early: grace waits; a dispute blocks no-show; late boarder warned post-trip' => [
        'tests/Feature/Trip/AttendanceTest.php' => 'records a no-show as an explicit act, not by omission',
        'tests/Feature/Trip/MemberWarningsTest.php' => 'tells her once she has been set down',
    ],
    '9. Driver starts at +12m, not expired at +20m; never-started expires at +20m' => [
        'tests/Feature/Trip/DelayLadderTest.php' => 'leaves a run that set off late alone, and keeps the violation it earned',
    ],
    '10. Change proposal partial acceptance never applies; a revision resets all approvals' => [
        'tests/Feature/Commute/ChangeProposalTest.php' => 'changes nothing while anybody has not answered',
    ],
    '11. Price edits preserve existing agreed booking prices' => [
        'tests/Feature/Commute/ScheduledTripGenerationTest.php' => 'snapshots the seats and price so a later change cannot move a booked day',
    ],
    '12. Role suspension / document expiry blocks Publish and Start; passenger actions remain' => [
        'tests/Feature/Driver/DriverEligibilityTest.php' => null,
    ],
];

it('keeps a test behind every requirement v5 asks for by name', function (string $requirement, array $evidence) {
    foreach ($evidence as $file => $names) {
        expect(file_exists(base_path($file)))->toBeTrue(
            "v5 requires: {$requirement}\nIts evidence was {$file}, which no longer exists."
        );

        if ($names === null) {
            // A whole file is the evidence, rather than one named case.
            continue;
        }

        $contents = file_get_contents(base_path($file));

        foreach ((array) $names as $name) {
            expect(str_contains($contents, $name))->toBeTrue(
                "v5 requires: {$requirement}\nIts evidence was \"{$name}\" in {$file}, which is no longer there.\n"
                .'Either restore the test, or point this map at whatever replaced it — but do not delete the row: '
                .'v5 asks for this one by number.'
            );
        }
    }
})->with(collect([
    '§6 route contract' => $routeContract,
    'concurrency' => $concurrency,
])->flatMap(fn (array $list, string $section) => collect($list)
    ->map(fn (array $evidence, string $requirement) => ["{$section} — {$requirement}", $evidence])
    ->values()
)->all());
