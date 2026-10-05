<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The rest of Phase 12's messages: the rating prompt, the last-day reminder, and cancellations.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    approveSeat($this->driverToken, requestSeat($this->paxToken, $commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    $this->booking = Booking::sole();
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

function inboxTypes(User $user): array
{
    return Notification::query()->where('user_id', $user->id)->where('channel', 'in_app')->orderBy('created_at')->orderBy('id')->pluck('type')->all();
}

/**
 * Chapter 9: "A few minutes later both receive a notification: How was your commute today?"
 */
it('asks both sides for a rating when the run completes, the driver once for the run', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    expect(inboxTypes($this->pax))->toContain('rating_due')
        ->and(collect(inboxTypes($this->driver))->filter(fn ($t) => $t === 'rating_due_riders'))->toHaveCount(1)
        ->and(Notification::query()->where('user_id', $this->pax->id)->where('type', 'rating_due')->where('channel', 'in_app')->sole()->data)
        ->toBe(['bookingId' => $this->booking->id]);
});

/**
 * Moves the completed run back so its rating window closes within the next day, without moving
 * the clock (which would expire the fifteen-minute access token).
 */
function windowClosingSoon(string $tripId): void
{
    $windowDays = (int) config('rafeeq.rating.window_days');

    ScheduledTrip::query()->whereKey($tripId)->update(['departure_at' => now()->subDays($windowDays)->addHours(6)]);
}

it('reminds both sides once, on the last day of the window', function () {
    journeyCompleted($this->driverToken, $this->tripId);
    windowClosingSoon($this->tripId);

    test()->artisan('ratings:send-reminders')->assertExitCode(0);
    // Hourly, and idempotent: running again sends nothing new.
    test()->artisan('ratings:send-reminders')->assertExitCode(0);

    expect(collect(inboxTypes($this->pax))->filter(fn ($t) => $t === 'rating_reminder'))->toHaveCount(1)
        ->and(collect(inboxTypes($this->driver))->filter(fn ($t) => $t === 'rating_reminder'))->toHaveCount(1);
});

it('does not remind somebody who already rated', function () {
    journeyCompleted($this->driverToken, $this->tripId);
    rateBooking($this->paxToken, $this->booking->id)->assertStatus(201);
    windowClosingSoon($this->tripId);

    test()->artisan('ratings:send-reminders');

    expect(inboxTypes($this->pax))->not->toContain('rating_reminder')
        // The driver has not rated yet, so the driver is still reminded.
        ->and(inboxTypes($this->driver))->toContain('rating_reminder');
});

it('does not remind early in the window', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    test()->artisan('ratings:send-reminders');

    expect(inboxTypes($this->pax))->not->toContain('rating_reminder');
});

it('tells the driver when a passenger cancels, without the passenger reason', function () {
    test()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->booking->id}/cancel", ['reason' => 'Doctor appointment that morning'])
        ->assertOk();

    $note = Notification::query()->where('user_id', $this->driver->id)->where('type', 'booking_cancelled')->where('channel', 'in_app')->sole();

    expect($note->body)->toContain($this->pax->public_first_name)
        ->and($note->body)->not->toContain('Doctor')
        ->and($note->data)->toBe(['bookingId' => $this->booking->id])
        // The one who cancelled is not told what they just did.
        ->and(inboxTypes($this->pax))->not->toContain('booking_cancelled');
});
