<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\ContentPage;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();
});

function publishedTrip(string $driverToken): array
{
    $commuteId = readyCommute($driverToken, Vehicle::query()->where('is_active', true)->sole()->id);
    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$commuteId, ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->first()->id];
}

it('delays and fails only the caller\'s own requests, and uses each rule up', function () {
    $mine = verifiedPassenger('01020001001');
    $theirs = verifiedPassenger('01020001002', device: 'pax-2');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', [
        'path' => '/notifications', 'method' => 'GET', 'mode' => 'error', 'status' => 503, 'count' => 2,
    ])->assertStatus(201)->assertJsonPath('data.remaining', 2)->assertJsonPath('data.mode', 'error');

    test()->flushHeaders()->withToken($theirs)->getJson('/api/v1/notifications')->assertOk();

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/notifications')
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/notifications')->assertStatus(503);
    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/notifications')->assertOk();

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', [
        'path' => '/notifications', 'mode' => 'delay', 'delayMs' => 500,
    ])->assertStatus(201);

    $started = microtime(true);
    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/notifications')->assertOk();
    expect(microtime(true) - $started)->toBeGreaterThan(0.45);

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/testing/faults')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses testing and auth paths, bad input and more than ten open rules', function () {
    $mine = verifiedPassenger('01020001001');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => '/testing/reset', 'mode' => 'delay', 'delayMs' => 600])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => '/auth/logout', 'mode' => 'delay', 'delayMs' => 600])
        ->assertStatus(422);
    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => '/bookings', 'mode' => 'error', 'status' => 418])
        ->assertStatus(422);

    foreach (range(1, 10) as $i) {
        test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => "/bookings/{$i}", 'mode' => 'delay', 'delayMs' => 500])->assertStatus(201);
    }

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/faults', ['path' => '/bookings', 'mode' => 'delay', 'delayMs' => 500])
        ->assertStatus(409)->assertJsonPath('error.code', 'FAULT_LIMIT_REACHED');

    test()->flushHeaders()->withToken($mine)->deleteJson('/api/v1/testing/faults')->assertOk()->assertJsonPath('data.cleared', 10);
});

it('answers 429 with Retry-After on demand, including on the OTP request for a test number', function () {
    $mine = verifiedPassenger('01020001001');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/rate-limit', ['path' => '/auth/otp/request', 'retryAfterSeconds' => 30])
        ->assertStatus(201)->assertJsonPath('data.status', 429)->assertJsonPath('data.phone', '+201020001001');

    test()->flushHeaders()->postJson('/api/v1/auth/otp/request', [
        'phone' => '01020001001', 'purpose' => 'authentication', 'device' => ['publicId' => 'pax', 'platform' => 'android'],
    ])->assertStatus(429)->assertHeader('Retry-After', '30')->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

    test()->flushHeaders()->postJson('/api/v1/auth/otp/request', [
        'phone' => '01020001001', 'purpose' => 'authentication', 'device' => ['publicId' => 'pax', 'platform' => 'android'],
    ])->assertStatus(202);
});

it('expires the open code and lifts the lock for a test number only', function () {
    $sender = fakeOtpSender();
    $device = ['publicId' => 'otp-dev', 'platform' => 'android'];

    $challenge = test()->postJson('/api/v1/auth/otp/request', ['phone' => '01055501234', 'purpose' => 'authentication', 'device' => $device])
        ->assertStatus(202)->json('data.challengeId');

    foreach (range(1, 3) as $i) {
        test()->postJson('/api/v1/auth/otp/verify', ['challengeId' => $challenge, 'code' => '000000', 'device' => $device]);
    }

    test()->postJson('/api/v1/auth/otp/request', ['phone' => '01055501234', 'purpose' => 'authentication', 'device' => $device])
        ->assertJsonPath('error.code', 'AUTH_OTP_LOCKED');

    test()->postJson('/api/v1/testing/otp/unlock', ['phone' => '+201055501234'])
        ->assertOk()->assertJsonPath('data.lockedUntil', null)->assertJsonPath('data.attempts', 0);

    $fresh = test()->postJson('/api/v1/auth/otp/request', ['phone' => '01055501234', 'purpose' => 'authentication', 'device' => $device])
        ->assertStatus(202)->json('data.challengeId');

    test()->postJson('/api/v1/testing/otp/expire', ['phone' => '01055501234'])->assertOk()->assertJsonPath('data.expired', true);

    test()->postJson('/api/v1/auth/otp/verify', ['challengeId' => $fresh, 'code' => $sender->lastCode(), 'device' => $device])
        ->assertJsonPath('error.code', 'AUTH_OTP_EXPIRED');

    test()->postJson('/api/v1/testing/otp/expire', ['phone' => '01055501234'])->assertStatus(409)->assertJsonPath('error.code', 'OTP_NOT_REQUESTED');
    test()->postJson('/api/v1/testing/otp/unlock', ['phone' => '01112223344'])->assertStatus(403)->assertJsonPath('error.code', 'TEST_NUMBER_ONLY');
});

