<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Reliability\Models\ReliabilityEventAppeal;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();
});

it('seeds every kind of payment line on both sides, and takes a credit with a written reason', function () {
    $passenger = verifiedPassenger('01020001001');

    foreach (['fee', 'late_fee', 'no_show_fee'] as $kind) {
        test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
            'side' => 'passenger', 'kind' => $kind, 'amountPiastres' => 1000,
        ])->assertStatus(201);
    }

    foreach (['coupon', 'credit', 'payment'] as $kind) {
        test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
            'side' => 'passenger', 'kind' => $kind, 'amountPiastres' => 500,
        ])->assertStatus(201);
    }

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'passenger', 'kind' => 'adjustment', 'amountPiastres' => 300, 'direction' => 'decrease',
    ])->assertStatus(201);

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'passenger', 'kind' => 'adjustment', 'amountPiastres' => 200,
    ])->assertStatus(201);

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'passenger', 'kind' => 'write_off',
    ])->assertStatus(201)->assertJsonPath('data.owedPiastres', 0)->assertJsonCount(1, 'data.lineIds');

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'passenger', 'kind' => 'credit', 'amountPiastres' => 500, 'disputed' => true,
    ])->assertStatus(422);

    expect(DebtEntry::query()->get()->map(fn (DebtEntry $line): string => $line->type->value)->unique()->sort()->values()->all())
        ->toBe(['adjustment', 'coupon', 'credit', 'fee', 'payment', 'write_off'])
        ->and(DebtEntry::query()->where('type', 'write_off')->value('amount_piastres'))->toBeLessThan(0);

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/credits', ['amountPiastres' => 2500, 'reason' => 'Goodwill credit for a late driver'])
        ->assertStatus(201)
        ->assertJsonPath('data.reasonCode', 'staff_credit')
        ->assertJsonPath('data.creditPiastres', 2500);

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/credits', ['amountPiastres' => 50, 'reason' => 'driver_cancelled'])
        ->assertStatus(201)->assertJsonPath('data.reasonCode', 'driver_cancelled');

    $driver = approvedDriver('01020001009', 'driver');

    foreach (['fee', 'late_fee', 'commission', 'coupon', 'credit', 'payment', 'adjustment', 'write_off'] as $kind) {
        test()->flushHeaders()->withToken($driver)->postJson('/api/v1/testing/debt/seed', [
            'side' => 'driver', 'kind' => $kind, 'amountPiastres' => 1500,
        ])->assertStatus(201);
    }
});

it('sets a lower-case payment status and a fee line on a past booking', function () {
    $passenger = verifiedPassenger('01020001001');
    [$completed] = test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/seed/past-bookings', ['count' => 1, 'ending' => 'COMPLETED'])->json('data.bookingIds');

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$completed}/fields", ['paymentStatus' => 'waived'])
        ->assertOk()->assertJsonPath('data.paymentStatus', 'WAIVED');

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$completed}/fields", ['feeLinePiastres' => -1500])
        ->assertOk()->assertJsonPath('data.feeLinePiastres', -1500)->assertJsonPath('data.feeLineReason', 'credit');

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$completed}/fields", ['paymentStatus' => 'gone'])
        ->assertStatus(422);
});

