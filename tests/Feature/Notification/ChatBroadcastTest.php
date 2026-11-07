<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Events\ChatMessageSent;
use App\Domains\Notification\Events\ChatMessagesRead;
use App\Domains\Notification\Models\Message;
use App\Domains\Safety\Models\BlockedUser;
use App\Http\Resources\ChatMessageResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Chat over the socket: a new message reaches the person it was written to, and reading it reaches
 * the person who wrote it. Both on that person's own `private-user.{id}` channel.
 */
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
});

function postRequestMessage(string $token, string $requestId, string $body = 'أنا عند البوابة ٢')
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/seat-requests/{$requestId}/messages", ['body' => $body]);
}

function openRequestThread(string $token, string $requestId)
{
    return test()->flushHeaders()->withToken($token)
        ->getJson("/api/v1/seat-requests/{$requestId}/messages");
}

it('pushes a request message to the receiver only, naming the thread', function () {
    Event::fake([ChatMessageSent::class]);

    $id = postRequestMessage($this->paxToken, $this->requestId)->assertStatus(201)->json('data.id');

    Event::assertDispatchedTimes(ChatMessageSent::class, 1);
    Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($id) {
        $sent = $event->broadcastWith();

        return $event->broadcastOn()->name === 'private-user.'.$this->driver->id
            && $event->broadcastAs() === 'chat.message'
            && $sent['id'] === $id
            && $sent['payload']['seatRequestId'] === $this->requestId
            && $sent['payload']['bookingId'] === null
            && $sent['payload']['message']['mine'] === false
            && $sent['payload']['message']['body'] === 'أنا عند البوابة ٢';
    });
});

it('pushes a booking message under the booking id', function () {
    approveSeat($this->driverToken, $this->requestId);
    $booking = Booking::sole();
    runLeavingIn($this->tripId, 30);

    Event::fake([ChatMessageSent::class]);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/bookings/{$booking->id}/messages", ['body' => 'أنا قريب'])
        ->assertStatus(201);

    Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($booking) {
        $payload = $event->broadcastWith()['payload'];

        return $event->broadcastOn()->name === 'private-user.'.$this->pax->id
            && $payload['bookingId'] === $booking->id
            && $payload['seatRequestId'] === null;
    });
});

it('sends the same message shape as the API, and no user id', function () {
    Event::fake([ChatMessageSent::class]);

    postRequestMessage($this->paxToken, $this->requestId)->assertStatus(201);

    Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->driver);

        $api = (new ChatMessageResource(Message::sole()->refresh()))->toArray($request);
        $sent = $event->broadcastWith();

        return $sent['payload']['message'] == $api
            && ! str_contains((string) json_encode($sent), $this->pax->id)
            && ! str_contains((string) json_encode($sent), $this->driver->id);
    });
});

it('pushes nothing for a message the server refused', function () {
    Event::fake([ChatMessageSent::class]);

    // A phone number.
    postRequestMessage($this->paxToken, $this->requestId, 'كلمني على 01012345678')->assertStatus(422);

    // 🔒 A blocked pair: the same silence as a closed thread.
    BlockedUser::factory()->create(['blocker_user_id' => $this->driver->id, 'blocked_user_id' => $this->pax->id]);
    postRequestMessage($this->paxToken, $this->requestId)->assertStatus(409);

    Event::assertNotDispatched(ChatMessageSent::class);
});

it('tells the writer once when their messages are read', function () {
    postRequestMessage($this->paxToken, $this->requestId)->assertStatus(201);

    Event::fake([ChatMessagesRead::class]);

    openRequestThread($this->driverToken, $this->requestId)->assertOk();

    Event::assertDispatchedTimes(ChatMessagesRead::class, 1);
    Event::assertDispatched(ChatMessagesRead::class, function (ChatMessagesRead $event) {
        $sent = $event->broadcastWith();

        return $event->broadcastOn()->name === 'private-user.'.$this->pax->id
            && $event->broadcastAs() === 'chat.read'
            && $sent['payload']['seatRequestId'] === $this->requestId
            && $sent['payload']['readAt'] === Message::sole()->refresh()->read_at->toIso8601String();
    });

    // Reopening reads nothing new, so there is nothing to announce.
    openRequestThread($this->driverToken, $this->requestId)->assertOk();

    Event::assertDispatchedTimes(ChatMessagesRead::class, 1);
});

it('does not announce a read when the writer opens their own thread', function () {
    postRequestMessage($this->paxToken, $this->requestId)->assertStatus(201);

    Event::fake([ChatMessagesRead::class]);

    openRequestThread($this->paxToken, $this->requestId)->assertOk();

    Event::assertNotDispatched(ChatMessagesRead::class);
    expect(Message::sole()->refresh()->read_at)->toBeNull();
});

it('keeps the message body out of the queued job', function () {
    postRequestMessage($this->paxToken, $this->requestId, 'سر في الرسالة')->assertStatus(201);

    $message = Message::sole();
    $event = new ChatMessageSent($this->driver->id, $message, $message->conversation()->sole());

    expect(serialize($event))->not->toContain('سر في الرسالة');
});
