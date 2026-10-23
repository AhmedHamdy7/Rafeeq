<?php

use App\Domains\Booking\Actions\AcceptSeatOfferAction;
use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Outbox\Actions\PublishOutboxAction;
use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Events\OutboxEventPublished;
use App\Domains\Outbox\Models\OutboxEvent;
use App\Domains\Outbox\Support\Outbox;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * The transactional outbox (Feature 03 v5).
 *
 * > *"Publish **idempotent** outbox events for ... seat opened/offer accepted/offer closed ...
 * > cancellation ... Websocket/push consumers must deduplicate event IDs."* And, in the seat
 * allocation section: *"Seat release ... emits `seat_opened(occurrence_id, seat_count, event_id)`
 * **once via outbox**."*
 *
 * 🔴 Why a table rather than a `broadcast()` call inside the Action: broadcasting inside a
 * transaction announces something that may still roll back, and broadcasting after it loses the
 * message if the process dies in between. Both failures are silent, and for a seat race both are the
 * wrong way round — a client told a seat opened for a rolled-back cancellation reaches for a chair
 * that was never empty, and a client never told sits on a stale screen while somebody else takes it.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    // One passenger seated, then the day filled, so everybody after her is waiting rather than
    // pending — which is the state the whole seat race starts from.
    $this->riderToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($this->riderToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])
        ->assertStatus(201)->json('data.id');

    $this->bookingId = approveSeat($this->driverToken, $requestId);

    $this->trip->forceFill(['seats_taken' => 3])->save();
});

/**
 * Somebody waiting for this day.
 *
 * @return array{token: string, id: string, userId: string}
 */
function outboxWaiter(string $phone, string $device, string $commuteId, string $tripId): array
{
    $token = verifiedPassenger($phone, device: $device);

    $id = requestSeat($token, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    return [
        'token' => $token,
        'id' => $id,
        'userId' => (string) SeatRequest::query()->whereKey($id)->sole()->passenger_user_id,
    ];
}

/**
 * Asks the server whether somebody may subscribe to a channel, through the real
 * `/broadcasting/auth` endpoint.
 *
 * 🔴 Why the connection is switched first: the testing environment runs on the `null` broadcaster,
 * whose `auth()` is an empty method — it admits EVERY channel without ever consulting
 * `routes/channels.php`. A subscription test written against it would pass with the callbacks
 * deleted, which is the one outcome that must not be possible here. The pusher driver is the one
 * that actually calls them, and its authorisation is pure HMAC — no broker, no network.
 */
function askToSubscribe(string $token, string $channel)
{
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'key-under-test',
        'broadcasting.connections.pusher.secret' => 'secret-under-test',
        'broadcasting.connections.pusher.app_id' => 'app-under-test',
    ]);

    // Channels register on whichever broadcaster was the default when they were declared — the null
    // one, at boot. They have to be declared again on the driver that is about to be asked.
    require base_path('routes/channels.php');

    return test()->flushHeaders()->withToken($token)->postJson('/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => '1234.5678',
    ]);
}

/**
 * 🔴 v5 names this event and its shape: `seat_opened(occurrence_id, seat_count, event_id)`, once.
 * The `event_id` it means is the fan-out's own release id, which every offer it made also carries —
 * the same id on both is what lets a client tie an offer to the opening it came from instead of
 * guessing.
 */
