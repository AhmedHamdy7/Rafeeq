<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/**
 * The mobile team's bug report of 2026-10-07: every search on the seeded corridor answered
 * `500 "Latitude out of range: 1232.00015"`.
 *
 * 🔴 The seeders stored a pasted polyline that decoded to nonsense, and one commute's geometry
 * failed the whole request. These tests run the report's own request against the real seed data,
 * and pin the two guarantees: seeded routes are readable, and an unreadable one is skipped rather
 * than fatal.
 */
beforeEach(function () {
    fakeOtpSender();
    $this->seed(DatabaseSeeder::class);

    $this->passenger = User::query()->where('phone_e164', '+201033334444')->sole();
});

function reportedSearch(): TestResponse
{
    return test()->actingAs(test()->passenger, 'sanctum')->getJson('/api/v1/search/commutes?'.http_build_query([
        'origin' => ['lat' => 30.0602, 'lng' => 31.4915],
        'destination' => ['lat' => 30.0724, 'lng' => 31.0116],
        'daysMask' => 62,
        'arrivalWindowStart' => '06:45:00',
        'arrivalWindowEnd' => '08:00:00',
        'maxWalkMinutes' => 12,
        'maxDetourMinutes' => 15,
    ]));
}

it('answers the reported request with the seeded commute, not a 500', function () {
    reportedSearch()
        ->assertOk()
        ->assertJsonPath('meta.matched', fn (int $matched) => $matched >= 1);
});

it('skips a commute whose stored route cannot be read, instead of failing the search', function () {
    CommuteOffer::query()->firstOrFail()->forceFill(['route_polyline' => '}_p~iF~ps|U_ulL'])->saveQuietly();

    Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'cannot be read'));

    reportedSearch()->assertOk()->assertJsonPath('meta.matched', 0);
});

it('repairs unreadable routes already in the database', function () {
    $seeded = CommuteOffer::query()->firstOrFail();
    $seeded->forceFill(['route_polyline' => '}_p~iF~ps|U_ulL'])->saveQuietly();

    (require database_path('migrations/2026_10_07_090000_repair_unreadable_commute_routes.php'))->up();

    expect(Polyline::isReadable($seeded->refresh()->route_polyline))->toBeTrue();
    reportedSearch()->assertOk();
});
