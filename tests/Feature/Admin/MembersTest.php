<?php

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\Incident;
use App\Livewire\Admin\Members;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The MEMBERS section and putting an account on hold (Phase 13).
 *
 * 🔴 Until this existed nothing in the platform could suspend an account: `account.active`
 * enforced a state that only a test could create, and screen 35 had no case reference and
 * no review time to show because the act that writes them did not exist.
 */
beforeEach(function () {
    Storage::fake('documents');
    fakeOtpSender();

    $this->ops = adminWithRole(AdminRole::Operations);
    $this->token = signIn('01112223344')['session']['accessToken'];
    $this->member = User::query()->where('phone_e164', '+201112223344')->sole();
});

function holdThroughDashboard(User $member, string $reason = 'safety_report', string $note = 'Two reports about unsafe driving this week, holding for review.', ?string $incidentId = null)
{
    return Livewire::test(Members::class)
        ->call('open', $member->id)
        ->call('startForm', 'suspend')
        ->set('reasonCode', $reason)
        ->set('note', $note)
        ->set('incidentId', $incidentId ?? '')
        ->call('suspend');
}

/*
|--------------------------------------------------------------------------
| The directory
|--------------------------------------------------------------------------
*/

/**
 * 🔒 A full number finds the account; the list never prints one back.
 */
it('finds a member by their full number and shows it masked', function () {
    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->set('search', '01112223344')
        ->assertSee('+20 11 *** 3344')
        ->assertDontSee('+201112223344')
        ->assertDontSee('01112223344');
});

it('finds a member by name', function () {
    $this->member->forceFill(['full_name' => 'Mariam Hassan Abdelaziz'])->save();
    User::factory()->create(['full_name' => 'Omar Aly']);

    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->set('search', 'Hassan')
        ->assertSee('Mariam Hassan Abdelaziz')
        ->assertDontSee('Omar Aly');
});

it('treats a percent sign in the search as a character, not a wildcard', function () {
    $this->member->forceFill(['full_name' => 'Mariam Hassan'])->save();

    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->set('search', '%')
        ->assertSee(__('admin.members.empty'));
});

it('lists people a report is open about under Flagged', function () {
    $reported = User::factory()->create(['full_name' => 'Reported Person']);
    User::factory()->create(['full_name' => 'Quiet Person']);
    Incident::factory()->create(['reported_user_id' => $reported->id]);

    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->call('pick', 'flagged')
        ->assertSee('Reported Person')
        ->assertDontSee('Quiet Person');
});

/*
|--------------------------------------------------------------------------
| Putting an account on hold
|--------------------------------------------------------------------------
*/

it('puts an account on hold with a case reference and a review time', function () {
    actingAsAdmin($this->ops);

    holdThroughDashboard($this->member)->assertHasNoErrors();

    $hold = AccountSuspension::sole();

    expect($this->member->refresh()->account_status)->toBe(AccountStatus::Suspended)
        ->and($hold->case_number)->toMatch('/^RF-\d{6}$/')
        ->and($hold->reason_code)->toBe(SuspensionReason::SafetyReport)
        ->and($hold->suspended_by_admin_id)->toBe($this->ops->id)
        ->and((int) round($hold->suspended_at->diffInHours($hold->review_due_at)))->toBe(24);

    $audit = AdminAction::query()->where('action', 'account.suspend')->sole();

    expect($audit->reason)->toContain('unsafe driving')
        ->and($audit->new_value)->toMatchArray(['case_number' => $hold->case_number]);
});

it('takes the review time from settings', function () {
    PlatformSetting::updateOrCreate(
        ['setting_key' => 'admin.suspension_review_hours'],
        ['setting_value' => 48, 'value_type' => 'integer'],
    );

    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member);

    $hold = AccountSuspension::sole();

    expect((int) round($hold->suspended_at->diffInHours($hold->review_due_at)))->toBe(48);
});

it('requires a reason from the list and a note for the file', function () {
    actingAsAdmin($this->ops);

    holdThroughDashboard($this->member, reason: 'because', note: 'Long enough note for the file here.')
        ->assertHasErrors('reasonCode');

    holdThroughDashboard($this->member, note: 'short')->assertHasErrors('note');

    expect($this->member->refresh()->account_status)->toBe(AccountStatus::Active);
});

/**
 * A second "suspend" would mint a second case number for the same hold, and the member would
 * be quoting one the team cannot find.
 */
it('refuses to put an account on hold twice', function () {
    actingAsAdmin($this->ops);

    holdThroughDashboard($this->member);
    holdThroughDashboard($this->member)->assertHasErrors('note');

    expect(AccountSuspension::count())->toBe(1);
});

it('links the hold to a report only if the report is about this member', function () {
    $mine = Incident::factory()->create(['reported_user_id' => $this->member->id]);
    $someoneElses = Incident::factory()->create(['reported_user_id' => User::factory()->create()->id]);

    actingAsAdmin($this->ops);

    // Refused as "not found", the same answer a made-up id gets.
    holdThroughDashboard($this->member, incidentId: $someoneElses->id)->assertHasErrors('note');

    expect(AccountSuspension::count())->toBe(0);

    holdThroughDashboard($this->member, incidentId: $mine->id)->assertHasNoErrors();

    expect(AccountSuspension::sole()->incident_id)->toBe($mine->id);
});

/*
|--------------------------------------------------------------------------
| What the member sees
|--------------------------------------------------------------------------
*/

/**
 * Screen 35: the reason as a sentence, a reference and a time — and never the note staff
 * wrote to the file.
 */
