<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Message;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\ContactInfoDetector;
use App\Domains\Safety\Models\Incident;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

/**
 * Trip chat (Phase 12, Chapter 11): one conversation per booking, between that day's passenger
 * and driver, open around that day's journey only.
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

    // Inside the window: leaving in half an hour.
    runLeavingIn($this->tripId, 30);
});

function sendChat(string $token, string $bookingId, string $body = 'أنا عند البوابة ٢')
{
    return test()->withToken($token)->postJson("/api/v1/bookings/{$bookingId}/messages", ['body' => $body]);
}

it('lets the passenger and the driver talk about the pickup', function () {
    sendChat($this->paxToken, $this->booking->id)
        ->assertStatus(201)
        ->assertJsonPath('data.mine', true)
        ->assertJsonPath('data.containsContactInfo', false);

    test()->withToken($this->driverToken)->getJson("/api/v1/bookings/{$this->booking->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.body', 'أنا عند البوابة ٢')
        ->assertJsonPath('data.0.mine', false)
        ->assertJsonPath('meta.canSend', true);

    // The driver opened it, so the passenger sees it read.
    test()->withToken($this->paxToken)->getJson("/api/v1/bookings/{$this->booking->id}/messages")
        ->assertJsonPath('data.0.readAt', fn ($value) => $value !== null);
});

/**
 * 🔒 Who wrote, never what: the body would be on a lock screen.
 */
it('tells the other person a message arrived, without the message', function () {
    sendChat($this->paxToken, $this->booking->id, 'call me 01012345678');

    $note = Notification::query()->where('user_id', $this->driver->id)->where('type', 'chat_message')->where('channel', 'in_app')->sole();

    expect($note->title.$note->body)->not->toContain('0101')
        ->and($note->data)->toBe(['bookingId' => $this->booking->id]);
});

it('keeps everybody else out, with a 404', function () {
    $strangerToken = verifiedPassenger('01555556666', device: 'stranger');

    test()->withToken($strangerToken)->getJson("/api/v1/bookings/{$this->booking->id}/messages")->assertNotFound();
    sendChat($strangerToken, $this->booking->id)->assertNotFound();
});

it('is not open days before the journey', function () {
    // The departure moved, not the date: another generated day already holds that date.
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->addDays(3)]);

    sendChat($this->paxToken, $this->booking->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHAT_NOT_OPEN')
        ->assertJsonPath('error.fields.opensAt.0', fn ($value) => $value !== null);

    test()->withToken($this->paxToken)->getJson("/api/v1/bookings/{$this->booking->id}/messages")
        ->assertOk()
        ->assertJsonPath('meta.canSend', false);
});

it('stays open for the grace period after the journey, and then closes', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    // "I left my umbrella in your car."
    sendChat($this->paxToken, $this->booking->id, 'نسيت الشمسية')->assertStatus(201);

    // The run's end moved back rather than the clock forward: travelling two hours would expire
    // the fifteen-minute access token and the test would be about that instead.
    TripSession::query()->where('scheduled_trip_id', $this->tripId)
        ->update(['completed_at' => now()->subMinutes(121)]);

    sendChat($this->paxToken, $this->booking->id)->assertStatus(409);
});

it('closes a run nobody completed, after the cap', function () {
    PlatformSetting::updateOrCreate(['setting_key' => 'chat.max_hours_after_departure'], ['setting_value' => 2, 'value_type' => 'integer']);
    PlatformSetting::updateOrCreate(['setting_key' => 'chat.grace_minutes'], ['setting_value' => 0, 'value_type' => 'integer']);

    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subHours(3)]);

    sendChat($this->paxToken, $this->booking->id)->assertStatus(409);
});

it('has no conversation on a cancelled booking', function () {
    $this->booking->forceFill(['status' => 'cancelled_by_passenger'])->save();

    sendChat($this->paxToken, $this->booking->id)->assertStatus(409);
});

/**
 * 🔒 A block looks exactly like a closed window — "you cannot message her because she blocked
 * you" tells somebody they were blocked.
 */
it('refuses a blocked pair with the same answer as a closed window', function () {
    test()->withToken($this->driverToken)
        ->postJson('/api/v1/safety/blocked-users', ['userId' => $this->pax->id])
        ->assertStatus(201);

    sendChat($this->paxToken, $this->booking->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHAT_NOT_OPEN');

    test()->withToken($this->paxToken)->getJson("/api/v1/bookings/{$this->booking->id}/messages")
        ->assertJsonPath('meta.canSend', false);
});

it('flags a phone number or an email, and delivers it', function (string $body) {
    sendChat($this->paxToken, $this->booking->id, $body)
        ->assertStatus(201)
        ->assertJsonPath('data.containsContactInfo', true);
})->with([
    'local' => 'كلمني على 01012345678',
    'arabic digits' => 'رقمي ٠١٠١٢٣٤٥٦٧٨',
    'spaced' => 'call 010 1234 5678',
    'international' => '+20 10 1234 5678',
    'email' => 'mail me at sara@example.com',
]);

it('does not flag ordinary pickup talk', function (string $body) {
    expect(ContactInfoDetector::contains($body))->toBeFalse();
})->with(['I am at gate 2', 'خمس دقايق وجاية', 'Point 90 Mall, entrance 3', 'running 10 min late']);

it('lets the recipient report a message, which reaches the safety desk', function () {
    $messageId = sendChat($this->paxToken, $this->booking->id, 'رسالة مسيئة')->json('data.id');

    $incidentId = test()->withToken($this->driverToken)
        ->postJson("/api/v1/messages/{$messageId}/report", ['reason' => 'Insulting language'])
        ->assertStatus(201)
        ->json('data.incidentId');

    $incident = Incident::query()->whereKey($incidentId)->sole();

    expect($incident->reported_user_id)->toBe($this->pax->id)
        ->and($incident->booking_id)->toBe($this->booking->id)
        ->and($incident->description)->toContain('رسالة مسيئة')
        ->and(Message::query()->whereKey($messageId)->sole()->flagged_reason)->toBe('Insulting language');
});

it('does not let anybody report their own message', function () {
    $messageId = sendChat($this->paxToken, $this->booking->id)->json('data.id');

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/messages/{$messageId}/report", ['reason' => 'test'])
        ->assertNotFound();
});

it('limits how fast one person can send', function () {
    PlatformSetting::updateOrCreate(['setting_key' => 'chat.messages_per_minute'], ['setting_value' => 5, 'value_type' => 'integer']);

    foreach (range(1, 5) as $i) {
        sendChat($this->paxToken, $this->booking->id, "message {$i}")->assertStatus(201);
    }

    sendChat($this->paxToken, $this->booking->id, 'one too many')->assertStatus(429);
});
