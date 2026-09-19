<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;

it('allows only one group per commute offer', function () {
    $offer = CommuteOffer::factory()->published()->create();

    CommuteGroup::factory()->create(['commute_offer_id' => $offer->id]);
    CommuteGroup::factory()->create(['commute_offer_id' => $offer->id]);
})->throws(QueryException::class);

it('rejects a duplicate membership for the same user in the same group', function () {
    $group = CommuteGroup::factory()->create();
    $user = User::factory()->create();

    GroupMember::factory()->for($group, 'commuteGroup')->for($user)->create();
    GroupMember::factory()->for($group, 'commuteGroup')->for($user)->create();
})->throws(QueryException::class);

it('allows the same user to belong to different groups', function () {
    $user = User::factory()->create();

    GroupMember::factory()->for($user)->create();
    $second = GroupMember::factory()->for($user)->create();

    expect($second->exists)->toBeTrue();
});

it('distinguishes driver, member and trial roles', function () {
    $group = CommuteGroup::factory()->create();

    $driver = GroupMember::factory()->for($group, 'commuteGroup')->driver()->create();
    $trial = GroupMember::factory()->for($group, 'commuteGroup')->trial()->create();

    expect($driver->role)->toBe(GroupMemberRole::Driver)
        ->and($trial->role)->toBe(GroupMemberRole::Trial)
        ->and($group->activeMembers)->toHaveCount(2);
});
