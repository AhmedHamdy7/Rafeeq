<?php

use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\AuthSession;

it('links a rotated session back to its predecessor', function () {
    $first = AuthSession::factory()->create();
    $second = AuthSession::factory()->create([
        'user_id' => $first->user_id,
        'device_id' => $first->device_id,
        'token_family_id' => $first->token_family_id,
        'previous_session_id' => $first->id,
    ]);

    expect($second->previousSession->id)->toBe($first->id);
});

it('hides the refresh token hash from serialization', function () {
    $session = AuthSession::factory()->create();

    expect($session->toArray())->not->toHaveKey('refresh_token_hash');
});

it('marks a session revoked with a typed reason', function () {
    $session = AuthSession::factory()->revoked()->create();

    expect($session->isRevoked())->toBeTrue()
        ->and($session->revocation_reason)->toBe(SessionRevocationReason::UserLogout);
});
