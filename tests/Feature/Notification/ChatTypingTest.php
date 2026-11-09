<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Events\ChatTyping;
use App\Domains\Safety\Models\BlockedUser;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;
    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->requestId = requestSeat($this->paxToken, $commuteId, ['scheduledTripId' => $this->tripId])->json('data.id');

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();

    Event::fake([ChatTyping::class]);
});

function typeOnRequest(string $token, string $requestId)
{
    return test()->flushHeaders()->withToken($token)->postJson("/api/v1/seat-requests/{$requestId}/typing");
}

function typeOnBooking(string $token, string $bookingId)
{
    return test()->flushHeaders()->withToken($token)->postJson("/api/v1/bookings/{$bookingId}/typing");
}

it('tells the other person on a request thread, straight to the socket', function () {
    typeOnRequest($this->paxToken, $this->requestId)->assertStatus(202);

    Event::assertDispatched(ChatTyping::class, function (ChatTyping $event) {
        $sent = $event->broadcastWith();

        return $event->broadcastOn()->name === 'private-user.'.$this->driver->id
            && $event->broadcastAs() === 'chat.typing'
            && $sent['type'] === 'chat.typing'
            && $sent['payload'] === ['bookingId' => null, 'seatRequestId' => $this->requestId];
    });

    expect(new ChatTyping($this->driver->id, null, $this->requestId))->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('sends at most one signal per interval, and accepts the extra calls quietly', function () {
    typeOnRequest($this->paxToken, $this->requestId)->assertStatus(202);
    typeOnRequest($this->paxToken, $this->requestId)->assertStatus(202);

    Event::assertDispatchedTimes(ChatTyping::class, 1);

    $this->travel(4)->seconds();

    typeOnRequest($this->paxToken, $this->requestId)->assertStatus(202);

    Event::assertDispatchedTimes(ChatTyping::class, 2);
});

it('counts each person separately', function () {
    typeOnRequest($this->paxToken, $this->requestId)->assertStatus(202);
    typeOnRequest($this->driverToken, $this->requestId)->assertStatus(202);

    Event::assertDispatchedTimes(ChatTyping::class, 2);
    Event::assertDispatched(ChatTyping::class, fn (ChatTyping $event) => $event->recipientId === $this->pax->id);
});

it('works on a booking thread inside its window', function () {
    approveSeat($this->driverToken, $this->requestId);
    $booking = Booking::sole();
    runLeavingIn($this->tripId, 30);

    typeOnBooking($this->driverToken, $booking->id)->assertStatus(202);

    Event::assertDispatched(ChatTyping::class, fn (ChatTyping $event) => $event->recipientId === $this->pax->id
        && $event->broadcastWith()['payload'] === ['bookingId' => $booking->id, 'seatRequestId' => null]);
});

it('refuses a closed thread like a send does, and signals nothing', function () {
    approveSeat($this->driverToken, $this->requestId);
    $booking = Booking::sole();
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->addDays(3)]);

    typeOnBooking($this->paxToken, $booking->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHAT_NOT_OPEN');

    typeOnRequest($this->paxToken, $this->requestId)
        ->assertStatus(409)
        ->assertJsonPath('error.fields.status.0', 'approved');

    Event::assertNotDispatched(ChatTyping::class);
});

it('refuses a blocked pair with the same answer as a closed thread', function () {
    BlockedUser::factory()->create(['blocker_user_id' => $this->driver->id, 'blocked_user_id' => $this->pax->id]);

    typeOnRequest($this->paxToken, $this->requestId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHAT_NOT_OPEN')
        ->assertJsonPath('error.fields.status.0', 'pending');

    Event::assertNotDispatched(ChatTyping::class);
});

it('hides both threads from anybody else', function () {
    $strangerToken = verifiedPassenger('01555556666', device: 'stranger');

    typeOnRequest($strangerToken, $this->requestId)->assertNotFound();

    approveSeat($this->driverToken, $this->requestId);
    typeOnBooking($strangerToken, Booking::sole()->id)->assertNotFound();

    Event::assertNotDispatched(ChatTyping::class);
});