it('ends the caller\'s token, with or without the refresh token', function () {
    $signIn = signIn('01020001001', 'phone-a');
    $access = $signIn['session']['accessToken'];

    test()->flushHeaders()->withToken($access)->postJson('/api/v1/testing/session/revoke', ['refresh' => false])
        ->assertOk()->assertJsonPath('data.revoked', 1)->assertJsonPath('data.refreshRevoked', false);

    app('auth')->forgetGuards();
    test()->flushHeaders()->withToken($access)->getJson('/api/v1/auth/me')->assertStatus(401);

    $renewed = test()->flushHeaders()->postJson('/api/v1/auth/session/refresh', ['refreshToken' => $signIn['session']['refreshToken']])
        ->assertOk()->json('data.session');

    test()->flushHeaders()->withToken($renewed['accessToken'])->postJson('/api/v1/testing/session/revoke', ['refresh' => true])
        ->assertOk()->assertJsonPath('data.refreshRevoked', true);

    app('auth')->forgetGuards();
    test()->flushHeaders()->withToken($renewed['accessToken'])->getJson('/api/v1/auth/me')->assertStatus(401);
    test()->flushHeaders()->postJson('/api/v1/auth/session/refresh', ['refreshToken' => $renewed['refreshToken']])->assertStatus(401);
});

it('resets the driver\'s commutes and the seats others hold on them, keeping the active car and the verification', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = publishedTrip($driver);
    $passenger = verifiedPassenger('01020001001');
    $bookingId = approveSeat($driver, requestSeat($passenger, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));
    $driverId = CommuteOffer::query()->whereKey($commuteId)->value('driver_profile_id');
    $notices = Notification::query()->where('user_id', '!=', $driverId)->count();

    $answer = test()->flushHeaders()->withToken($driver)->postJson('/api/v1/testing/reset')->assertOk();

    expect($answer->json('data.commutes'))->toBe(1)
        ->and($answer->json('data.bookings'))->toBe(1)
        ->and($answer->json('data.kept'))->toBe(['verification'])
        ->and(CommuteOffer::query()->whereKey($commuteId)->exists())->toBeFalse()
        ->and(Booking::query()->whereKey($bookingId)->exists())->toBeFalse()
        ->and(Vehicle::query()->where('driver_profile_id', $driverId)->where('is_active', true)->exists())->toBeTrue()
        ->and(Notification::query()->where('user_id', '!=', $driverId)->count())->toBe($notices);

    test()->flushHeaders()->withToken($driver)->getJson('/api/v1/driver/seat-requests')->assertOk()->assertJsonCount(0, 'data');
    test()->flushHeaders()->withToken($driver)->getJson('/api/v1/driver/trips?scope=upcoming')->assertOk()->assertJsonCount(0, 'data');
});

it('resets a passenger and frees her seat on someone else\'s run', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = publishedTrip($driver);
    $passenger = verifiedPassenger('01020001001');
    approveSeat($driver, requestSeat($passenger, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    expect(ScheduledTrip::query()->whereKey($tripId)->value('seats_taken'))->toBe(1);

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/reset', ['keep' => ['verification', 'contacts']])
        ->assertOk()->assertJsonPath('data.bookings', 1)->assertJsonPath('data.seatRequests', 1);

    expect(ScheduledTrip::query()->whereKey($tripId)->value('seats_taken'))->toBe(0)
        ->and(CommuteOffer::query()->whereKey($commuteId)->exists())->toBeTrue();

    requestSeat($passenger, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201);
});

it('refuses a reset while a real member is aboard a run under way', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = publishedTrip($driver);
    $real = verifiedPassenger('01112223344');
    approveSeat($driver, requestSeat($real, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));
    underway($driver, $tripId);

    test()->flushHeaders()->withToken($driver)->postJson('/api/v1/testing/reset')
        ->assertStatus(409)->assertJsonPath('error.code', 'TRIP_IN_PROGRESS');
});

