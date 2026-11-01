<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReviewAccountsSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * F1-1: the store-review sign-in. One number, one fixed code, working in production — because the
 * Apple and Google reviewers use the production build and cannot receive our SMS.
 */
beforeEach(function () {
    $this->sender = fakeOtpSender();

    config([
        'rafeeq.auth.otp.review_phone' => '01099990000',
        'rafeeq.auth.otp.review_code' => '246810',
    ]);
});

function reviewOtpRequest(string $phone = '01099990000')
{
    return test()->postJson('/api/v1/auth/otp/request', [
        'phone' => $phone,
        'device' => ['publicId' => 'review-device', 'platform' => 'ios'],
    ]);
}

it('gives the review number its fixed code and sends no SMS', function () {
    reviewOtpRequest()->assertStatus(202);

    expect(Hash::check('246810', OtpChallenge::sole()->code_hash))->toBeTrue()
        ->and($this->sender->sent)->toBe([]);
});

/**
 * 🔒 The point of the feature: production is where the reviewers are. The dev fixed code is
 * refused there; this one is not.
 */
it('works in production, where the dev fixed code is refused', function () {
    app()->detectEnvironment(fn () => 'production');

    $challengeId = reviewOtpRequest()->assertStatus(202)->json('data.challengeId');

    test()->postJson('/api/v1/auth/otp/verify', [
        'challengeId' => $challengeId,
        'code' => '246810',
        'device' => ['publicId' => 'review-device', 'platform' => 'ios'],
    ])->assertOk();
});

it('matches the number however it is typed', function () {
    reviewOtpRequest('+201099990000')->assertStatus(202);

    expect($this->sender->sent)->toBe([]);
});

it('changes nothing for any other number', function () {
    reviewOtpRequest('01012345678')->assertStatus(202);

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->lastCode())->not->toBe('246810');
});

it('records every use as a security event', function () {
    reviewOtpRequest()->assertStatus(202);

    expect(SecurityEvent::query()->where('event_type', SecurityEventType::ReviewOtpIssued->value)->count())->toBe(1);
});

it('keeps the resend cooldown on the review number', function () {
    reviewOtpRequest()->assertStatus(202);
    reviewOtpRequest()->assertStatus(429)->assertHeader('Retry-After');
});

it('does nothing unless both the number and the code are set', function () {
    config(['rafeeq.auth.otp.review_code' => null]);

    reviewOtpRequest()->assertStatus(202);

    expect($this->sender->sent)->toHaveCount(1);
});

it('refuses a review code of the wrong length', function () {
    config(['rafeeq.auth.otp.review_code' => '1234']);

    reviewOtpRequest()->assertStatus(500);

    expect(OtpChallenge::count())->toBe(0);
});

it('seeds a review account that is both a verified passenger and a driver with a published commute', function () {
    $this->seed(DatabaseSeeder::class);

    $this->artisan('rafeeq:review-accounts')->assertSuccessful();

    $review = User::query()->where('phone_e164', '+201099990000')->sole();

    expect($review->capabilities()->pluck('capability')->map(fn ($c) => $c instanceof Capability ? $c->value : $c)->all())
        ->toContain('passenger', 'driver')
        ->and(CommuteOffer::query()->where('driver_profile_id', $review->id)->where('status', CommuteOfferStatus::Published)->exists())->toBeTrue()
        ->and(CommuteOffer::query()->whereHas('driverProfile', fn ($p) => $p->whereHas('user', fn ($u) => $u->where('phone_e164', ReviewAccountsSeeder::SECOND_DRIVER_PHONE)))->exists())->toBeTrue()
        ->and(Booking::query()->where('passenger_user_id', $review->id)->exists())->toBeTrue();

    // Idempotent: a second run builds nothing new.
    $this->artisan('rafeeq:review-accounts')->assertSuccessful();

    expect(User::query()->where('phone_e164', '+201099990000')->count())->toBe(1)
        ->and(Booking::query()->where('passenger_user_id', $review->id)->count())->toBe(1);
});
