<?php

use App\Domains\Admin\Models\FeatureFlag;

it('is disabled entirely when enabled is false regardless of rollout', function () {
    $flag = FeatureFlag::factory()->create(['enabled' => false, 'rollout_percentage' => 100]);

    expect($flag->isEnabledFor('user-1'))->toBeFalse();
});

it('is enabled for everyone at 100% rollout', function () {
    $flag = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 100]);

    expect($flag->isEnabledFor('user-1'))->toBeTrue()
        ->and($flag->isEnabledFor('user-2'))->toBeTrue();
});

it('is disabled for everyone at 0% rollout', function () {
    $flag = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 0]);

    expect($flag->isEnabledFor('user-1'))->toBeFalse();
});

it('is deterministic for the same seed at a partial rollout', function () {
    $flag = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 50]);

    expect($flag->isEnabledFor('same-seed'))->toBe($flag->isEnabledFor('same-seed'));
});
