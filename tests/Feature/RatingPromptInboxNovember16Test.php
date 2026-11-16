<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
});

function rp16As(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function rp16Ride(string $driverToken, string $paxToken): array
{
    $commuteId = readyCommute($driverToken, Vehicle::query()->where('is_active', true)->sole()->id);
    rp16As($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();
    $tripId = ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->first()->id;

    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    test()->flushHeaders();
    journeyCompleted($driverToken, $tripId);

    return [$commuteId, $tripId, $bookingId];
}

it('asks again as a first ride after both accounts are reset', function () {
    $driver = approvedDriver('01020001009', 'driver');
    $pax = verifiedPassenger('01020001001');

    [, , $first] = rp16Ride($driver, $pax);
    rp16As($pax)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt.reason', 'first_ride');
    rateBooking($pax, $first)->assertStatus(201);

    rp16As($driver)->postJson('/api/v1/testing/reset')->assertOk();
    rp16As($pax)->postJson('/api/v1/testing/reset')->assertOk();

    [, , $second] = rp16Ride($driver, $pax);

    rp16As($pax)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.bookingId', $second)
        ->assertJsonPath('data.ratingPrompt.reason', 'first_ride');
});

it('asks after a ride even with her next commute booked with the same driver', function () {
    $driver = approvedDriver('01020001009', 'driver');
    $pax = verifiedPassenger('01020001001');

    $commuteId = readyCommute($driver, Vehicle::query()->where('is_active', true)->sole()->id);
    rp16As($driver)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();
    $trips = ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->get();

    approveSeat($driver, requestSeat($pax, $commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id'));
    $bookingId = Booking::query()->where('scheduled_trip_id', $trips[0]->id)->value('id');
    expect(Booking::query()->count())->toBeGreaterThan(1);

    test()->flushHeaders();
    journeyCompleted($driver, $trips[0]->id);

    rp16As($pax)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.bookingId', $bookingId)
        ->assertJsonPath('data.ratingPrompt.reason', 'first_ride');
});

it('waits a week after she rated the same driver, and asks once the rated ride is aged 8 days', function () {
    $driver = approvedDriver('01020001009', 'driver');
    $pax = verifiedPassenger('01020001001');

    $commuteId = readyCommute($driver, Vehicle::query()->where('is_active', true)->sole()->id);
    rp16As($driver)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();
    $trips = ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->get();

    approveSeat($driver, requestSeat($pax, $commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id'));
    $first = Booking::query()->where('scheduled_trip_id', $trips[0]->id)->value('id');
    $second = Booking::query()->where('scheduled_trip_id', $trips[1]->id)->value('id');

    test()->flushHeaders();
    journeyCompleted($driver, $trips[0]->id);
    rateBooking($pax, $first)->assertStatus(201);
    rp16As($pax)->postJson("/api/v1/testing/bookings/{$first}/age", ['days' => 1])->assertOk();

    runLeavingIn($trips[1]->id, 10, 1);
    rp16As($driver)->postJson("/api/v1/trips/{$trips[1]->id}/start")->assertStatus(201);
    rp16As($driver)->postJson("/api/v1/trips/{$trips[1]->id}/status", ['status' => 'EN_ROUTE'])->assertOk();
    rp16As($driver)->postJson("/api/v1/trips/{$trips[1]->id}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    rp16As($driver)->postJson("/api/v1/trips/{$trips[1]->id}/complete")->assertOk();

    rp16As($pax)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt', null);

    $why = collect(rp16As($pax)->getJson('/api/v1/testing/rating-prompt')->assertOk()->json('data.bookings'))->keyBy('bookingId');

    expect($why[$second]['heldBy'])->toBe('asked_within_week')
        ->and($why[$second]['reason'])->toBeNull()
        ->and($why[$second]['weeklyFrom'])->not->toBeNull()
        ->and($why[$first]['heldBy'])->toBe('rated');

    rp16As($pax)->postJson("/api/v1/testing/bookings/{$first}/age", ['days' => 7])->assertOk();

    rp16As($pax)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.bookingId', $second)
        ->assertJsonPath('data.ratingPrompt.reason', 'weekly');

    $why = collect(rp16As($pax)->getJson('/api/v1/testing/rating-prompt')->assertOk()->json('data.bookings'))->keyBy('bookingId');

    expect($why[$second]['heldBy'])->toBeNull()->and($why[$second]['reason'])->toBe('weekly');
});

it('gives every notification a body that stands without its title, in English and Arabic', function () {
    $en = require lang_path('en/notifications.php');
    $ar = require lang_path('ar/notifications.php');
    $placeholders = function (string $text): array {
        preg_match_all('/:([a-z]+)/', $text, $found);
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    };

    foreach (NotificationType::cases() as $type) {
        $key = $type->value;

        foreach (['en' => $en, 'ar' => $ar] as $locale => $lines) {
            expect(trim((string) ($lines[$key]['body'] ?? '')))->not->toBe('', "{$locale}.{$key} has no body");
        }

        expect($placeholders($ar[$key]['body']))->toBe($placeholders($en[$key]['body']), "{$key}: Arabic and English bodies differ");

        if (! in_array($type, [NotificationType::Announcement, NotificationType::StaffMessage], true)) {
            expect(array_diff($placeholders($en[$key]['title']), $placeholders($en[$key]['body'])))
                ->toBe([], "{$key}: the body leaves out what the title names");
        }
    }
});
