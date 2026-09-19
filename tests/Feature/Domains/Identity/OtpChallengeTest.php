<?php

use App\Domains\Identity\Models\OtpChallenge;
use Illuminate\Support\Facades\Schema;

it('never has a foreign key to users', function () {
    expect(Schema::hasColumn('otp_challenges', 'user_id'))->toBeFalse();
});

it('hides the code hash from serialization', function () {
    $challenge = OtpChallenge::factory()->create();

    expect($challenge->toArray())->not->toHaveKey('code_hash');
});

it('reports expiry correctly', function () {
    $fresh = OtpChallenge::factory()->create();
    $expired = OtpChallenge::factory()->expired()->create();

    expect($fresh->isExpired())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue();
});

it('tracks attempts remaining against max_attempts', function () {
    $challenge = OtpChallenge::factory()->create(['max_attempts' => 5, 'attempt_count' => 4]);

    expect($challenge->hasAttemptsRemaining())->toBeTrue();

    $challenge->increment('attempt_count');

    expect($challenge->fresh()->hasAttemptsRemaining())->toBeFalse();
});
