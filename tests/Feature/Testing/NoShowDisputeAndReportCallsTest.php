<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Contracts\PushSender;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Models\Incident;
use App\Domains\Trip\Enums\DisputeResolution;
use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\MemberWarning;
use App\Livewire\Admin\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

function asCaseTester(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function markNoShow(): void
{
    underway(test()->driverToken, test()->tripId);
    arrivedAndWaited(test()->driverToken, test()->tripId, test()->bookingId);

    asCaseTester(test()->driverToken)
        ->postJson('/api/v1/trips/'.test()->tripId.'/no-show', ['bookingId' => test()->bookingId])
        ->assertOk();
}

function disputeNoShow(): void
{
    asCaseTester(test()->paxToken)
        ->postJson('/api/v1/bookings/'.test()->bookingId.'/dispute', ['reason' => 'I was at Gate 2 from 7:00. The car never came.'])
        ->assertCreated();
}

it('shows the no-show record on her own booking and not on the driver\'s view', function () {
    asCaseTester($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->assertJsonPath('data.noShow', null);

    markNoShow();

    $noShow = asCaseTester($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()->json('data.noShow');

    $confirmedAt = Attendance::sole()->confirmed_at;

    expect($noShow['confirmedAt'])->toBe($confirmedAt->toIso8601String())
        ->and(Carbon::parse($noShow['disputableUntil'])->equalTo($confirmedAt->copy()->addHours(24)))->toBeTrue()
        ->and($noShow['dispute'])->toBeNull();

    $row = collect(asCaseTester($this->paxToken)->getJson('/api/v1/my-bookings')->assertOk()->json('data'))
        ->firstWhere('id', $this->bookingId);

    expect($row['noShow']['confirmedAt'])->toBe($confirmedAt->toIso8601String());

    expect(asCaseTester($this->driverToken)->getJson("/api/v1/bookings/{$this->bookingId}")->assertOk()->json('data'))
        ->not->toHaveKey('noShow');
});

it('stops offering the dispute once the window has passed', function () {
    markNoShow();

    Attendance::sole()->forceFill(['confirmed_at' => now()->subHours(25)])->save();

    asCaseTester($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->assertJsonPath('data.noShow.disputableUntil', null);

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'I was at Gate 2 from 7:00.'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATTENDANCE_DISPUTE_WINDOW_CLOSED');
});

it('answers the dispute with the booking, then refuses a second one', function () {
    markNoShow();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'I was at Gate 2 from 7:00. The car never came.'])
        ->assertCreated()
        ->assertJsonPath('data.id', $this->bookingId)
        ->assertJsonPath('data.paymentStatus', 'DISPUTED')
        ->assertJsonPath('data.noShow.dispute.status', 'OPEN')
        ->assertJsonPath('data.noShow.dispute.resolvedAt', null)
        ->assertJsonPath('data.noShow.dispute.note', null);

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'Another reason entirely here.'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_ALREADY_DISPUTED');

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'short'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.reason.0', fn ($value) => $value !== null);
});

it('refuses a dispute on a booking nobody marked', function () {
    asCaseTester($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/dispute", ['reason' => 'I was at Gate 2 from 7:00.'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_NOT_DISPUTABLE');
});

it('keeps the no-show when the dispute is upheld, and tells her', function () {
    markNoShow();
    disputeNoShow();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", [
            'resolution' => 'upheld',
            'note' => 'The driver waited at Gate 2 and the GPS agrees.',
        ])
        ->assertOk()
        ->assertJsonPath('data.paymentStatus', 'NOT_DUE')
        ->assertJsonPath('data.noShow.dispute.status', 'DECIDED')
        ->assertJsonPath('data.noShow.dispute.resolution', 'upheld')
        ->assertJsonPath('data.noShow.dispute.note', 'The driver waited at Gate 2 and the GPS agrees.')
        ->assertJsonPath('data.noShow.dispute.resolvedAt', fn ($value) => $value !== null)
        ->assertJsonPath('data.noShow.disputableUntil', null);

    $passengerId = Attendance::sole()->booking->passenger_user_id;

    $row = Notification::query()
        ->where('user_id', $passengerId)
        ->where('type', 'no_show_dispute_decided')
        ->where('channel', NotificationChannel::InApp->value)
        ->sole();

    expect($row->data)->toEqualCanonicalizing([
        'bookingId' => $this->bookingId,
        'tripId' => $this->tripId,
        'resolution' => 'upheld',
    ]);

    expect(MemberWarning::query()->where('booking_id', $this->bookingId)->sole()->dispute_resolution)->toBeNull()
        ->and(ReliabilityEvent::query()->where('booking_id', $this->bookingId)->sole()->reversed_at)->toBeNull();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'overturned'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_DISPUTE_NOT_OPEN');
});

it('removes the no-show, its fee and its reliability event when overturned', function () {
    asCaseTester($this->paxToken)->postJson('/api/v1/testing/attendance', ['kind' => 'no_show', 'count' => 2])->assertOk();

    markNoShow();

    $warning = MemberWarning::query()->where('booking_id', $this->bookingId)->where('kind', WarningKind::NoShow->value)->sole();

    expect($warning->fee_status)->toBe(WarningFeeStatus::Charged)
        ->and($warning->fee_piastres)->toBeGreaterThan(0);

    disputeNoShow();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'overturned'])
        ->assertOk()
        ->assertJsonPath('data.noShow.dispute.resolution', 'overturned')
        ->assertJsonPath('data.noShow.dispute.note', null);

    $warning->refresh();

    expect($warning->dispute_resolution)->toBe(DisputeResolution::Overturned)
        ->and($warning->fee_status)->toBe(WarningFeeStatus::Reversed)
        ->and(DebtEntry::query()->where('idempotency_key', "warning_fee_reversal:{$warning->id}")->exists())->toBeTrue()
        ->and(ReliabilityEvent::query()
            ->where('booking_id', $this->bookingId)
            ->where('kind', ReliabilityEventKind::PassengerNoShow->value)
            ->sole()->reversed_at)->not->toBeNull();
});

