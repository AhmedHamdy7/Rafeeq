<?php

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);
});

function p20Published(string $driverToken, array $overrides = []): array
{
    $commuteId = readyCommute($driverToken, Vehicle::query()->where('is_active', true)->sole()->id, $overrides);
    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$commuteId, ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->firstOrFail()->id];
}

it('closes a seat request as expired or ended', function (string $status) {
    $driver = approvedDriver();
    [$commuteId, $tripId] = p20Published($driver);
    $rider = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($rider, $commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($rider)->postJson("/api/v1/testing/seat-requests/{$requestId}/close", ['status' => $status])
        ->assertOk()
        ->assertJsonPath('data.status', $status);

    test()->flushHeaders()->withToken($rider)->getJson("/api/v1/seat-requests/{$requestId}")
        ->assertOk()
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.endedAt', fn ($value) => $value !== null);

    expect(SeatRequest::findOrFail($requestId)->status->value)->toBe(strtolower($status));
})->with(['EXPIRED', 'ENDED']);

it('still refuses a status the call does not set', function () {
    $driver = approvedDriver();
    [$commuteId, $tripId] = p20Published($driver);
    $rider = verifiedPassenger('01112223344', device: 'pax-1');
    $requestId = requestSeat($rider, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($rider)->postJson("/api/v1/testing/seat-requests/{$requestId}/close", ['status' => 'APPROVED'])
        ->assertStatus(422);
});

it('clears her gender so a women-only commute asks for it', function () {
    $rider = verifiedPassenger('01112223344', device: 'pax-1');

    test()->flushHeaders()->withToken($rider)->patchJson('/api/v1/testing/account/fields', ['gender' => null])
        ->assertOk()
        ->assertJsonPath('data.gender', null);

    expect(User::query()->where('phone_e164', '+201112223344')->first()->gender->value)->toBe('prefer_not_to_say');

    test()->flushHeaders()->withToken($rider)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.gender', null);

    test()->flushHeaders()->withToken($rider)->patchJson('/api/v1/testing/account/fields', ['gender' => 'woman'])
        ->assertOk()
        ->assertJsonPath('data.gender', 'woman');

    test()->flushHeaders()->withToken($rider)->patchJson('/api/v1/testing/account/fields', ['gender' => 'prefer_not_to_say'])
        ->assertStatus(422);
});

it('pauses a women-only commute for the gender check and resumes it', function () {
    $driver = approvedDriver();
    [$commuteId] = p20Published($driver, ['audience' => 'women_only']);

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/testing/commutes/{$commuteId}/pause", ['reason' => 'women_only_check'])
        ->assertOk()
        ->assertJsonPath('data.status', 'PAUSED')
        ->assertJsonPath('data.pausedReason', 'gender_review');

    expect(CommuteOffer::findOrFail($commuteId)->paused_reason)->toBe(CommutePausedReason::GenderReview);

    test()->flushHeaders()->withToken($driver)->deleteJson("/api/v1/testing/commutes/{$commuteId}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED')
        ->assertJsonPath('data.pausedReason', null);

    expect(CommuteOffer::findOrFail($commuteId)->status)->toBe(CommuteOfferStatus::Published);
});

it('refuses the gender pause on a commute open to everyone or someone else’s', function () {
    $driver = approvedDriver();
    [$commuteId] = p20Published($driver, ['audience' => 'any_verified']);

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/testing/commutes/{$commuteId}/pause", ['reason' => 'women_only_check'])
        ->assertStatus(422);

    $other = verifiedPassenger('01112223344', device: 'pax-1');

    test()->flushHeaders()->withToken($other)->postJson("/api/v1/testing/commutes/{$commuteId}/pause", ['reason' => 'women_only_check'])
        ->assertStatus(404);
});

it('says where a test notification went', function () {
    $rider = verifiedPassenger('01112223344', device: 'pax-1');
    $userId = User::query()->where('phone_e164', '+201112223344')->value('id');

    Device::query()->where('user_id', $userId)->sole()->forceFill(['push_token' => 'fcm-token-for-the-test-abcdefgh'])->save();

    $response = test()->flushHeaders()->withToken($rider)->postJson('/api/v1/testing/notifications', ['type' => 'waitlist_cleared_by_change'])
        ->assertStatus(201)
        ->assertJsonPath('meta.inboxWritten', true)
        ->assertJsonPath('meta.pushAllowed', true)
        ->assertJsonPath('meta.pushTokens', 1);

    $pushIds = $response->json('meta.pushIds');
    expect($pushIds)->toHaveCount(1);

    $log = test()->flushHeaders()->withToken($rider)->getJson('/api/v1/testing/push-log')->assertOk()->json('data');

    expect($log['devices'])->toHaveCount(1)
        ->and($log['devices'][0]['hasPushToken'])->toBeTrue()
        ->and($log['devices'][0]['pushTokenEnd'])->toBe('abcdefgh')
        ->and($log['pushes'][0]['id'])->toBe($pushIds[0])
        ->and($log['pushes'][0]['type'])->toBe('waitlist_cleared_by_change')
        ->and($log['pushes'][0]['state'])->toBeIn(['sent', 'failed', 'queued'])
        ->and($log)->toHaveKey('pushProviderConfigured');
});
