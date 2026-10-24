<?php

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Notification\Models\Message;
use App\Domains\Notification\Models\Notification;
use App\Domains\Safety\Models\Incident;
use Illuminate\Support\Facades\Storage;

/**
 * Chat on a seat request, before there is any booking (Feature 03 v5).
 *
 * > *"No Messages tab; chat scoped to request / booking / waiting-list."*
 *
 * 🔴 This is the exchange that decides a booking, and it had nowhere to happen. A driver weighing a
 * request could not ask "could you be at the second gate instead?"; a passenger could not ask
 * anything at all about the car she is about to get into. Both had to decide on the strength of a
 * form.
 *
 * ⚠️ And it is **not consent** — v5 §2 in terms. Agreeing a corner in a thread changes nothing: the
 * meeting point moves only through a counteroffer, which is structured and recorded, and that is
 * what a dispute is settled against.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');

    $this->requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        ->json('data.id');

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
});

function sendRequestChat(string $token, string $requestId, string $body = 'ممكن أقابلك عند البوابة ٢؟')
{
    return test()->flushHeaders()->withToken($token)
        ->postJson("/api/v1/seat-requests/{$requestId}/messages", ['body' => $body]);
}

function readRequestChat(string $token, string $requestId)
{
    return test()->flushHeaders()->withToken($token)
        ->getJson("/api/v1/seat-requests/{$requestId}/messages");
}

it('lets the two of them talk while the driver is deciding', function () {
    sendRequestChat($this->paxToken, $this->requestId)
        ->assertStatus(201)
        ->assertJsonPath('data.mine', true)
        ->assertJsonPath('data.containsContactInfo', false);

    readRequestChat($this->driverToken, $this->requestId)
        ->assertOk()
        ->assertJsonPath('data.0.body', 'ممكن أقابلك عند البوابة ٢؟')
        ->assertJsonPath('data.0.mine', false)
        ->assertJsonPath('meta.canSend', true)
        /*
         * The status instead of a window: this thread is open for exactly as long as the request is
         * a live question, so publishing a closing time would be inventing one.
         */
        ->assertJsonPath('meta.requestStatus', 'PENDING');

    // The driver answers, and reading it marks hers read.
    sendRequestChat($this->driverToken, $this->requestId, 'أيوه، البوابة ٢ تمام.')->assertStatus(201);

    readRequestChat($this->paxToken, $this->requestId)
        ->assertOk()
        ->assertJsonPath('data.0.body', 'أيوه، البوابة ٢ تمام.')
        ->assertJsonPath('data.1.readAt', fn ($value) => $value !== null);
});

/**
 * One thread per request, created on the first message rather than when the request is made — the
 * same reason a booking's is: an empty row per request nobody wrote in is a row per request.
 */
it('opens one thread on the first message and no more', function () {
    expect(Conversation::query()->count())->toBe(0);

    sendRequestChat($this->paxToken, $this->requestId)->assertStatus(201);
    sendRequestChat($this->driverToken, $this->requestId, 'تمام.')->assertStatus(201);
    sendRequestChat($this->paxToken, $this->requestId, 'شكرًا!')->assertStatus(201);

    $conversation = Conversation::query()->sole();

    expect($conversation->seat_request_id)->toBe($this->requestId)
        // Not a booking's thread: there is no booking.
        ->and($conversation->booking_id)->toBeNull()
        ->and(Message::query()->count())->toBe(3);
});

/**
 * 🔴 Open while the driver is deciding, while she is waiting on an answer to a counteroffer, and
 * while she is on the waiting list. Each of those is a live question between two people.
 */
it('stays open through a counteroffer', function () {
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$this->requestId}/counteroffer", [
            'pickup' => ['lat' => 30.0629, 'lng' => 31.3509, 'label' => 'Ring Road service lane'],
            'note' => 'المدخل التاني أسهل.',
        ])->assertOk();

    /*
     * 🔴 The worst possible moment to close it: she has a decision to make about a point she did
     * not choose, and the only person who can explain it is the one who proposed it.
     */
    readRequestChat($this->paxToken, $this->requestId)
        ->assertOk()
        ->assertJsonPath('meta.canSend', true)
        ->assertJsonPath('meta.requestStatus', 'COUNTEROFFERED');

    sendRequestChat($this->paxToken, $this->requestId, 'فين المدخل التاني بالظبط؟')->assertStatus(201);
});

it('stays open while she waits on the list', function () {
    ScheduledTrip::query()->whereKey($this->tripId)->update(['seats_taken' => 3]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$this->requestId}/waitlist")
        ->assertOk()
        ->assertJsonPath('data.status', 'WAITLISTED');

    // A waiting list is a relationship, not a queue ticket: the driver already approved her as
    // somebody she would carry.
    readRequestChat($this->paxToken, $this->requestId)
        ->assertOk()
        ->assertJsonPath('meta.canSend', true);

    sendRequestChat($this->paxToken, $this->requestId, 'لسه في فرصة الأسبوع الجاي؟')->assertStatus(201);
});

/**
 * 🔒 A driver who said no has said everything she owes. A channel that outlived her refusal would be
 * a way to keep talking to somebody who declined to carry you.
 */
it('closes the thread once the driver refuses', function () {
    sendRequestChat($this->paxToken, $this->requestId)->assertStatus(201);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$this->requestId}/reject")->assertOk();

    sendRequestChat($this->paxToken, $this->requestId, 'طيب والأسبوع الجاي؟')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHAT_NOT_OPEN')
        ->assertJsonPath('error.fields.status.0', 'rejected');

    // 🔴 But what was said stays readable. It is evidence, and it was hers too.
    readRequestChat($this->paxToken, $this->requestId)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.canSend', false);
});

