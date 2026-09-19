<?php

use App\Domains\Safety\Models\LiveShare;

it('hides the token hash from serialization', function () {
    $share = LiveShare::factory()->create();

    expect($share->toArray())->not->toHaveKey('token_hash');
});

it('reports active state based on revocation and expiry', function () {
    $active = LiveShare::factory()->create();
    $revoked = LiveShare::factory()->revoked()->create();
    $expired = LiveShare::factory()->create(['expires_at' => now()->subMinute()]);

    expect($active->isActive())->toBeTrue()
        ->and($revoked->isActive())->toBeFalse()
        ->and($expired->isActive())->toBeFalse();
});

it('uses a unique, unguessable token hash', function () {
    $a = LiveShare::factory()->create();
    $b = LiveShare::factory()->create();

    expect(strlen($a->getRawOriginal('token_hash')))->toBeGreaterThanOrEqual(64)
        ->and($a->getRawOriginal('token_hash'))->not->toBe($b->getRawOriginal('token_hash'));
});
