<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

/**
 * 🔴 Paging on the lists that grow with use.
 *
 * Every one of these was `->get()` with no limit, which is not a slow endpoint — it is an
 * endpoint that answers a driver's second year with every booking they have ever had, in
 * one response, on a phone. The first time it happens is in production and to the people
 * who have used the product longest.
 *
 * The paging numbers go in `meta`, the key the envelope already reserves, so `data` reads
 * the same whether or not an endpoint grew paging.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344');

    // A recurring member accrues a booking per travelling day — the shape that made
    // these lists grow without bound.
    $requestId = requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $this->bookingCount = Booking::count();

    expect($this->bookingCount)->toBeGreaterThan(12, 'A month of weekdays should be more than one page.');
});

it('answers with one page and says how many there are', function () {
    $response = test()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings?perPage=5')
        ->assertOk();

    $response->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.perPage', 5)
        ->assertJsonPath('meta.total', $this->bookingCount)
        // Stated rather than left for the client to derive from three other numbers.
        ->assertJsonPath('meta.hasMore', true);

    expect($response->json('meta.lastPage'))->toBe((int) ceil($this->bookingCount / 5));
});

it('reaches every row across the pages, once each', function () {
    $seen = [];
    $page = 1;

    do {
        $body = test()->withToken($this->paxToken)
            ->getJson("/api/v1/my-bookings?perPage=5&page={$page}")
            ->assertOk()
            ->json();

        foreach ($body['data'] as $booking) {
            $seen[] = $booking['id'];
        }

        $page++;
    } while ($body['meta']['hasMore']);

    // Nothing skipped and nothing repeated — what an unstable sort breaks, and why every
    // list orders on a unique tiebreak (standard #42).
    expect($seen)->toHaveCount($this->bookingCount)
        ->and($seen)->toBe(array_values(array_unique($seen)));
});

it('says there is no more on the last page', function () {
    $lastPage = (int) ceil($this->bookingCount / 5);

    test()->withToken($this->paxToken)
        ->getJson("/api/v1/my-bookings?perPage=5&page={$lastPage}")
        ->assertOk()
        ->assertJsonPath('meta.hasMore', false)
        ->assertJsonPath('meta.page', $lastPage);
});

/**
 * 🔒 `perPage` comes from the caller, so the ceiling is the part that matters. Without it
 * the limit is advisory and a list endpoint becomes a way to ask the database for
 * everything.
 */
it('refuses to hand over more rows than the ceiling, however many are asked for', function () {
    $max = (int) config('rafeeq.api.max_per_page');

    test()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings?perPage=100000')
        ->assertOk()
        ->assertJsonPath('meta.perPage', $max);
});

it('falls back to the configured default when nothing is asked for', function () {
    test()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings')
        ->assertOk()
        ->assertJsonPath('meta.perPage', (int) config('rafeeq.api.default_per_page'));
});

it('treats a nonsense page size as the smallest usable one', function () {
    // Zero and negatives would otherwise produce an empty page forever, or a database
    // error, depending on the driver.
    foreach (['0', '-5'] as $nonsense) {
        test()->withToken($this->paxToken)
            ->getJson("/api/v1/my-bookings?perPage={$nonsense}")
            ->assertOk()
            ->assertJsonPath('meta.perPage', 1);
    }
});

it('pages the driver side too, which is the longest list in the API', function () {
    test()->withToken($this->driverToken)
        ->getJson('/api/v1/driver/bookings?perPage=4')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', $this->bookingCount);
});

/**
 * A page past the end is empty rather than an error: a client holding a stale page number
 * after rows were removed should see "nothing here" and go back, not a 404 or a 500.
 */
it('answers a page past the end with an empty list', function () {
    test()->withToken($this->paxToken)
        ->getJson('/api/v1/my-bookings?page=9999')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.hasMore', false);
});

it('leaves naturally bounded lists unpaged', function () {
    /*
     * Deliberately NOT paged, and worth pinning so nobody "fixes" them: a person has a
     * handful of devices and a driver a config-capped number of vehicles. Paging them
     * would add a `meta` the client must read and a second request it must make, to
     * split a list that cannot grow.
     */
    test()->withToken($this->driverToken)->getJson('/api/v1/driver/vehicles')
        ->assertOk()
        ->assertJsonMissingPath('meta');

    test()->withToken($this->driverToken)->getJson('/api/v1/account/devices')
        ->assertOk()
        ->assertJsonMissingPath('meta');
});