it('marks the seat refunded when the decision is refunded', function () {
    markNoShow();
    disputeNoShow();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'refunded'])
        ->assertOk()
        ->assertJsonPath('data.paymentStatus', 'REFUNDED')
        ->assertJsonPath('data.noShow.dispute.resolution', 'refunded');
});

it('answers 404 for somebody else\'s booking and 409 without an open dispute', function () {
    markNoShow();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'upheld'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ATTENDANCE_DISPUTE_NOT_OPEN');

    disputeNoShow();

    asCaseTester($this->driverToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'upheld'])
        ->assertNotFound();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'maybe'])
        ->assertStatus(422);
});

it('gives every report a short case number', function () {
    $created = asCaseTester($this->paxToken)->postJson('/api/v1/incidents', ['category' => 'other', 'description' => 'Something happened at the gate.'])
        ->assertCreated()->json('data');

    expect($created['caseNumber'])->toMatch('/^RP-\d{6}$/');

    asCaseTester($this->paxToken)->getJson("/api/v1/incidents/{$created['id']}")
        ->assertOk()->assertJsonPath('data.caseNumber', $created['caseNumber']);

    expect(collect(asCaseTester($this->paxToken)->getJson('/api/v1/incidents')->assertOk()->json('data'))->firstWhere('id', $created['id'])['caseNumber'])
        ->toBe($created['caseNumber']);
});