it('seeds finished bookings, one per day back, that read like real history', function () {
    $passenger = verifiedPassenger('01020001001');

    $ids = test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/seed/past-bookings', ['count' => 25])
        ->assertStatus(201)->assertJsonPath('data.count', 25)->json('data.bookingIds');

    expect(Booking::query()->whereKey($ids)->distinct()->pluck('status')->count())->toBe(7);

    $one = test()->flushHeaders()->withToken($passenger)->getJson("/api/v1/bookings/{$ids[0]}")->assertOk();
    expect($one->json('data.otherPartyUserId'))->not->toBeNull();

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/seed/past-bookings', ['count' => 3, 'ending' => 'COMPLETED'])
        ->assertStatus(201);

    expect(Booking::query()->where('status', BookingStatus::Completed->value)->count())->toBeGreaterThanOrEqual(7);
});

it('moves a decided request back, and refuses a pending one it would not expire', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = publishedTrip($driver);
    $passenger = verifiedPassenger('01020001001');
    $requestId = requestSeat($passenger, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/seat-requests/{$requestId}/age", ['days' => 1])
        ->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_STILL_PENDING');

    SeatRequest::query()->whereKey($requestId)->update(['status' => SeatRequestStatus::Rejected->value, 'responded_at' => now()]);

    $aged = test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/seat-requests/{$requestId}/age", ['days' => 8])
        ->assertOk()->assertJsonPath('data.status', 'REJECTED');

    expect(now()->diffInDays($aged->json('data.decidedAt'), true))->toBeGreaterThanOrEqual(7.9);
});

it('writes debt lines that behave like real ones, and a credit', function () {
    $passenger = verifiedPassenger('01020001001');

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'passenger', 'kind' => 'no_show_fee', 'amountPiastres' => 2750, 'count' => 2,
    ])->assertStatus(201)->assertJsonCount(2, 'data.lineIds')->assertJsonPath('data.owedPiastres', 5500);

    test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/account/debt')->assertOk();

    expect(DebtEntry::query()->orderBy('created_at')->first()->created_at->lessThan(now()->subHours(23)))->toBeTrue();

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'driver', 'kind' => 'commission', 'amountPiastres' => 11000,
    ])->assertStatus(403)->assertJsonPath('error.code', 'DRIVER_NOT_APPROVED');

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'passenger', 'kind' => 'commission', 'amountPiastres' => 11000,
    ])->assertStatus(422);

    test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/credits', ['amountPiastres' => 7500])
        ->assertStatus(201)->assertJsonPath('data.creditPiastres', 2000)->assertJsonPath('data.balancePiastres', -2000);

    $driver = approvedDriver('01020001009', 'driver');

    test()->flushHeaders()->withToken($driver)->postJson('/api/v1/testing/debt/seed', [
        'side' => 'driver', 'kind' => 'commission', 'amountPiastres' => 11000, 'disputed' => true,
    ])->assertStatus(201)->assertJsonPath('data.owedPiastres', 11000)->assertJsonCount(1, 'data.openDisputes');
});

it('sets odd values on one of her bookings', function () {
    $passenger = verifiedPassenger('01020001001');
    [$completed] = test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/seed/past-bookings', ['count' => 1, 'ending' => 'COMPLETED'])->json('data.bookingIds');
    [$cancelled] = test()->flushHeaders()->withToken($passenger)->postJson('/api/v1/testing/seed/past-bookings', ['count' => 1, 'ending' => 'CANCELLED_BY_DRIVER'])->json('data.bookingIds');

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$completed}/fields", ['paymentStatus' => 'WAIVED', 'pickupLabel' => null])
        ->assertOk()->assertJsonPath('data.id', $completed);

    expect(Booking::query()->whereKey($completed)->sole()->payment_status->value)->toBe('waived')
        ->and(Booking::query()->whereKey($completed)->value('pickup_label'))->toBeNull();

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$completed}/fields", ['cancellationReason' => 'day_cancelled'])
        ->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_NOT_CANCELLED');

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$cancelled}/fields", ['cancellationReason' => 'SOMETHING_NEW'])
        ->assertStatus(422);

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/bookings/{$cancelled}/fields", ['cancellationReason' => 'day_cancelled'])
        ->assertOk()->assertJsonPath('data.cancelledReason', 'day_cancelled');
});

