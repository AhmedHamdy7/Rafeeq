<?php

use App\Domains\Admin\Actions\ManageDebtAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtNotices;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->driver = User::query()->where('phone_e164', '+201012345678')->firstOrFail();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $this->paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $this->pax = User::query()->where('phone_e164', '+201223339999')->firstOrFail();
});

function items59As(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function pauseCommuteForTest(string $commuteId, CommutePausedReason $reason = CommutePausedReason::ByDriver): void
{
    CommuteOffer::query()->whereKey($commuteId)->update([
        'status' => CommuteOfferStatus::Paused->value,
        'paused_reason' => $reason->value,
        'paused_at' => now(),
    ]);
}

function recurringMember(string $driverToken, string $paxToken, string $commuteId): string
{
    $requestId = requestSeat($paxToken, $commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    return approveSeat($driverToken, $requestId);
}

it('44: refuses approve and waitlist on a paused commute with COMMUTE_PAUSED', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id');

    pauseCommuteForTest($this->commuteId);

    items59As($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_PAUSED');

    items59As($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_PAUSED');
});

it('44: puts the commute status on every driver request row and on the single request', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id');

    items59As($this->driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertOk()
        ->assertJsonPath('data.0.commuteStatus', 'active')
        ->assertJsonPath('data.0.commutePausedReason', null);

    pauseCommuteForTest($this->commuteId);

    items59As($this->driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertJsonPath('data.0.commuteStatus', 'paused')
        ->assertJsonPath('data.0.commutePausedReason', 'by_driver');

    items59As($this->driverToken)->getJson("/api/v1/driver/seat-requests/{$requestId}")
        ->assertOk()
        ->assertJsonPath('data.id', $requestId)
        ->assertJsonPath('data.commuteStatus', 'paused')
        ->assertJsonPath('data.commutePausedReason', 'by_driver');

    items59As($this->paxToken)->getJson("/api/v1/driver/seat-requests/{$requestId}")->assertNotFound();
});

it('45: puts the commute status on the driver waiting list rows', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id');
    items59As($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    pauseCommuteForTest($this->commuteId);

    items59As($this->driverToken)->getJson('/api/v1/driver/waitlist')
        ->assertOk()
        ->assertJsonPath('data.0.commuteId', $this->commuteId)
        ->assertJsonPath('data.0.commuteStatus', 'paused')
        ->assertJsonPath('data.0.commutePausedReason', 'by_driver');
});

it('48: deletes notifications by id or all, and marks them unread again', function () {
    $one = Notifier::send($this->pax, NotificationType::BookingCancelled, ['name' => 'Omar', 'date' => '1/12']);
    $two = Notifier::send($this->pax, NotificationType::BookingCancelled, ['name' => 'Omar', 'date' => '2/12']);
    $before = items59As($this->paxToken)->getJson('/api/v1/notifications')->json('meta.unreadCount');

    items59As($this->paxToken)->deleteJson('/api/v1/notifications', ['ids' => [$one->id]])
        ->assertOk()
        ->assertJsonPath('data.unreadCount', $before - 1);

    $ids = collect(items59As($this->paxToken)->getJson('/api/v1/notifications')->json('data'))->pluck('id');
    expect($ids)->not->toContain($one->id)->and($ids)->toContain($two->id);

    items59As($this->paxToken)->patchJson('/api/v1/notifications/read', ['ids' => [$two->id]])->assertOk();
    items59As($this->paxToken)->patchJson('/api/v1/notifications/read', ['ids' => [$two->id], 'read' => false])
        ->assertOk()
        ->assertJsonPath('data.unreadCount', $before - 1);

    expect(Notification::query()->findOrFail($two->id)->read_at)->toBeNull();

    items59As($this->paxToken)->deleteJson('/api/v1/notifications', ['all' => true])
        ->assertOk()
        ->assertJsonPath('data.unreadCount', 0);

    expect(items59As($this->paxToken)->getJson('/api/v1/notifications')->json('data'))->toBe([])
        ->and(Notification::query()->whereKey($one->id)->exists())->toBeTrue();

    items59As($this->driverToken)->deleteJson('/api/v1/notifications', ['ids' => [$two->id]])->assertOk();
});

it('48: says the side on every debt notice', function () {
    DebtNotices::send($this->driver, NotificationType::DebtOverdue, ['amount' => 1_800], DebtSide::Driver);
    DebtNotices::send($this->pax, NotificationType::RidingSuspendedForDebt, ['amount' => 1_800], DebtSide::Passenger);

    expect(Notification::query()->where('user_id', $this->driver->id)->where('type', 'debt_overdue')->where('channel', 'in_app')->sole()->data['side'])->toBe('driver')
        ->and(Notification::query()->where('user_id', $this->pax->id)->where('type', 'riding_suspended_for_debt')->where('channel', 'in_app')->sole()->data['side'])->toBe('passenger');
});

it('42: sends the commutes paused for unpaid commission with the payment confirmation', function () {
    pauseCommuteForTest($this->commuteId, CommutePausedReason::CommissionUnpaid);

    $payment = DebtPayment::factory()->create(['user_id' => $this->driver->id, 'side' => DebtSide::Driver, 'amount_piastres' => 1_800]);
    app(ManageDebtAction::class)->confirmPayment($payment, AdminUser::factory()->create());

    $data = Notification::query()->where('user_id', $this->driver->id)->where('type', 'debt_payment_confirmed')->where('channel', 'in_app')->sole()->data;

    expect($data['side'])->toBe('driver')
        ->and($data['commuteIds'])->toBe($this->commuteId);
});

it('56: previews and removes her from every upcoming day, ends her seat and tells her once', function () {
    $bookingId = recurringMember($this->driverToken, $this->paxToken, $this->commuteId);
    $upcoming = Booking::query()->where('passenger_user_id', $this->pax->id)->where('status', BookingStatus::Confirmed->value)->count();

    expect($upcoming)->toBeGreaterThan(1);

    items59As($this->driverToken)->getJson("/api/v1/driver/bookings/{$bookingId}/cancel-preview")
        ->assertOk()
        ->assertJsonPath('data.scope', 'day')
        ->assertJsonPath('data.daysCount', 1);

    $preview = items59As($this->driverToken)->getJson("/api/v1/driver/bookings/{$bookingId}/cancel-preview?scope=all")
        ->assertOk()
        ->assertJsonPath('data.scope', 'all')
        ->assertJsonPath('data.daysCount', $upcoming)
        ->json('data');

    expect($preview['feePiastres'])->toBe(array_sum(array_column($preview['perTrip'], 'feePiastres')));

    Notification::query()->where('user_id', $this->pax->id)->delete();

    items59As($this->driverToken)->postJson("/api/v1/driver/bookings/{$bookingId}/cancel", ['reason' => 'moving house', 'scope' => 'all'])
        ->assertOk()
        ->assertJsonPath('meta.scope', 'all')
        ->assertJsonPath('meta.daysCount', $upcoming);

    expect(Booking::query()->where('passenger_user_id', $this->pax->id)->where('status', BookingStatus::Confirmed->value)->count())->toBe(0)
        ->and(SeatRequest::query()->where('passenger_user_id', $this->pax->id)->sole()->status)->toBe(SeatRequestStatus::Ended)
        ->and(GroupMember::query()->where('user_id', $this->pax->id)->sole()->status)->toBe(GroupMemberStatus::Removed);

    $notes = Notification::query()->where('user_id', $this->pax->id)->where('channel', 'in_app')->get();

    expect($notes)->toHaveCount(1)
        ->and($notes->first()->type)->toBe('removed_from_commute')
        ->and($notes->first()->data['scope'])->toBe('all')
        ->and($notes->first()->data['commuteId'])->toBe($this->commuteId);
});

it('56: removes her from one day only with scope day', function () {
    $bookingId = recurringMember($this->driverToken, $this->paxToken, $this->commuteId);
    $upcoming = Booking::query()->where('passenger_user_id', $this->pax->id)->where('status', BookingStatus::Confirmed->value)->count();

    Notification::query()->where('user_id', $this->pax->id)->delete();

    items59As($this->driverToken)->postJson("/api/v1/driver/bookings/{$bookingId}/cancel", ['reason' => 'not this day'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED_BY_DRIVER')
        ->assertJsonPath('meta.scope', 'day');

    expect(Booking::query()->where('passenger_user_id', $this->pax->id)->where('status', BookingStatus::Confirmed->value)->count())->toBe($upcoming - 1)
        ->and(SeatRequest::query()->where('passenger_user_id', $this->pax->id)->sole()->status)->toBe(SeatRequestStatus::Approved);

    $note = Notification::query()->where('user_id', $this->pax->id)->where('channel', 'in_app')->sole();

    expect($note->type)->toBe('booking_cancelled')
        ->and($note->data['scope'])->toBe('day')
        ->and($note->body)->toContain('removed you from');
});

it('47: gives the added distance with her on board in the route preview', function () {
    $requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id');

    $impact = items59As($this->driverToken)->getJson("/api/v1/driver/seat-requests/{$requestId}/route-preview")
        ->assertOk()
        ->json('data.impact');

    expect($impact)->toHaveKeys(['addedMinutes', 'addedMetres', 'promisedArrivalLocal', 'projectedArrivalLocal'])
        ->and($impact['addedMetres'])->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('54: puts her commitment, days and drop-off time on the driver\'s bookings', function () {
    recurringMember($this->driverToken, $this->paxToken, $this->commuteId);

    $row = items59As($this->driverToken)->getJson("/api/v1/driver/bookings?tripId={$this->trip->id}")
        ->assertOk()
        ->json('data.0');

    expect($row['commitment'])->toBe('recurring')
        ->and($row['requestedDaysMask'])->toBe(DaysMask::weekdaysSunToThu()->value)
        ->and($row['commuteGroupId'])->not->toBeNull()
        ->and($row['tripDate'])->toBe($this->trip->trip_date->toDateString())
        ->and($row)->toHaveKeys(['dropoffEtaLocal', 'dropoffEtaAt', 'pickupEtaLocal']);
});

it('59: fills the promised arrival on every upcoming day of the public commute, booked or not', function () {
    $days = items59As($this->paxToken)->getJson("/api/v1/commutes/{$this->commuteId}/public")
        ->assertOk()
        ->json('data.upcomingTrips');

    expect($days)->not->toBeEmpty();

    foreach ($days as $day) {
        expect($day['promisedArrivalLocal'])->not->toBeNull()
            ->and($day['promisedArrivalAt'])->not->toBeNull();
    }
});

it('48: puts the side and the debt line on a driver fee notice', function () {
    $bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id'));
    runLeavingIn($this->trip->id, 120);

    items59As($this->driverToken)->postJson("/api/v1/driver/bookings/{$bookingId}/cancel", ['reason' => 'car trouble'])->assertOk();

    $note = Notification::query()->where('user_id', $this->driver->id)->where('type', 'driver_cancel_fee')->where('channel', 'in_app')->sole();

    expect($note->data['side'])->toBe('driver')
        ->and($note->data['debtEntryId'])->toBe((string) DebtEntry::query()->where('user_id', $this->driver->id)->sole()->id);
});
