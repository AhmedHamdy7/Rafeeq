<?php

/**
 * Feature 03 v5's twelve named concurrency/integration tests, and where each one lives.
 *
 * > *"## Required concurrency/integration tests"* — v5 lists them by number and asks for them by
 * > name rather than describing properties to satisfy however we like.
 *
 * 🔴 **This file is a map, not a second implementation.** Every one of the twelve is covered by a
 * test that was written where the behaviour lives, next to the code it constrains — which is where
 * a test belongs and where somebody changing that code will see it. Re-testing them here would
 * create a second set that drifts from the first, and the day they disagreed neither would be
 * trustworthy.
 *
 * What it does instead is hold the LINK. v5 asks for these twelve specifically, and three things can
 * go wrong silently: a test is renamed and the requirement loses its evidence, a requirement is
 * believed covered because a nearby test sounds similar, or a test is deleted with the feature it
 * grew from while the requirement stays. So each row below names a real test, and the assertion is
 * that the named test still exists and still runs.
 *
 * ⚠️ It deliberately does not assert that the named test PASSES — the suite does that. A map that
 * re-ran its targets would double the suite's slowest tests for no information.
 */
$map = [
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

it('keeps a test behind every one of v5’s twelve named requirements', function (string $requirement, array $evidence) {
    foreach ($evidence as $file => $name) {
        expect(file_exists(base_path($file)))->toBeTrue(
            "v5 requires: {$requirement}\nIts evidence was {$file}, which no longer exists."
        );

        if ($name === null) {
            // A whole file is the evidence, rather than one named case.
            continue;
        }

        expect(str_contains(file_get_contents(base_path($file)), $name))->toBeTrue(
            "v5 requires: {$requirement}\nIts evidence was \"{$name}\" in {$file}, which is no longer there.\n"
            .'Either restore the test, or point this map at whatever replaced it — but do not delete the row: '
            .'v5 asks for this one by number.'
        );
    }
})->with(collect($map)->map(fn (array $evidence, string $requirement) => [$requirement, $evidence])->values()->all());
