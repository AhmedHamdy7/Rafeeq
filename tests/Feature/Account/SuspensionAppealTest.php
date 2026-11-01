<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Enums\DisputeResolution;
use App\Livewire\Admin\Members;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * AUTH-19, D-0018 (follow-up F1-3): a conduct suspension stays in the app with Appeal; only a
 * permanent ban shows the Account restricted screen.
 */
beforeEach(function () {
    Storage::fake('documents');
    fakeOtpSender();

    $this->ops = adminWithRole(AdminRole::Operations);
    $this->token = signIn('01112223344')['session']['accessToken'];
    completeBasicProfile($this->token);
    $this->member = User::query()->where('phone_e164', '+201112223344')->sole();
});

function holdMember(User $member, bool $permanent = false): void
{
    Livewire::test(Members::class)
        ->call('open', $member->id)
        ->call('startForm', 'suspend')
        ->set('reasonCode', 'policy_breach')
        ->set('note', 'Abusive messages to two drivers this week, holding for review.')
        ->set('permanent', $permanent)
        ->call('suspend')
        ->assertHasNoErrors();
}

function appealHold(string $token, array $body = [])
{
    return test()->withToken($token)->postJson('/api/v1/account/suspension/appeal', array_merge([
        'message' => 'I never sent those messages; somebody else had my phone that week.',
    ], $body));
}

function decideAppeal(User $member, SuspensionAppeal $appeal, bool $overturn): void
{
    Livewire::test(Members::class)
        ->call('open', $member->id)
        ->set('note', 'Read the messages and the report together, decided.')
        ->call('decideAppeal', $appeal->id, $overturn)
        ->assertHasNoErrors();
}

it('keeps a suspended member in the app: Home, a banner, and her reads', function () {
    actingAsAdmin($this->ops);
    holdMember($this->member);

    test()->withToken($this->token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.nextStep', 'HOME')
        ->assertJsonPath('data.user.suspension.permanent', false)
        ->assertJsonPath('data.user.suspension.appeal.canAppeal', true)
        ->assertJsonPath('data.user.suspension.appeal.needsNewEvidence', false)
        ->assertJsonPath('data.user.suspension.appeal.appeals', []);

    // Read-only: her trips list opens, a write is refused.
    test()->withToken($this->token)->getJson('/api/v1/my-bookings')->assertOk();
    test()->withToken($this->token)->putJson('/api/v1/account/profile/basic', ['fullName' => 'Mariam', 'gender' => 'woman'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
});

it('keeps the restricted screen for a permanent ban, reads included', function () {
    actingAsAdmin($this->ops);
    holdMember($this->member, permanent: true);

    expect(AccountSuspension::sole()->is_permanent)->toBeTrue();

    test()->withToken($this->token)->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.nextStep', 'RESTRICTED_ACCOUNT')
        ->assertJsonPath('data.user.suspension.permanent', true)
        ->assertJsonPath('data.user.suspension.appeal.canAppeal', false);

    test()->withToken($this->token)->getJson('/api/v1/my-bookings')->assertStatus(403);

    appealHold($this->token)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'PERMANENT_BAN');
});

it('cancels every upcoming seat she holds, with no fee, when she is suspended', function () {
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    $passenger = User::query()->where('phone_e164', '+201223339999')->sole();

    actingAsAdmin($this->ops);
    holdMember($passenger);

    $booking = Booking::sole();

    expect($booking->status)->toBe(BookingStatus::CancelledByPassenger)
        ->and($booking->cancelled_reason)->toBe('account_suspended')
        ->and((int) $booking->cancellation_fee_piastres)->toBe(0);
});

it('takes one appeal within 48 hours, then a second only with new evidence', function () {
    actingAsAdmin($this->ops);
    holdMember($this->member);

    appealHold($this->token)
        ->assertStatus(201)
        ->assertJsonPath('data.attempt', 1)
        ->assertJsonPath('data.status', 'pending');

    // One at a time.
    appealHold($this->token)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'APPEAL_PENDING');

    decideAppeal($this->member, SuspensionAppeal::sole(), overturn: false);

    expect($this->member->refresh()->account_status)->toBe(AccountStatus::Suspended);

    appealHold($this->token)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'EVIDENCE_REQUIRED');

    test()->withToken($this->token)->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.user.suspension.appeal.needsNewEvidence', true)
        ->assertJsonPath('data.user.suspension.appeal.appeals.0.status', 'upheld');

    appealHold($this->token, ['evidence' => 'Police report 2026/4411 for the stolen phone, filed that Monday.'])
        ->assertStatus(201)
        ->assertJsonPath('data.attempt', 2);

    decideAppeal($this->member, SuspensionAppeal::query()->where('attempt', 2)->sole(), overturn: false);

    appealHold($this->token, ['evidence' => 'Anything else'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'ALREADY_APPEALED');
});

it('closes the first appeal 48 hours after the hold', function () {
    actingAsAdmin($this->ops);
    holdMember($this->member);

    $this->travel(49)->hours();

    // The access token has long expired; sign the same device back in.
    fakeOtpSender();
    $token = signIn('01112223344')['session']['accessToken'];

    appealHold($token)->assertStatus(422)->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');

    test()->withToken($token)->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.user.suspension.appeal.canAppeal', false);
});

it('lifts the hold when staff overturn the appeal', function () {
    actingAsAdmin($this->ops);
    holdMember($this->member);
    appealHold($this->token)->assertStatus(201);

    decideAppeal($this->member, SuspensionAppeal::sole(), overturn: true);

    expect($this->member->refresh()->account_status)->toBe(AccountStatus::Active)
        ->and(SuspensionAppeal::sole()->resolution)->toBe(DisputeResolution::Overturned)
        ->and(AccountSuspension::sole()->lifted_at)->not->toBeNull();
});

it('answers 404 to an appeal from somebody who is not suspended', function () {
    appealHold($this->token)->assertStatus(404);
});
