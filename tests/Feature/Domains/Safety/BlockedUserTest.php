<?php

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\BlockedUser;
use Illuminate\Database\QueryException;

it('rejects a duplicate block pair', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    BlockedUser::factory()->create(['blocker_user_id' => $a->id, 'blocked_user_id' => $b->id]);
    BlockedUser::factory()->create(['blocker_user_id' => $a->id, 'blocked_user_id' => $b->id]);
})->throws(QueryException::class);

it('detects a block in either direction — pitfall #27', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    BlockedUser::factory()->create(['blocker_user_id' => $b->id, 'blocked_user_id' => $a->id]);

    expect(BlockedUser::existsBetween($a->id, $b->id))->toBeTrue()
        ->and(BlockedUser::existsBetween($b->id, $a->id))->toBeTrue();
});

it('reports no block between two unrelated users', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect(BlockedUser::existsBetween($a->id, $b->id))->toBeFalse();
});
