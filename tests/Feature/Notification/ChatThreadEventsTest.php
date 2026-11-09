<?php

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Events\ChatThreadEventAdded;
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
});

function threadEvents(string $token, string $path): array
{
    return collect(test()->flushHeaders()->withToken($token)->getJson($path)->assertOk()->json('meta.events'))
        ->pluck('event')
        ->all();
}

it('opens a request thread with the request', function () {
    $request = SeatRequest::query()->findOrFail($this->requestId);

    test()->withToken($this->paxToken)->getJson("/api/v1/seat-requests/{$this->requestId}/messages")
        ->assertOk()
        ->assertJsonPath('meta.events', [['event' => 'request_sent', 'at' => $request->created_at->toIso8601String()]]);
});

it('adds a counteroffer to the request thread', function () {
    $this->travel(5)->minutes();

    SeatRequest::query()->whereKey($this->requestId)->update([
        'status' => SeatRequestStatus::Counteroffered->value,
        'counteroffered_at' => now(),
    ]);

    expect(threadEvents($this->driverToken, "/api/v1/seat-requests/{$this->requestId}/messages"))
        ->toBe(['request_sent', 'counteroffered']);
});

it('tells the story of the ride in the booking thread, in order', function () {
    approveSeat($this->driverToken, $this->requestId);
    $booking = Booking::sole();
    $path = "/api/v1/bookings/{$booking->id}/messages";

    expect(threadEvents($this->paxToken, $path))->toBe(['booking_confirmed']);

    $this->travel(1)->minutes();
    underway($this->driverToken, $this->tripId);
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers", ['bookingId' => $booking->id, ...atPickupOf($booking->id)])
        ->assertStatus(201);

    expect(threadEvents($this->paxToken, $path))->toBe(['booking_confirmed', 'driver_arrived']);

    $this->travel(2)->minutes();
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $booking->id])->assertOk();

    expect(threadEvents($this->driverToken, $path))->toBe(['booking_confirmed', 'driver_arrived', 'picked_up']);

    $this->travel(5)->minutes();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $events = test()->flushHeaders()->withToken($this->paxToken)->getJson($path)->json('meta.events');

    expect(array_column($events, 'event'))->toBe(['booking_confirmed', 'driver_arrived', 'picked_up', 'ride_completed'])
        ->and($events[2]['at'])->toBe($booking->attendance()->sole()->checked_in_at->toIso8601String());
});

it('pushes each ride moment to both people on the socket', function () {
    approveSeat($this->driverToken, $this->requestId);
    $booking = Booking::sole();
    underway($this->driverToken, $this->tripId);

    Event::fake([ChatThreadEventAdded::class]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/wait-timers", ['bookingId' => $booking->id, ...atPickupOf($booking->id)])
        ->assertStatus(201);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $booking->id])->assertOk();

    foreach ([$this->pax->id, $this->driver->id] as $userId) {
        Event::assertDispatched(ChatThreadEventAdded::class, function (ChatThreadEventAdded $event) use ($userId, $booking) {
            $sent = $event->broadcastWith();

            return $event->broadcastOn()->name === 'private-user.'.$userId
                && $event->broadcastAs() === 'chat.event'
                && $sent['payload']['bookingId'] === $booking->id
                && $sent['payload']['seatRequestId'] === null
                && $sent['payload']['event']['event'] === 'picked_up';
        });
    }

    Event::assertDispatchedTimes(ChatThreadEventAdded::class, 4);
});

it('ends her ride in the thread when the driver drops her off', function () {
    approveSeat($this->driverToken, $this->requestId);
    $booking = Booking::sole();
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $booking->id])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();

    $this->travel(5)->minutes();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $booking->id])->assertOk();

    expect(threadEvents($this->paxToken, "/api/v1/bookings/{$booking->id}/messages"))
        ->toBe(['booking_confirmed', 'picked_up', 'ride_completed']);
});
