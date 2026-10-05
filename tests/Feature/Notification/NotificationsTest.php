<?php

use App\Domains\Admin\Actions\HandleIncidentAction;
use App\Domains\Admin\Actions\RespondToSosAction;
use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Contracts\PushSender;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Models\NotificationPreference;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SosEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 12 — the platform telling a member something on its own initiative (Chapter 11).
 *
 * 🔴 Until this existed every message was pull-only: a driver learned of a seat request by
 * opening the inbox screen, and a member who raised an SOS learned an operator had picked it up
 * only by trying to cancel it and being refused.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $this->pax = User::query()->where('phone_e164', '+201223339999')->sole();
    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
});

/**
 * @return Collection<int, Notification>
 */
function inboxOf(User $user)
{
    return Notification::query()->where('user_id', $user->id)->where('channel', NotificationChannel::InApp->value)->get();
}

function pushesTo(User $user): int
{
    return Notification::query()->where('user_id', $user->id)->where('channel', NotificationChannel::Push->value)->count();
}

function withPushToken(User $user): void
{
    Device::query()->where('user_id', $user->id)->update(['push_token' => encrypt('fcm-token-'.$user->id, false)]);
}

/*
|--------------------------------------------------------------------------
| The messages
|--------------------------------------------------------------------------
*/

it('tells the driver about a new seat request, by the passenger first name only', function () {
    $this->pax->forceFill(['full_name' => 'Mariam Hassan Abdelaziz', 'public_first_name' => 'Mariam'])->save();

    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');

    $note = inboxOf($this->driver)->sole();

    expect($note->type)->toBe('seat_requested')
        ->and($note->body)->toContain('Mariam')
        // 🔒 A push body is shown on a lock screen.
        ->and($note->body)->not->toContain('Hassan')
        ->and($note->data)->toBe(['seatRequestId' => $requestId]);
});

it('tells the passenger when the driver says yes', function () {
    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    expect(inboxOf($this->pax)->pluck('type')->all())->toBe(['seat_approved']);
});

it('tells the passenger when the driver says no, without repeating the driver note', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/reject", ['note' => 'My sister is joining that week'])
        ->assertOk();

    $note = inboxOf($this->pax)->sole();

    expect($note->type)->toBe('seat_declined')
        ->and($note->body)->not->toContain('sister');
});

it('tells every passenger when the run starts', function () {
    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    underway($this->driverToken, $this->tripId);

    expect(inboxOf($this->pax)->pluck('type')->all())->toContain('trip_started')
        ->and(inboxOf($this->pax)->firstWhere('type', 'trip_started')->data)->toBe(['tripId' => $this->tripId]);
});

/**
 * 🔴 Written in the caller's transaction: a refused approval must not tell anybody they have a
 * seat.
 */
it('says nothing about an approval that was rolled back', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id');

    ScheduledTrip::query()->whereKey($this->tripId)->update(['seats_taken' => ScheduledTrip::find($this->tripId)->seats_total]);

    test()->withToken($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(409);

    expect(inboxOf($this->pax))->toBeEmpty();
});

it('writes the message in the recipient language, not the sender', function () {
    $this->pax->forceFill(['preferred_language' => 'ar'])->save();

    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    expect(inboxOf($this->pax)->sole()->title)->toBe(__('notifications.seat_approved.title', locale: 'ar'));
});

/*
|--------------------------------------------------------------------------
| Safety messages
|--------------------------------------------------------------------------
*/

it('tells somebody who raised an SOS that a human has picked it up, on the phone too', function () {
    withPushToken($this->pax);

    $sos = SosEvent::query()->whereKey(test()->withToken($this->paxToken)->postJson('/api/v1/sos')->json('data.id'))->sole();

    app(RespondToSosAction::class)->acknowledge($sos, adminWithRole(AdminRole::SafetyLead));

    expect(inboxOf($this->pax)->sole()->type)->toBe('sos_picked_up')
        ->and(pushesTo($this->pax))->toBe(1);
});

/**
 * 🔴 The silent alert's whole promise: nothing on the phone makes a sound.
 */
it('never pushes to the phone about a silent alert, only to the inbox', function () {
    withPushToken($this->pax);

    $sos = SosEvent::query()->whereKey(test()->withToken($this->paxToken)->postJson('/api/v1/sos', ['isDiscreet' => true])->json('data.id'))->sole();

    app(RespondToSosAction::class)->acknowledge($sos, adminWithRole(AdminRole::SafetyLead));

    expect(inboxOf($this->pax)->sole()->type)->toBe('sos_picked_up')
        ->and(pushesTo($this->pax))->toBe(0);
});

it('tells the reporter when a report is answered, and not when it is escalated', function () {
    $incident = Incident::query()->whereKey(
        test()->withToken($this->paxToken)->postJson('/api/v1/incidents', ['category' => 'other'])->json('data.id')
    )->sole();
    $lead = adminWithRole(AdminRole::SafetyLead);

    app(HandleIncidentAction::class)->escalate($incident, $lead, 'Handing this one to the lead, internal note.');

    expect(inboxOf($this->pax))->toBeEmpty();

    app(HandleIncidentAction::class)->resolve($incident, $lead, 'We spoke to the driver about it.');

    expect(inboxOf($this->pax)->sole()->type)->toBe('report_resolved');
});

it('tells a member their account is on hold with the case reference, and they can read it while on hold', function () {
    $hold = app(SuspendMemberAction::class)->suspend($this->pax, adminWithRole(AdminRole::Operations), SuspensionReason::SafetyReport, 'Two reports this week, holding for review.');

    expect(inboxOf($this->pax)->sole()->body)->toContain($hold->case_number);

    test()->withToken($this->paxToken)->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'account_on_hold');
});