it('announces a freed seat on the day’s channel, carrying the id its offers carry', function () {
    $her = outboxWaiter('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    app(OfferSeatToWaitlistAction::class)->forTrip($this->trip, 1);

    $event = OutboxEvent::query()->where('type', OutboxEventType::SeatOpened->value)->sole();
    $offer = SeatOffer::query()->where('seat_request_id', $her['id'])->sole();

    expect($event->payload['occurrenceId'])->toBe($this->trip->id)
        ->and($event->payload['seatCount'])->toBe(1)
        ->and($event->payload['releaseEventId'])->toBe($offer->release_event_id)
        ->and($event->channel)->toBe("occurrence.{$this->trip->id}")
        // Recorded, not sent: the row is written with the fact and a separate pass delivers it.
        ->and($event->published_at)->toBeNull();
});

/**
 * Nothing is announced when nobody is waiting — the fan-out stops before it records, because
 * "a seat opened" with no queue to hear it is a message to an empty room.
 */
it('announces nothing when nobody is waiting', function () {
    app(OfferSeatToWaitlistAction::class)->forTrip($this->trip, 1);

    expect(OutboxEvent::query()->count())->toBe(0);
});

/**
 * 🔴 Producing idempotently, which v5 asks for in as many words. The cancelling path is retried — a
 * replayed job, an overlapping sweep — and a second announcement of one release would show two
 * chances at one seat.
 */
it('announces one release once, however many times the path runs', function () {
    outboxWaiter('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    app(OfferSeatToWaitlistAction::class)->forTrip($this->trip, 1);

    $seatOpened = fn () => OutboxEvent::query()->where('type', OutboxEventType::SeatOpened->value);

    $releaseId = $seatOpened()->sole()->payload['releaseEventId'];

    // Re-recording the SAME release is the retry this guards. A fresh call to the Action would mint
    // a new release id, which is a different event and genuinely belongs on the stream.
    foreach (range(1, 3) as $ignored) {
        Outbox::record(
            OutboxEventType::SeatOpened,
            Outbox::occurrenceChannel($this->trip->id),
            ['occurrenceId' => $this->trip->id, 'seatCount' => 1, 'releaseEventId' => $releaseId],
            dedupeKey: "seat.opened:{$releaseId}",
        );
    }

    expect($seatOpened()->count())->toBe(1);
});

/**
 * 🔴 And each loser is told WHICH kind of ending it was: somebody was quicker, not "the seat
 * stopped existing". `SeatOfferStatus` keeps those apart on purpose, and an event that collapsed
 * them would undo that on the surface where she finds out fastest.
 */
it('tells each loser on her own channel that somebody was quicker', function () {
    $first = outboxWaiter('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $second = outboxWaiter('01222220002', 'pax-3', $this->commuteId, $this->trip->id);

    // Through the real cancellation, so the seat is genuinely free when she takes it — accepting
    // onto a full day is refused under the trip's lock, which is what makes the fan-out safe.
    test()->flushHeaders()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $winning = SeatOffer::query()->where('seat_request_id', $first['id'])->sole();

    app(AcceptSeatOfferAction::class)->execute($winning);

    $accepted = OutboxEvent::query()->where('type', OutboxEventType::OfferAccepted->value)->sole();
    $closed = OutboxEvent::query()->where('type', OutboxEventType::OfferClosed->value)->sole();

    expect($accepted->channel)->toBe("occurrence.{$this->trip->id}")
        ->and($accepted->payload['offerId'])->toBe($winning->getKey())
        ->and($accepted->payload['releaseEventId'])->toBe($winning->release_event_id)
        /*
         * 🔒 The loser hears it on HER channel, not the day's. She has no booking on this day and
         * may never have one — "you did not get it" is news about her, and putting it on a channel
         * the whole day can hear would tell everybody who lost.
         */
        ->and($closed->channel)->toBe("user.{$second['userId']}")
        ->and($closed->payload['reason'])->toBe('taken_by_other');
});

/**
 * A cancellation reaches the day's channel: the driver is otherwise planning to stop for somebody
 * who will not be there.
 */
it('announces a cancellation on the day’s channel', function () {
    test()->flushHeaders()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel", ['reason' => 'Plans changed'])
        ->assertOk();

    $event = OutboxEvent::query()->where('type', OutboxEventType::BookingCancelled->value)->sole();

    expect($event->payload['bookingId'])->toBe($this->bookingId)
        ->and($event->payload['status'])->toBe('cancelled_by_passenger')
        ->and($event->payload['seatsReleased'])->toBe(1)
        ->and($event->channel)->toBe("occurrence.{$this->trip->id}");
});

/**
 * 🔒 Ids and counts, never names, phones or plates. A broadcast payload is the easiest thing in a
 * system to end up in a proxy log, and this one travels over a socket to a phone.
 */
it('puts no names or numbers in any payload', function () {
    outboxWaiter('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    test()->flushHeaders()->withToken($this->riderToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    $events = OutboxEvent::query()->get();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $encoded = (string) json_encode($event->payload, JSON_UNESCAPED_UNICODE);

        expect($encoded)->not->toContain('01222220001')
            ->and($encoded)->not->toContain('01112223344')
            ->and($encoded)->not->toContain('سارة');

        foreach (array_keys($event->payload) as $key) {
            expect(mb_strtolower($key))->not->toContain('name')
                ->and(mb_strtolower($key))->not->toContain('phone');
        }
    }
});

/**
 * 🔴 `published_at` is set AFTER the broadcast, never before. A process that dies mid-publish leaves
 * the row to be picked up again — trading a possible duplicate, which v5 tells consumers to
 * deduplicate, for never losing one, which nothing downstream could recover from.
 */
it('marks an event published once it has gone out', function () {
    Event::fake([OutboxEventPublished::class]);

    OutboxEvent::factory()->count(3)->create();

    expect(app(PublishOutboxAction::class)->execute())->toBe(3);

    Event::assertDispatchedTimes(OutboxEventPublished::class, 3);

    expect(OutboxEvent::query()->pending()->count())->toBe(0)
        ->and(OutboxEvent::query()->whereNotNull('published_at')->count())->toBe(3)
        ->and(OutboxEvent::query()->where('attempts', 1)->count())->toBe(3);
});

/**
 * A second pass sends nothing, which is the whole of what makes the publisher safe to run every
 * minute.
 */
it('sends nothing twice', function () {
    Event::fake([OutboxEventPublished::class]);

    OutboxEvent::factory()->count(2)->create();

    app(PublishOutboxAction::class)->execute();

    expect(app(PublishOutboxAction::class)->execute())->toBe(0);

    Event::assertDispatchedTimes(OutboxEventPublished::class, 2);
});

/**
 * The scheduled command is the only thing that runs the pass in production, so it is worth one test
 * that it is wired to the Action rather than to nothing.
 */
it('publishes from the command the scheduler runs', function () {
    Event::fake([OutboxEventPublished::class]);

    OutboxEvent::factory()->create();

    test()->artisan('outbox:publish')->assertExitCode(0);

    expect(OutboxEvent::query()->pending()->count())->toBe(0);
});

/**
 * 🔴 An unpublished row is NEVER pruned. Pruning by age alone would delete the one row that proves
 * the mechanism failed — which is the only row anybody would want to find.
 */
it('ages out delivered events and keeps undelivered ones', function () {
    $tooOld = (int) config('rafeeq.outbox.keep_published_days') + 10;

    $delivered = OutboxEvent::factory()->published()->create([
        'published_at' => now()->subDays($tooOld),
    ]);

    $neverDelivered = OutboxEvent::factory()->create([
        'occurred_at' => now()->subDays($tooOld),
    ]);

    app(OutboxEvent::class)->prunable()->delete();

    expect(OutboxEvent::query()->whereKey($delivered->getKey())->exists())->toBeFalse()
        // 🔴 Still here, and findable.
        ->and(OutboxEvent::query()->whereKey($neverDelivered->getKey())->exists())->toBeTrue();
});

/**
 * 🔒 The channel callback is the only thing between a live feed of who is taking which seat on which
 * morning and anybody who guessed an id. There is no second check after it.
 *
 * A woman waiting for a seat is admitted although she has no booking at all — she is exactly who the
 * fan-out exists for, and refusing her would leave the people it is for as the only ones who cannot
 * hear it.
 */
it('lets a waiting passenger listen to the day she is waiting for', function () {
    $her = outboxWaiter('01222220001', 'pax-2', $this->commuteId, $this->trip->id);

    askToSubscribe($her['token'], "private-occurrence.{$this->trip->id}")->assertOk();
});

it('lets the driver listen to her own day', function () {
    askToSubscribe($this->driverToken, "private-occurrence.{$this->trip->id}")->assertOk();
});

/**
 * 🔒 And refuses somebody with no interest in the day at all.
 */
it('refuses the day’s channel to a stranger', function () {
    $stranger = verifiedPassenger('01299887766', device: 'pax-9');

    askToSubscribe($stranger, "private-occurrence.{$this->trip->id}")->assertForbidden();
});

/**
 * 🔒 And nobody may listen on anybody else's own channel — the simplest rule in `channels.php` and
 * the one with the least room for error, which is precisely why it is tested.
 */
it('refuses one passenger’s private channel to another', function () {
    $her = outboxWaiter('01222220001', 'pax-2', $this->commuteId, $this->trip->id);
    $other = verifiedPassenger('01299887766', device: 'pax-9');

    askToSubscribe($other, "private-user.{$her['userId']}")->assertForbidden();

    // Hers is hers.
    askToSubscribe($her['token'], "private-user.{$her['userId']}")->assertOk();
});
