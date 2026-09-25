<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Leaving the group, with the notice the group is owed (Master Plan §889 — "leave
 * notice").
 *
 * `notice_period_days` exists because a commute is a standing arrangement, not a
 * booking: a driver who planned their month around four passengers should not find out
 * on Sunday night that one of them is gone.
 *
 * What the tests below pin down is the split. Bookings AFTER the notice date are
 * released immediately, so the seats can be filled while there is still time.
 * Bookings INSIDE it are kept, because the member is still travelling those days —
 * cancelling them would be the group leaving them rather than the reverse.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($this->paxToken, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $this->groupId = CommuteGroup::sole()->id;
    $this->noticeDays = (int) config('rafeeq.group.default_notice_period_days');
});

it('gives notice rather than leaving on the spot', function () {
    $membership = test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave", ['reason' => 'باقل مكان قريب'])
        ->assertOk()->json('data');

    expect($membership['status'])->toBe('NOTICE_GIVEN')
        ->and($membership['leavesOn'])->toBe(
            CarbonImmutable::now()->addDays($this->noticeDays)->toDateString()
        );

    // Still a member until that day: they are travelling this week.
    expect(GroupMember::query()
        ->where('user_id', '!=', null)
        ->where('status', GroupMemberStatus::NoticeGiven->value)
        ->count())->toBe(1);
});

/**
 * 🔴 The seats past the notice date go back on offer NOW, so there is still time for
 * somebody to take them.
 */
it('releases the seats beyond the notice period and keeps the ones inside it', function () {
    $leavesOn = CarbonImmutable::now()->addDays($this->noticeDays)->endOfDay();

    $beyond = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('departure_at', '>', $leavesOn)
        ->pluck('id');

    $within = ScheduledTrip::query()
        ->where('commute_offer_id', $this->commuteId)
        ->where('departure_at', '>', now())
        ->where('departure_at', '<=', $leavesOn)
        ->pluck('id');

    expect(Booking::query()->whereIn('scheduled_trip_id', $within)->count())
        ->toBeGreaterThan(0, 'There should be booked days inside the notice period.');

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")->assertOk();

    // Gone, and the seats with them.
    expect(Booking::query()
        ->whereIn('scheduled_trip_id', $beyond)
        ->where('status', BookingStatus::Confirmed->value)
        ->count())->toBe(0);

    ScheduledTrip::query()->whereIn('id', $beyond)->get()->each(
        fn (ScheduledTrip $trip) => expect($trip->seats_taken)->toBe(0)
    );

    // Kept: the member is still travelling these.
    expect(Booking::query()
        ->whereIn('scheduled_trip_id', $within)
        ->where('status', BookingStatus::Confirmed->value)
        ->count())->toBeGreaterThan(0);
});

/**
 * A notice with nothing to complete it would leave people in `notice_given` forever —
 * still on the driver's list, still nominally committed to days they said they were
 * leaving.
 */
it('completes the notice on the day, through the daily command', function () {
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")->assertOk();

    // The day before: nothing has changed yet.
    $this->travel($this->noticeDays - 1)->days();
    Artisan::call('memberships:roll-forward');

    $membership = GroupMember::query()->where('status', '!=', 'active')->sole();

    expect($membership->status)->toBe(GroupMemberStatus::NoticeGiven);

    // And the day after.
    $this->travel(2)->days();
    Artisan::call('memberships:roll-forward');

    $membership->refresh();

    expect($membership->status)->toBe(GroupMemberStatus::Left)
        ->and($membership->left_at)->not->toBeNull();
});

/**
 * Somebody serving out a notice is still in the group — they are in the car tomorrow.
 * Somebody who has left is not, and the group becomes as invisible to them as it was
 * before they joined.
 */
it('keeps them in the group during the notice and removes them after', function () {
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")->assertOk();

    test()->withToken($this->paxToken)->getJson("/api/v1/groups/{$this->groupId}")->assertOk();

    $this->travel($this->noticeDays + 1)->days();
    Artisan::call('memberships:roll-forward');

    // A fresh session, because eight days of travel outlive an access token. The
    // account keeps its approved verification — the point here is the membership.
    $token = signIn(phone: '01112223344', devicePublicId: 'pax-1-again')['session']['accessToken'];

    test()->withToken($token)->getJson("/api/v1/groups/{$this->groupId}")->assertStatus(404);
    test()->withToken($token)->getJson('/api/v1/groups')->assertOk()->assertJsonCount(0, 'data');
});

/**
 * A former member must not be seated again by the rolling job — that is the whole
 * reason the command completes notices BEFORE it extends bookings.
 */
it('does not seat somebody whose notice has run out', function () {
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")->assertOk();

    $this->travel($this->noticeDays + 1)->days();

    Artisan::call('commutes:generate-trips');
    Artisan::call('memberships:roll-forward');

    // Nothing ahead of them: every remaining booking is in the past.
    expect(Booking::query()
        ->where('status', BookingStatus::Confirmed->value)
        ->whereIn('scheduled_trip_id', ScheduledTrip::query()
            ->where('departure_at', '>', now())
            ->select('id'))
        ->count())->toBe(0);
});

it('refuses a second notice', function () {
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")->assertOk();

    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'GROUP_NOTICE_ALREADY_GIVEN');
});

/**
 * Not a permission problem: the group exists because the driver's commute does, and
 * "everyone travels except the person driving" is not a state to represent. Pausing or
 * archiving the commute is the operation they actually want.
 */
it('refuses to let the driver leave their own group', function () {
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'GROUP_DRIVER_CANNOT_LEAVE');

    expect(GroupMember::query()->where('role', 'driver')->sole()->status)
        ->toBe(GroupMemberStatus::Active);
});

it('refuses a notice from somebody outside the group', function () {
    $stranger = verifiedPassenger('01223334455', device: 'pax-2');

    test()->withToken($stranger)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")
        ->assertStatus(404);
});

/**
 * 🔴 Somebody who left must be able to come back, and this is the test that found they
 * could not.
 *
 * "One open request per passenger per commute" counts `approved` as open, and it is
 * enforced by a generated unique column. Their spent approval therefore held that slot
 * for good: a 409 on every attempt to rejoin, which neither they nor the driver could
 * clear. Completing the notice now ends the request as well as the membership.
 *
 * Rejoining also reactivates the one membership rather than adding a second — one per
 * person per group is a unique index, so this decides what "rejoining" means.
 */
it('lets somebody who left come back as a new member', function () {
    test()->withToken($this->paxToken)
        ->postJson("/api/v1/groups/{$this->groupId}/leave")->assertOk();

    $this->travel($this->noticeDays + 1)->days();
    Artisan::call('memberships:roll-forward');
    Artisan::call('commutes:generate-trips');

    // The approval that created the membership is finished with, which is what frees
    // the slot.
    expect(SeatRequest::sole()->status)->toBe(SeatRequestStatus::Ended);

    /*
     * Fresh sessions for BOTH sides: eight days of travel outlive an access token, the
     * driver's as much as the passenger's. Both accounts keep their verifications — the
     * point of this test is the membership, not the tokens.
     */
    $token = signIn(phone: '01112223344', devicePublicId: 'pax-1-again')['session']['accessToken'];
    $driverToken = signIn(phone: '01012345678', devicePublicId: 'driver-1-again')['session']['accessToken'];

    $requestId = requestSeat($token, $this->commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)
        ->assertJsonPath('data.membership.status', 'ACTIVE');

    // One membership, reactivated — not a second row.
    expect(GroupMember::query()->where('role', 'member')->count())->toBe(1);
});