it('seeds the endings the real flows leave: driver cancelled, exited for a late driver, never started', function () {
    $passenger = verifiedPassenger('01020001001');

    $seed = fn (string $ending): string => test()->flushHeaders()->withToken($passenger)
        ->postJson('/api/v1/testing/seed/past-bookings', ['count' => 1, 'ending' => $ending])
        ->assertStatus(201)->json('data.bookingIds.0');

    $read = fn (string $id) => test()->flushHeaders()->withToken($passenger)->getJson("/api/v1/bookings/{$id}")->assertOk();

    $removed = $seed('canceled by the driver');
    $read($removed)->assertJsonPath('data.status', 'CANCELLED_BY_DRIVER')->assertJsonPath('data.cancelledReason', 'removed_by_driver');

    $day = $seed('DRIVER_CANCELLED_DAY');
    $read($day)->assertJsonPath('data.status', 'CANCELLED_BY_DRIVER')->assertJsonPath('data.cancelledReason', 'day_cancelled');
    expect(ScheduledTrip::query()->whereKey(Booking::query()->whereKey($day)->value('scheduled_trip_id'))->value('status'))->toBe(ScheduledTripStatus::Cancelled);

    $exited = $seed('exited (driver late)');
    $read($exited)->assertJsonPath('data.status', 'EXITED_DUE_TO_DRIVER_DELAY')
        ->assertJsonPath('data.cancelledReason', 'not_started')
        ->assertJsonPath('data.paymentStatus', 'WAIVED');

    $never = $seed('never started');
    $read($never)->assertJsonPath('data.status', 'EXITED_DUE_TO_DRIVER_DELAY')
        ->assertJsonPath('data.cancelledReason', 'trip_expired')
        ->assertJsonPath('data.paymentStatus', 'WAIVED');
    expect(ScheduledTrip::query()->whereKey(Booking::query()->whereKey($never)->value('scheduled_trip_id'))->value('status'))->toBe(ScheduledTripStatus::Expired)
        ->and(Attendance::query()->whereKey($never)->value('status')->value)->toBe('driver_no_show');

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/seed/past-bookings', ['count' => 1, 'ending' => 'SOMETHING_ELSE'])
        ->assertStatus(422);
});

it('rejects an approved, active car so the driver sees action required', function () {
    $driver = approvedDriver('01020001009', 'driver');
    $car = Vehicle::query()->where('is_active', true)->sole();

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/testing/driver/vehicles/{$car->id}/reject", [
        'item' => 'vehicle_registration', 'reason' => 'The registration photo is blurry.',
    ])->assertOk()->assertJsonPath('data.verificationStatus', 'REJECTED')->assertJsonPath('data.isActive', true);

    test()->flushHeaders()->withToken($driver)->getJson('/api/v1/driver/verification')
        ->assertOk()
        ->assertJsonPath('data.overall', 'action_required')
        ->assertJsonPath('data.actionItem.key', 'vehicle_registration')
        ->assertJsonPath('data.actionItem.rejectionReason', 'The registration photo is blurry.');

    test()->flushHeaders()->withToken($driver)->getJson('/api/v1/driver/vehicles')
        ->assertOk()->assertJsonPath('data.0.status', 'REJECTED');
});

it('fails /auth/me, carries error.fields, and sets faults by phone for a signed-out device', function () {
    $mine = verifiedPassenger('01020001001');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', [
        'path' => '/auth/me', 'mode' => 'error', 'status' => 422, 'code' => 'VALIDATION_FAILED', 'fields' => ['fullName' => 'Too short.'],
    ])->assertStatus(201)->assertJsonPath('data.fields.fullName.0', 'Too short.');

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/auth/me')
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonPath('error.fields.fullName.0', 'Too short.');
    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/auth/me')->assertOk();

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => '/auth/logout', 'mode' => 'delay', 'delayMs' => 600])
        ->assertStatus(422);
    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => '/bookings', 'mode' => 'error', 'status' => 500, 'fields' => ['x', 'y']])
        ->assertStatus(422);

    test()->flushHeaders()->postJson('/api/v1/testing/faults/by-phone', [
        'phone' => '01055501234', 'path' => '/auth/otp/request', 'mode' => 'error', 'status' => 422,
        'code' => 'AUTH_PHONE_INVALID', 'fields' => ['phone' => ['Not this one.']],
    ])->assertStatus(201)->assertJsonPath('data.phone', '+201055501234');

    test()->flushHeaders()->getJson('/api/v1/testing/faults/by-phone?phone=01055501234')->assertOk()->assertJsonCount(1, 'data');

    test()->flushHeaders()->postJson('/api/v1/auth/otp/request', [
        'phone' => '01055501235', 'purpose' => 'authentication', 'device' => ['publicId' => 'other', 'platform' => 'android'],
    ])->assertStatus(202);

    test()->flushHeaders()->postJson('/api/v1/auth/otp/request', [
        'phone' => '01055501234', 'purpose' => 'authentication', 'device' => ['publicId' => 'signed-out', 'platform' => 'android'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'AUTH_PHONE_INVALID')->assertJsonPath('error.fields.phone.0', 'Not this one.');

    test()->flushHeaders()->postJson('/api/v1/testing/faults/by-phone', [
        'phone' => '01020001001', 'path' => '/notifications', 'mode' => 'error', 'status' => 503,
    ])->assertStatus(201);

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/notifications')->assertStatus(503);

    test()->flushHeaders()->postJson('/api/v1/testing/faults/by-phone', ['phone' => '01099999999', 'path' => '/notifications', 'mode' => 'error', 'status' => 503])
        ->assertStatus(403)->assertJsonPath('error.code', 'TEST_NUMBER_ONLY');

    test()->flushHeaders()->postJson('/api/v1/testing/faults/by-phone', ['phone' => '01055501234', 'path' => '/notifications', 'mode' => 'delay', 'delayMs' => 500])
        ->assertStatus(201);
    test()->flushHeaders()->deleteJson('/api/v1/testing/faults/by-phone?phone=01055501234')->assertOk()->assertJsonPath('data.cleared', 2);
});

