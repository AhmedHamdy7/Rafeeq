<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use Database\Seeders\DemoDataSeeder;

/**
 * `demo:refresh` — a long-lived demo environment where the calendar has moved on.
 *
 * The mobile team's Home showed `nextJourney: null` and `topMatches: []` a few days after seeding:
 * every tester's one booking was on a day already past, and their saved requests had never been
 * matched. This is that situation, then the command that fixes it.
 */
beforeEach(function () {
    fakeOtpSender();
    $this->seed(DemoDataSeeder::class);

    $this->omar = User::query()->where('phone_e164', '+201014531739')->sole();
});

it('gives a fresh demo environment matches on Home', function () {
    $this->actingAs($this->omar, 'sanctum')->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.topMatches', fn (array $matches) => count($matches) >= 1);
});

it('puts a tester whose trips are all in the past back on an upcoming one', function () {
    // The calendar moves on: every one of Omar's booked days is now behind him.
    ScheduledTrip::query()
        ->whereIn('id', Booking::query()->where('passenger_user_id', $this->omar->id)->select('scheduled_trip_id'))
        ->update(['departure_at' => now()->subDays(2)]);

    $this->actingAs($this->omar, 'sanctum')->getJson('/api/v1/home')->assertJsonPath('data.nextJourney', null);

    $this->artisan('demo:refresh')->expectsOutputToContain('seated on an upcoming trip')->assertSuccessful();

    $this->actingAs($this->omar, 'sanctum')->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.nextJourney.driver.publicFirstName', fn (?string $name) => $name !== null);
});

it('is refused in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:refresh')->assertFailed();
});
