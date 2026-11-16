<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Events\AccountBanned;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trips = ScheduledTrip::query()->orderBy('trip_date')->get();
    $this->tripId = $this->trips->first()->id;
});

function df16As(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function df16Seat(string $driverToken, string $paxToken, string $commuteId, string $tripId): string
{
    return approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));
}

it('F1+F2: gives the rating prompt her pickup time and place, and no unusual kind on a plain ride', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = df16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    journeyCompleted($this->driverToken, $this->tripId);

    $prompt = df16As($paxToken)->getJson('/api/v1/home')->assertOk()->json('data.ratingPrompt');

    expect($prompt['bookingId'])->toBe($bookingId)
        ->and($prompt['reason'])->toBe('first_ride')
        ->and($prompt['unusualKind'])->toBeNull()
        ->and($prompt['pickupLocal'])->toMatch('/^\d{2}:\d{2}$/')
        ->and($prompt['pickupLabel'])->toBe(Booking::query()->findOrFail($bookingId)->pickup_label);
});

it('F1: names the unusual case when the ride ended early', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = df16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    journeyCompleted($this->driverToken, $this->tripId);
    Booking::query()->whereKey($bookingId)->update(['status' => BookingStatus::EndedEarly->value]);

    df16As($paxToken)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.reason', 'unusual')
        ->assertJsonPath('data.ratingPrompt.unusualKind', 'ended_early');
});

it('F6: makes a rating prompt of a given reason until she dismisses it', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = df16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    journeyCompleted($this->driverToken, $this->tripId);

    df16As($paxToken)->postJson("/api/v1/testing/bookings/{$bookingId}/rating-prompt", ['reason' => 'weekly'])
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.reason', 'weekly')
        ->assertJsonPath('data.ratingPrompt.unusualKind', null);

    df16As($paxToken)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt.reason', 'weekly');

    df16As($paxToken)->postJson("/api/v1/testing/bookings/{$bookingId}/rating-prompt", ['reason' => 'unusual', 'unusualKind' => 'late'])
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.unusualKind', 'late');

    df16As($paxToken)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.bookingId', $bookingId)
        ->assertJsonPath('data.ratingPrompt.reason', 'unusual')
        ->assertJsonPath('data.ratingPrompt.unusualKind', 'late');

    df16As($paxToken)->postJson("/api/v1/bookings/{$bookingId}/rating-prompt/dismiss")->assertOk();

    df16As($paxToken)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt', null);
});

it('F6: refuses a booking that cannot be rated, and an unusual prompt with no kind', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = df16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    df16As($paxToken)->postJson("/api/v1/testing/bookings/{$bookingId}/rating-prompt", ['reason' => 'weekly'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_NOT_RATEABLE');

    df16As($paxToken)->postJson("/api/v1/testing/bookings/{$bookingId}/rating-prompt", ['reason' => 'unusual'])
        ->assertStatus(422);
});

it('F3: lists her next days in one group, her own cancels included when asked', function () {
    $paxToken = verifiedPassenger('01112223344');
    $requestId = requestSeat($paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');
    approveSeat($this->driverToken, $requestId);

    $days = Booking::query()
        ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
        ->orderBy('scheduled_trips.departure_at')
        ->select('bookings.*')
        ->get();
    expect($days->count())->toBeGreaterThan(1);

    $groupId = $days->first()->commute_group_id;
    expect($groupId)->not->toBeNull();

    $cancelled = $days[1]->id;
    df16As($paxToken)->patchJson("/api/v1/bookings/{$cancelled}/cancel", ['reason' => 'schedule_changed'])->assertOk();

    $plain = df16As($paxToken)->getJson("/api/v1/my-bookings?scope=upcoming&groupId={$groupId}&perPage=50")->assertOk()->json('data');
    expect(collect($plain)->pluck('id'))->not->toContain($cancelled)
        ->and(count($plain))->toBe($days->count() - 1);

    $all = df16As($paxToken)->getJson("/api/v1/my-bookings?scope=upcoming&groupId={$groupId}&includeCancelled=1&perPage=50")->assertOk()->json('data');
    expect(collect($all)->pluck('id')->all())->toBe($days->pluck('id')->all())
        ->and($all[1]['status'])->toBe('CANCELLED_BY_PASSENGER');

    df16As($paxToken)->getJson('/api/v1/my-bookings?scope=upcoming&groupId=01NOTAGROUP&includeCancelled=1')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('F5: names the contact an automatic share went to', function () {
    $paxToken = verifiedPassenger('01112223344');
    df16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    $chosen = df16As($paxToken)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Hoda', 'phone' => '01099997777'])->assertStatus(201)->json('data.id');

    underway($this->driverToken, $this->tripId);

    df16As($paxToken)->putJson('/api/v1/safety/auto-share', ['enabled' => true, 'contactId' => $chosen])->assertOk();

    df16As($paxToken)->getJson('/api/v1/safety/live-shares')
        ->assertOk()
        ->assertJsonPath('data.0.source', 'AUTO')
        ->assertJsonPath('data.0.sharedWithContactId', $chosen)
        ->assertJsonPath('data.0.sharedWithName', 'Hoda');
});

it('F7: fires account.banned on her own channel without banning her', function () {
    Event::fake([AccountBanned::class]);

    $paxToken = verifiedPassenger('01112223344');
    df16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);
    underway($this->driverToken, $this->tripId);

    df16As($paxToken)->postJson('/api/v1/testing/realtime/account-banned')->assertStatus(202)->assertJsonPath('data.sent', true);
    df16As($paxToken)->postJson('/api/v1/testing/realtime/account-banned', ['activeTripId' => null])->assertStatus(202);

    $events = Event::dispatched(AccountBanned::class)->map(fn (array $args) => $args[0]);

    expect($events)->toHaveCount(2)
        ->and($events[0]->activeTripId)->toBe($this->tripId)
        ->and($events[0]->broadcastAs())->toBe('account.banned')
        ->and($events[0]->broadcastOn()->name)->toBe('private-user.'.$events[0]->userId)
        ->and($events[1]->activeTripId)->toBeNull();

    df16As($paxToken)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.suspension', null);
});

it('F7: a test ban broadcasts account.banned too', function () {
    Event::fake([AccountBanned::class]);

    $paxToken = verifiedPassenger('01112223344');

    df16As($paxToken)->postJson('/api/v1/testing/account/ban')->assertOk();

    Event::assertDispatched(AccountBanned::class, fn (AccountBanned $event) => $event->broadcastWith()['type'] === 'account.banned'
        && $event->broadcastWith()['payload']['permanent'] === true
        && $event->broadcastWith()['payload']['caseNumber'] !== null);
});