it('tells the member why, under which reference, and when — but not the staff note', function () {
    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member, note: 'Reporter is her ex-colleague, check for a grudge.');

    $hold = AccountSuspension::sole();

    $me = test()->withToken($this->token)->getJson('/api/v1/auth/me')->assertOk();

    $me->assertJsonPath('data.user.accountStatus', 'SUSPENDED')
        ->assertJsonPath('data.nextStep', 'ACCOUNT_SUSPENDED')
        ->assertJsonPath('data.user.suspension.caseNumber', $hold->case_number)
        ->assertJsonPath('data.user.suspension.reasonCode', 'safety_report')
        ->assertJsonPath('data.user.suspension.reviewDueAt', $hold->review_due_at->toIso8601String())
        ->assertJsonPath('data.user.suspensionReason', __('auth.suspension_reasons.safety_report'));

    expect($me->getContent())->not->toContain('grudge');
});

it('words the reason in the language the app asked for', function () {
    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member);

    test()->withToken($this->token)->withHeader('Accept-Language', 'ar')
        ->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.user.suspensionReason', __('auth.suspension_reasons.safety_report', locale: 'ar'));
});

it('carries the reference on the refusal, so the app can show screen 35 mid-flow', function () {
    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member);

    test()->withToken($this->token)->getJson('/api/v1/search/commutes?'.http_build_query(searchCriteria()))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED')
        ->assertJsonPath('error.fields.caseNumber.0', AccountSuspension::sole()->case_number)
        ->assertJsonPath('error.fields.reviewDueAt.0', fn ($value) => $value !== null);
});

/**
 * 🔴 A hold must never close the safety path. Somebody on hold can still be in a car.
 */
it('still lets somebody on hold raise an SOS', function () {
    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member);

    test()->withToken($this->token)->postJson('/api/v1/sos')->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| A driver on hold
|--------------------------------------------------------------------------
*/

/**
 * 🔴 Search used to check only that the driver PROFILE was approved. A driver whose ACCOUNT
 * was on hold stayed in results, collecting seat requests nobody could answer.
 */
it('takes a driver on hold out of search, and puts them back when lifted', function () {
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    expect(search($paxToken)->assertOk()->json('data'))->not->toBeEmpty();

    actingAsAdmin($this->ops);
    holdThroughDashboard($driver);

    expect(search($paxToken)->assertOk()->json('data'))->toBeEmpty();

    // A seat request by id is refused the same way — the search's filters are the gate.
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(404);

    Livewire::test(Members::class)
        ->call('open', $driver->id)
        ->call('startForm', 'reinstate')
        ->set('note', 'Reports reviewed, both unfounded, lifting the hold.')
        ->call('reinstate')
        ->assertHasNoErrors();

    expect(search($paxToken)->assertOk()->json('data'))->not->toBeEmpty();
});

it('warns staff how many booked days a hold on a driver strands, before they confirm', function () {
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01223339999', device: 'pax-2');
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));

    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->call('open', $driver->id)
        ->call('startForm', 'suspend')
        ->assertSee(trans_choice('admin.members.stranded', 1, ['count' => 1]));
});

/*
|--------------------------------------------------------------------------
| Lifting a hold
|--------------------------------------------------------------------------
*/

it('lifts a hold, keeps it in the history, and the member is active again', function () {
    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member);

    Livewire::test(Members::class)
        ->call('open', $this->member->id)
        ->call('startForm', 'reinstate')
        ->set('note', 'Spoke to both parties, no further action needed.')
        ->call('reinstate')
        ->assertHasNoErrors()
        ->assertSee(AccountSuspension::sole()->case_number);

    $hold = AccountSuspension::sole();

    expect($this->member->refresh()->account_status)->toBe(AccountStatus::Active)
        ->and($this->member->suspension_reason)->toBeNull()
        ->and($hold->lifted_at)->not->toBeNull()
        ->and($hold->lifted_by_admin_id)->toBe($this->ops->id)
        ->and(AdminAction::query()->where('action', 'account.reactivate')->sole()->reason)->toContain('no further action');

    test()->withToken($this->token)->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.user.accountStatus', 'ACTIVE')
        ->assertJsonMissingPath('data.user.suspension');
});

it('refuses to lift a hold that is not there', function () {
    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->call('open', $this->member->id)
        ->call('startForm', 'reinstate')
        ->set('note', 'Nothing to lift here, should be refused.')
        ->call('reinstate')
        ->assertHasErrors('note');
});

it('never deletes a hold', function () {
    $hold = AccountSuspension::factory()->create();

    expect(fn () => $hold->delete())->toThrow(RuntimeException::class);
});

it('flags a hold whose promised review time has passed', function () {
    actingAsAdmin($this->ops);
    holdThroughDashboard($this->member);

    AccountSuspension::query()->update(['review_due_at' => now()->subHour()]);

    Livewire::test(Members::class)
        ->call('pick', 'suspended')
        ->assertSee(__('admin.members.review_overdue'));
});

/*
|--------------------------------------------------------------------------
| Who may do what
|--------------------------------------------------------------------------
*/

it('lets support find a member but not put them on hold', function () {
    $support = adminWithRole(AdminRole::Support);

    expect($support->can(AdminPermission::MemberSuspend->value))->toBeFalse();

    actingAsAdmin($support);

    Livewire::test(Members::class)
        ->assertOk()
        ->call('open', $this->member->id)
        ->call('startForm', 'suspend')
        ->assertForbidden();
});

it('keeps finance off the page', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Members::class)->assertForbidden();
});