it('fails the next photo upload on any report with a wildcard path', function () {
    $mine = verifiedPassenger('01020001001');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', [
        'path' => '/incidents/*/evidence', 'method' => 'POST', 'mode' => 'error', 'status' => 500,
    ])->assertStatus(201);

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/incidents/01JAAAAAAAAAAAAAAAAAAAAAAA/evidence')->assertStatus(404);

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/incidents/01JAAAAAAAAAAAAAAAAAAAAAAA/evidence')
        ->assertStatus(500)->assertHeader('X-Testing-Fault')->assertJsonPath('error.code', 'SERVER_ERROR');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/incidents/01JAAAAAAAAAAAAAAAAAAAAAAA/evidence')
        ->assertHeaderMissing('X-Testing-Fault');
});

it('adds placeholder evidence on a decline so the second appeal can be sent, then none are left', function () {
    $mine = verifiedPassenger('01020001001');

    $eventId = test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/reliability/events', ['kind' => 'passenger_cancelled_late'])
        ->assertStatus(201)->json('data.0.id');

    $appeal = fn (): string => test()->flushHeaders()->withToken($mine)
        ->postJson("/api/v1/account/reliability/events/{$eventId}/appeal", ['reason' => 'My child was ill.'])
        ->assertOk()->json('data.appeal.id');

    $first = $appeal();

    test()->flushHeaders()->withToken($mine)->postJson("/api/v1/testing/reliability/appeals/{$first}/decide", ['decision' => 'declined', 'skipEvidence' => true])
        ->assertOk()->assertJsonPath('data.appealsLeft', 1)->assertJsonPath('data.appealRefusal', null);

    $second = $appeal();

    test()->flushHeaders()->withToken($mine)->postJson("/api/v1/testing/reliability/appeals/{$second}/decide", ['decision' => 'declined'])
        ->assertOk()->assertJsonPath('data.appealsLeft', 0)->assertJsonPath('data.appealRefusal', 'NO_APPEALS_LEFT');

    expect(ReliabilityEventAppeal::query()->count())->toBe(2);
});

it('hides the InstaPay account from the caller only, and puts it back', function () {
    $mine = verifiedPassenger('01020001001');
    $theirs = verifiedPassenger('01020001002', device: 'pax-2');

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/account/debt')->assertOk()->assertJsonPath('data.instapay.handle', fn ($v) => $v !== null);

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/payments/instapay', ['enabled' => false])
        ->assertOk()->assertJsonPath('data.enabled', false);

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/account/debt')->assertOk()->assertJsonPath('data.instapay', null);
    test()->flushHeaders()->withToken($theirs)->getJson('/api/v1/account/debt')->assertOk()->assertJsonPath('data.instapay.handle', fn ($v) => $v !== null);

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/payments/instapay', ['enabled' => true])->assertOk();
    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/account/debt')->assertJsonPath('data.instapay.handle', fn ($v) => $v !== null);

    config(['rafeeq.testing.api' => false]);
    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/payments/instapay', ['enabled' => false])->assertStatus(404);
    test()->flushHeaders()->postJson('/api/v1/testing/faults/by-phone', ['phone' => '01055501234'])->assertStatus(404);
});