it('writes the support page and puts back what was there before', function () {
    $mine = verifiedPassenger('01020001001');

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/content/support_contact')->assertStatus(404);

    test()->flushHeaders()->withToken($mine)->putJson('/api/v1/testing/content/support_contact', ['en' => "WhatsApp: +20 10 0000 0000\nsupport@example.com", 'ar' => 'واتساب'])
        ->assertOk()->assertJsonPath('data.key', 'support_contact');

    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/content/support_contact')->assertOk()->assertJsonPath('data.ar', 'واتساب');

    test()->flushHeaders()->withToken($mine)->deleteJson('/api/v1/testing/content/support_contact')->assertOk()->assertJsonPath('data.removed', true);
    test()->flushHeaders()->withToken($mine)->getJson('/api/v1/content/support_contact')->assertStatus(404);

    ContentPage::query()->create(['key' => 'guidelines', 'body_en' => 'Staff text', 'body_ar' => 'نص', 'version' => 4]);

    test()->flushHeaders()->withToken($mine)->putJson('/api/v1/testing/content/community_guidelines', ['en' => 'Test', 'ar' => 'تجربة'])
        ->assertOk()->assertJsonPath('data.version', 5);
    test()->flushHeaders()->withToken($mine)->deleteJson('/api/v1/testing/content/guidelines')
        ->assertOk()->assertJsonPath('data.removed', false)->assertJsonPath('data.restoredStaffVersion', true);

    expect(ContentPage::query()->find('guidelines')->body_en)->toBe('Staff text');

    test()->flushHeaders()->withToken($mine)->putJson('/api/v1/testing/content/terms', ['en' => 'x', 'ar' => 'x'])->assertStatus(404);
});

it('makes the car\'s last position old and holds off the driver\'s new points for a minute', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = publishedTrip($driver);
    $passenger = verifiedPassenger('01020001001');
    approveSeat($driver, requestSeat($passenger, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/testing/trips/{$tripId}/location/age", ['minutes' => 3])
        ->assertStatus(409)->assertJsonPath('error.code', 'TRIP_NOT_STARTED');

    underway($driver, $tripId);
    reportPosition($driver, $tripId, [position()])->assertSuccessful();

    test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/trips/{$tripId}/location/age", ['minutes' => 3])
        ->assertOk()->assertJsonPath('data.ageSeconds', 180)->assertJsonPath('data.isStale', true);

    reportPosition($driver, $tripId, [position(agoSeconds: 1)])->assertSuccessful();

    $session = TripSession::query()->where('scheduled_trip_id', $tripId)->sole();
    expect($session->last_location_at->lessThan(now()->subMinutes(2)))->toBeTrue();

    $read = test()->flushHeaders()->withToken($passenger)->getJson("/api/v1/trips/{$tripId}/location")->assertOk();
    expect(now()->diffInSeconds($read->json('data.lastLocationAt'), true))->toBeGreaterThanOrEqual(179);
});

it('sends a push with no inbox row', function () {
    $mine = verifiedPassenger('01020001001');
    $userId = User::query()->where('phone_e164', '+201020001001')->value('id');

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/push', ['type' => 'driver_arrived'])
        ->assertStatus(202)->assertJsonPath('data.type', 'driver_arrived');

    expect(Notification::query()->where('user_id', $userId)->where('channel', 'in_app')->where('type', 'driver_arrived')->exists())->toBeFalse();

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/push', ['type' => 'pickup_change_requested', 'data' => ['bookingId' => '01JXXXXXXXXXXXXXXXXXXXXXXX']])
        ->assertStatus(422);
});

it('answers 404 for every new call when the testing API is off', function () {
    $mine = verifiedPassenger('01020001001');
    config(['rafeeq.testing.api' => false]);

    test()->flushHeaders()->withToken($mine)->postJson('/api/v1/testing/reset')->assertStatus(404);
    test()->flushHeaders()->postJson('/api/v1/testing/otp/unlock', ['phone' => '01055501234'])->assertStatus(404);
});