/*
|--------------------------------------------------------------------------
| Preferences
|--------------------------------------------------------------------------
*/

it('starts with everything on except marketing', function () {
    $switches = collect(test()->withToken($this->paxToken)->getJson('/api/v1/notifications/preferences')->assertOk()->json('data'));

    expect($switches->firstWhere(fn ($s) => $s['category'] === 'booking' && $s['channel'] === 'push')['enabled'])->toBeTrue()
        ->and($switches->firstWhere(fn ($s) => $s['category'] === 'marketing' && $s['channel'] === 'push')['enabled'])->toBeFalse()
        ->and($switches->firstWhere(fn ($s) => $s['category'] === 'safety' && $s['channel'] === 'push')['canDisable'])->toBeFalse();
});

it('stops the phone buzzing for a category, and keeps the inbox', function () {
    withPushToken($this->pax);

    test()->withToken($this->paxToken)->patchJson('/api/v1/notifications/preferences', ['preferences' => [
        ['category' => 'booking', 'channel' => 'push', 'enabled' => false],
    ]])->assertOk();

    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    expect(inboxOf($this->pax))->toHaveCount(1)
        ->and(pushesTo($this->pax))->toBe(0);
});

it('refuses to switch safety notices off', function () {
    test()->withToken($this->paxToken)->patchJson('/api/v1/notifications/preferences', ['preferences' => [
        ['category' => 'booking', 'channel' => 'push', 'enabled' => false],
        ['category' => 'safety', 'channel' => 'push', 'enabled' => false],
    ]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NOTIFICATION_CATEGORY_LOCKED');

    // All or nothing: the booking switch in the same request did not change either.
    expect(NotificationPreference::count())->toBe(0);
});

/**
 * A stored "off" for safety — written any other way — still cannot silence it.
 */
it('delivers a safety notice even if a row somehow says off', function () {
    withPushToken($this->pax);

    NotificationPreference::query()->create(['user_id' => $this->pax->id, 'category' => 'safety', 'channel' => 'push', 'enabled' => false]);

    $sos = SosEvent::query()->whereKey(test()->withToken($this->paxToken)->postJson('/api/v1/sos')->json('data.id'))->sole();
    app(RespondToSosAction::class)->acknowledge($sos, adminWithRole(AdminRole::SafetyLead));

    expect(pushesTo($this->pax))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The inbox
|--------------------------------------------------------------------------
*/

it('lists only the caller own inbox, with the unread count', function () {
    withPushToken($this->pax);

    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    test()->withToken($this->paxToken)->getJson('/api/v1/notifications')
        ->assertOk()
        // One item, though a push row also exists: push rows are the delivery log.
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'seat_approved')
        ->assertJsonPath('data.0.isRead', false)
        ->assertJsonPath('meta.unreadCount', 1);

    // The driver's inbox holds the seat request, and nothing of the passenger's.
    test()->withToken($this->driverToken)->getJson('/api/v1/notifications')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'seat_requested');
});

it('marks read by id, ignoring ids that are not the caller own', function () {
    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    $mine = inboxOf($this->pax)->sole();
    $theDrivers = inboxOf($this->driver)->sole();

    test()->withToken($this->paxToken)
        ->patchJson('/api/v1/notifications/read', ['ids' => [$mine->id, $theDrivers->id]])
        ->assertOk()
        ->assertJsonPath('data.unreadCount', 0);

    expect($mine->refresh()->read_at)->not->toBeNull()
        ->and($theDrivers->refresh()->read_at)->toBeNull();
});

it('marks everything read', function () {
    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->paxToken)->patchJson('/api/v1/notifications/read', ['all' => true])
        ->assertOk()
        ->assertJsonPath('data.unreadCount', 0);
});

/*
|--------------------------------------------------------------------------
| Push delivery
|--------------------------------------------------------------------------
*/

it('records that no push provider is configured instead of claiming the push was sent', function () {
    withPushToken($this->pax);

    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    $push = Notification::query()->where('user_id', $this->pax->id)->where('channel', 'push')->sole();

    expect($push->sent_at)->toBeNull()
        ->and($push->failed_reason)->toBe('no_push_provider');
});

it('marks a push sent when a real provider accepts it, and keeps the token out of the failure reason', function () {
    withPushToken($this->pax);

    $fake = new class implements PushSender
    {
        public array $sent = [];

        public bool $fail = false;

        public function send(string $pushToken, string $title, string $body, array $data): void
        {
            if ($this->fail) {
                throw new RuntimeException("rejected token {$pushToken}", 400);
            }

            $this->sent[] = compact('pushToken', 'title', 'data');
        }

        public function delivers(): bool
        {
            return true;
        }
    };

    app()->instance(PushSender::class, $fake);

    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->json('data.id'));

    expect($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['pushToken'])->toBe('fcm-token-'.$this->pax->id)
        ->and(Notification::query()->where('user_id', $this->pax->id)->where('channel', 'push')->sole()->sent_at)->not->toBeNull();

    $fake->fail = true;

    try {
        underway($this->driverToken, $this->tripId);
    } catch (RuntimeException) {
        // The sync queue rethrows the job's failure; on a real queue it would retry.
    }

    $failed = Notification::query()->where('user_id', $this->pax->id)->where('channel', 'push')->where('type', 'trip_started')->sole();

    expect($failed->failed_reason)->toBe('RuntimeException (400)')
        ->and($failed->failed_reason)->not->toContain('fcm-token');
});
