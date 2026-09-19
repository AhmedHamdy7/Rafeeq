<?php

use App\Domains\Verification\Models\TrustScore;
use Illuminate\Support\Facades\Schema;

it('never exposes the internal score or its components', function () {
    $trustScore = TrustScore::factory()->create();

    expect($trustScore->toArray())->not->toHaveKey('score')
        ->and($trustScore->toArray())->not->toHaveKey('components');
});

it('uses the user_id as its primary key with no separate id column', function () {
    $trustScore = TrustScore::factory()->create();

    expect($trustScore->getKeyName())->toBe('user_id')
        ->and(Schema::hasColumn('trust_scores', 'id'))->toBeFalse();
});
