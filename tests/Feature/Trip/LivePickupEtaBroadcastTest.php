<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Events\PickupEtaUpdated;
use App\Domains\Trip\Events\TripLocationUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

it('broadcasts the heading with the car and her pickup time on her own channel', function () {
    underway($this->driverToken, $this->tripId);
    Event::fake([TripLocationUpdated::class, PickupEtaUpdated::class]);

    reportPosition($this->driverToken, $this->tripId, [[...position(agoSeconds: 2), 'heading' => 87.6]])->assertOk();

    Event::assertDispatched(TripLocationUpdated::class, fn (TripLocationUpdated $event): bool => $event->broadcastWith()['heading'] === 88
        && $event->broadcastWith()['speedKmh'] === 48);

    $booking = Booking::query()->findOrFail($this->bookingId);
    $live = test()->withToken($this->paxToken)->getJson("/api/v1/trips/{$this->tripId}/location")->assertOk()->json('data');

    Event::assertDispatched(PickupEtaUpdated::class, function (PickupEtaUpdated $event) use ($booking, $live): bool {
        $payload = $event->broadcastWith()['payload'];

        return $event->broadcastOn()->name === 'private-user.'.$booking->passenger_user_id
            && $event->broadcastAs() === 'trip.pickup_eta'
            && $payload['bookingId'] === $booking->id
            && $payload['tripId'] === $this->tripId
            && $payload['pickupEtaAt'] === $live['pickupEtaAt']
            && $payload['stopsBeforeYou'] === $live['stopsBeforeYou'];
    });

    expect($live['position']['heading'])->toBe(88);
});

it('refuses a heading outside a circle', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [[...position(), 'heading' => 400]])->assertStatus(422);
});