it('plays a report through review, decision, appeal and a closed window', function () {
    $id = asCaseTester($this->paxToken)->postJson('/api/v1/incidents', ['category' => 'other', 'description' => 'Something happened at the gate.'])
        ->assertCreated()->json('data.id');

    asCaseTester($this->paxToken)->postJson("/api/v1/testing/incidents/{$id}/decide", ['status' => 'UNDER_REVIEW'])
        ->assertOk()
        ->assertJsonPath('data.status', 'UNDER_REVIEW')
        ->assertJsonPath('data.appeal.appealableUntil', null);

    asCaseTester($this->paxToken)->postJson("/api/v1/testing/incidents/{$id}/decide", [
        'status' => 'RESOLVED',
        'resolution' => 'We spoke to the driver and warned him.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'RESOLVED')
        ->assertJsonPath('data.resolution', 'We spoke to the driver and warned him.')
        ->assertJsonPath('data.resolvedAt', fn ($value) => $value !== null)
        ->assertJsonPath('data.appeal.appealableUntil', fn ($value) => $value !== null);

    expect(Notification::query()->where('type', 'report_resolved')->where('channel', NotificationChannel::InApp->value)->sole()->data)
        ->toBe(['incidentId' => $id]);

    asCaseTester($this->paxToken)->postJson("/api/v1/incidents/{$id}/appeal", ['reason' => 'He did it again the next morning.'])
        ->assertSuccessful();

    asCaseTester($this->paxToken)->postJson("/api/v1/testing/incidents/{$id}/decide", ['status' => 'CLOSED'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CLOSED')
        ->assertJsonPath('data.appeal.count', 1)
        ->assertJsonPath('data.appeal.needsNewEvidence', true);

    asCaseTester($this->paxToken)->postJson("/api/v1/testing/incidents/{$id}/age", ['hours' => 49])
        ->assertOk()
        ->assertJsonPath('data.appeal.appealableUntil', null);

    asCaseTester($this->paxToken)->postJson("/api/v1/testing/incidents/{$id}/decide", ['status' => 'RESOLVED'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INCIDENT_CLOSED');
});

it('keeps the report calls to the person who filed it', function () {
    $id = asCaseTester($this->paxToken)->postJson('/api/v1/incidents', ['category' => 'other'])->assertCreated()->json('data.id');

    asCaseTester($this->driverToken)->postJson("/api/v1/testing/incidents/{$id}/decide", ['status' => 'RESOLVED'])->assertNotFound();
    asCaseTester($this->driverToken)->postJson("/api/v1/testing/incidents/{$id}/age", ['hours' => 10])->assertNotFound();
    asCaseTester($this->paxToken)->postJson("/api/v1/testing/incidents/{$id}/age", ['hours' => 201])->assertStatus(422);

    expect(Incident::query()->findOrFail($id)->resolved_at)->toBeNull();
});

it('answers 404 for the new calls while testing is off', function () {
    config(['rafeeq.testing.api' => false]);

    asCaseTester($this->paxToken)->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'upheld'])->assertNotFound();
    asCaseTester($this->paxToken)->postJson('/api/v1/testing/incidents/x/decide', ['status' => 'RESOLVED'])->assertNotFound();
    asCaseTester($this->paxToken)->postJson('/api/v1/testing/incidents/x/age', ['hours' => 5])->assertNotFound();
});

it('sends the inbox row\'s id, type and data with the push', function () {
    $passenger = User::query()->findOrFail(Booking::query()->findOrFail($this->bookingId)->passenger_user_id);

    Device::query()->where('user_id', $passenger->id)->update(['push_token' => encrypt('fcm-token-'.$passenger->id, false)]);

    $fake = new class implements PushSender
    {
        public array $sent = [];

        public function send(string $pushToken, string $title, string $body, array $data): void
        {
            $this->sent[] = $data;
        }

        public function delivers(): bool
        {
            return true;
        }
    };

    app()->instance(PushSender::class, $fake);

    markNoShow();
    disputeNoShow();

    asCaseTester($this->paxToken)
        ->postJson("/api/v1/testing/bookings/{$this->bookingId}/dispute/decide", ['resolution' => 'overturned'])
        ->assertOk();

    $inbox = Notification::query()
        ->where('user_id', $passenger->id)
        ->where('type', 'no_show_dispute_decided')
        ->where('channel', NotificationChannel::InApp->value)
        ->sole();

    $push = collect($fake->sent)->firstWhere('type', 'no_show_dispute_decided');

    expect($push)->toEqualCanonicalizing([
        'bookingId' => $this->bookingId,
        'tripId' => $this->tripId,
        'resolution' => 'overturned',
        'type' => 'no_show_dispute_decided',
        'category' => 'trip',
        'notificationId' => $inbox->id,
    ]);

    foreach ($fake->sent as $data) {
        expect($data)->toHaveKey('notificationId');
    }
});

it('lets finance decide a no-show dispute on the dashboard', function () {
    markNoShow();
    disputeNoShow();

    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Payments::class)
        ->assertSee('I was at Gate 2 from 7:00. The car never came.')
        ->call('openAppeal', $this->bookingId)
        ->call('decideDispute', $this->bookingId, 'overturned')
        ->assertHasErrors('appealNote')
        ->set('appealNote', 'GPS shows the car never reached Gate 2.')
        ->set('memberNote', 'Sorry about this. The no-show is removed.')
        ->call('decideDispute', $this->bookingId, 'overturned')
        ->assertHasNoErrors()
        ->assertDontSee('I was at Gate 2 from 7:00. The car never came.');

    $attendance = Attendance::sole();

    expect($attendance->dispute_resolution)->toBe(DisputeResolution::Overturned)
        ->and($attendance->dispute_note)->toBe('Sorry about this. The no-show is removed.')
        ->and(AdminAction::query()->where('action', 'attendance.dispute_decide')->sole()->reason)->toBe('GPS shows the car never reached Gate 2.');
});