/**
 * ⚠️ An approved request closes its thread too, and leaves no gap: approval creates a booking, and
 * the booking's own conversation is where they talk about the journey.
 */
it('hands over to the booking’s thread on approval', function () {
    sendRequestChat($this->paxToken, $this->requestId)->assertStatus(201);

    $bookingId = approveSeat($this->driverToken, $this->requestId);

    sendRequestChat($this->paxToken, $this->requestId, 'تمام')
        ->assertStatus(409)
        ->assertJsonPath('error.fields.status.0', 'approved');

    // The journey's own thread, which is a different conversation with its own window.
    runLeavingIn($this->tripId, 30);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$bookingId}/messages", ['body' => 'أنا عند البوابة'])
        ->assertStatus(201);

    expect(Conversation::query()->count())->toBe(2);
});

/**
 * 🔒 The protections are the point of there being one Action with two doors rather than two
 * Actions. A phone number is refused here exactly as it is on a booking: a number shared in the app
 * moves the conversation off it — away from blocking, the safety desk and the record — which is
 * exactly where somebody who later needs protection cannot get it.
 */
it('refuses a phone number on a request thread too', function () {
    sendRequestChat($this->paxToken, $this->requestId, 'كلمني على 01012345678')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CHAT_CONTACT_INFO_NOT_ALLOWED');

    expect(Message::query()->count())->toBe(0);
});

/**
 * 🔒 And a blocked pair gets the SAME refusal a closed thread gets, byte for byte. "You cannot
 * message her because she blocked you" is telling somebody she blocked them.
 */
it('refuses a blocked pair indistinguishably from a closed thread', function () {
    test()->withToken($this->paxToken)
        ->postJson('/api/v1/safety/blocked-users', ['userId' => $this->driver->id])
        ->assertStatus(201);

    $blocked = sendRequestChat($this->driverToken, $this->requestId, 'أهلاً')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHAT_NOT_OPEN')
        ->json('error');

    // The request is still PENDING, so this is what a live thread answers a blocked sender — the
    // same body a refused one would carry.
    expect($blocked['fields']['status'][0])->toBe('pending');

    expect(Message::query()->count())->toBe(0);
});

/**
 * 🔒 Nobody else can read or write it, and the answer is a 404 rather than a 403: a 403 would
 * confirm the id is real, and a seat request names which woman asked which driver for a seat.
 */
it('hides the thread from everybody but the two of them', function () {
    sendRequestChat($this->paxToken, $this->requestId)->assertStatus(201);

    $stranger = verifiedPassenger('01299887766', device: 'pax-9');

    readRequestChat($stranger, $this->requestId)->assertStatus(404);
    sendRequestChat($stranger, $this->requestId, 'أهلاً')->assertStatus(404);

    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223338888', devicePublicId: 'driver-2', seed: 2);

    readRequestChat($otherDriver, $this->requestId)->assertStatus(404);

    expect(Message::query()->count())->toBe(1);
});

/**
 * Who wrote, never what. The body would appear on a lock screen, and "call me on 0101…" on
 * somebody's lock screen is the number shared with whoever is holding the phone.
 */
it('tells the other person without quoting the message', function () {
    sendRequestChat($this->paxToken, $this->requestId, 'ممكن أسألك عن العربية؟')->assertStatus(201);

    $notification = Notification::query()->where('user_id', $this->driver->id)->latest('id')->first();

    expect($notification)->not->toBeNull()
        ->and($notification->body)->not->toContain('العربية')
        // The deep link is the request, so the driver lands on the thread she is being told about.
        ->and($notification->data['seatRequestId'])->toBe($this->requestId);
});

/**
 * 🔒 The report path reaches a request thread, and that is not an afterthought: this is the one
 * place two people talk with no relationship approved between them, so it is where an unwelcome
 * message is most likely and where she has least reason to expect protection. It used to require a
 * booking and would have refused her.
 */
it('lets her report a message sent before any booking exists', function () {
    sendRequestChat($this->driverToken, $this->requestId, 'انتي لوحدك في البيت؟')->assertStatus(201);

    $message = Message::query()->sole();

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/messages/{$message->getKey()}/report", ['reason' => 'مضايقة'])
        ->assertStatus(201);

    $incident = Incident::query()->latest('id')->sole();

    expect($incident->reporter_user_id)->toBe($this->pax->id)
        // No booking to attach it to, and the desk still gets the quoted message.
        ->and($incident->booking_id)->toBeNull()
        ->and($incident->description)->toContain('انتي لوحدك في البيت؟')
        ->and(Message::query()->sole()->flagged_reason)->toBe('مضايقة');
});

/**
 * Reporting your own message is meaningless, and anybody who is not the recipient is not in the
 * conversation.
 */
it('refuses a report from the sender', function () {
    sendRequestChat($this->paxToken, $this->requestId)->assertStatus(201);

    $message = Message::query()->sole();

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/messages/{$message->getKey()}/report", ['reason' => 'test'])
        ->assertStatus(404);
});

/**
 * ⚠️ The thread is not a second way to agree a meeting point. v5 §2: "chat is not consent".
 */
it('changes nothing about the agreed points', function () {
    sendRequestChat($this->paxToken, $this->requestId, 'خلينا نقول البوابة ٣ بدل ٢')->assertStatus(201);
    sendRequestChat($this->driverToken, $this->requestId, 'ماشي، البوابة ٣.')->assertStatus(201);

    $request = SeatRequest::query()->whereKey($this->requestId)->sole();

    // Still exactly what she sent in the request, and no counteroffer anywhere.
    expect($request->requested_pickup_label)->toBe('Ring Road, Rehab exit')
        ->and($request->counteroffered_at)->toBeNull()
        ->and($request->counteroffer_pickup_lat)->toBeNull();
});
