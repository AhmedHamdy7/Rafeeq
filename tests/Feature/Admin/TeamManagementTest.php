<?php

use App\Domains\Admin\Actions\ManageTeamAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Enums\AdminStatus;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminInvitation;
use App\Domains\Admin\Models\AdminUser;
use App\Livewire\Admin\AcceptInvitation;
use App\Livewire\Admin\Team;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Team and roles (M) and the first sign-in from an invitation (S05, S06).
 */
beforeEach(function () {
    Cache::clear();
    $this->boss = adminWithRole(AdminRole::SuperAdmin);
});

/** The token at the end of an invitation link. */
function tokenFrom(string $link): string
{
    return basename(parse_url($link, PHP_URL_PATH));
}

it('invites a team member who cannot sign in until the first sign-in is done', function () {
    actingAsAdmin($this->boss);

    $component = Livewire::test(Team::class)
        ->call('startInvite')
        ->set('name', 'Dina Hassan')
        ->set('email', 'Dina@Rafeeq.test')
        ->set('role', AdminRole::Support->value)
        ->call('invite')
        ->assertHasNoErrors();

    $dina = AdminUser::query()->where('email', 'dina@rafeeq.test')->sole();

    expect($dina->status)->toBe(AdminStatus::Invited)
        ->and($dina->hasMfaConfirmed())->toBeFalse()
        ->and($dina->hasRole(AdminRole::Support->value))->toBeTrue()
        ->and($component->get('link'))->toStartWith(url('/admin/invitation/'))
        // Only the hash is stored.
        ->and(AdminInvitation::query()->where('token_hash', tokenFrom($component->get('link')))->exists())->toBeFalse()
        ->and(AdminAction::query()->where('action', 'admin.invite')->exists())->toBeTrue();
});

it('takes the invited person through password and authenticator, then signs them in', function () {
    $link = app(ManageTeamAction::class)->invite($this->boss, 'Dina Hassan', 'dina@rafeeq.test', AdminRole::Support)['link'];
    $dina = AdminUser::query()->where('email', 'dina@rafeeq.test')->sole();

    Livewire::test(AcceptInvitation::class, ['token' => tokenFrom($link)])
        ->assertSee(__('admin/team.invitation.welcome', ['name' => 'Dina']))
        ->set('password', 'a-long-new-password')
        ->set('password_confirmation', 'a-long-new-password')
        ->call('choosePassword')
        ->assertSet('step', 2)
        ->set('code', currentTotpCode($dina))
        ->call('finish')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.home'));

    $dina->refresh();

    expect($dina->status)->toBe(AdminStatus::Active)
        ->and($dina->hasMfaConfirmed())->toBeTrue()
        ->and(Hash::check('a-long-new-password', $dina->password_hash))->toBeTrue()
        ->and(Auth::guard('admin')->id())->toBe($dina->id)
        ->and(AdminInvitation::query()->sole()->accepted_at)->not->toBeNull();
});

it('saves nothing when the code is wrong', function () {
    $link = app(ManageTeamAction::class)->invite($this->boss, 'Dina', 'dina@rafeeq.test', AdminRole::Support)['link'];

    Livewire::test(AcceptInvitation::class, ['token' => tokenFrom($link)])
        ->set('password', 'a-long-new-password')
        ->set('password_confirmation', 'a-long-new-password')
        ->call('choosePassword')
        ->set('code', '000000')
        ->call('finish')
        ->assertHasErrors('code');

    expect(AdminUser::query()->where('email', 'dina@rafeeq.test')->sole()->status)->toBe(AdminStatus::Invited);
});

it('works once, and a newer link replaces the older one', function () {
    $action = app(ManageTeamAction::class);
    $first = $action->invite($this->boss, 'Dina', 'dina@rafeeq.test', AdminRole::Support)['link'];
    $dina = AdminUser::query()->where('email', 'dina@rafeeq.test')->sole();
    $second = $action->resetAccess($this->boss, $dina);

    expect(AdminInvitation::findUsable(tokenFrom($first)))->toBeNull()
        ->and(AdminInvitation::findUsable(tokenFrom($second)))->not->toBeNull();

    Livewire::test(AcceptInvitation::class, ['token' => tokenFrom($first)])->assertSee(__('admin/team.invitation.expired_title'));
});

it('refuses an expired link', function () {
    $invitation = AdminInvitation::factory()->create(['expires_at' => now()->subMinute()]);

    expect($invitation->isUsable())->toBeFalse();
});

it('resets access: the old password and authenticator stop working', function () {
    $nour = adminWithRole(AdminRole::Verification);
    $oldSecret = $nour->mfa_secret;

    app(ManageTeamAction::class)->resetAccess($this->boss, $nour);
    $nour->refresh();

    expect($nour->status)->toBe(AdminStatus::Invited)
        ->and($nour->mfa_secret)->not->toBe($oldSecret)
        ->and(Hash::check('password', $nour->password_hash))->toBeFalse();
});

it('changes a role, suspends and reactivates, each in the audit log', function () {
    $nour = adminWithRole(AdminRole::Verification);
    actingAsAdmin($this->boss);

    Livewire::test(Team::class)
        ->call('editRole', $nour->id)
        ->set('newRole', AdminRole::Operations->value)
        ->call('saveRole')
        ->call('suspend', $nour->id)
        ->call('reactivate', $nour->id)
        ->assertHasNoErrors();

    $nour->refresh();

    expect($nour->hasRole(AdminRole::Operations->value))->toBeTrue()
        ->and($nour->status)->toBe(AdminStatus::Active)
        ->and(AdminAction::query()->whereIn('action', ['admin.change_role', 'admin.suspend', 'admin.reactivate'])->count())->toBe(3);
});

it('never lets an Admin change their own role or state', function () {
    actingAsAdmin($this->boss);

    Livewire::test(Team::class)->call('suspend', $this->boss->id)->assertHasErrors('team');

    expect($this->boss->refresh()->status)->toBe(AdminStatus::Active);
});

it('keeps the last active Admin', function () {
    $other = adminWithRole(AdminRole::SuperAdmin);
    $other->forceFill(['status' => AdminStatus::Suspended])->save();
    // An Admin whose own account is suspended still holds the permission in this test, so
    // the only thing standing between them and suspending the boss is the last-Admin rule.
    $second = adminWithRole(AdminRole::SuperAdmin);
    $second->forceFill(['status' => AdminStatus::Suspended])->save();

    expect(fn () => app(ManageTeamAction::class)->suspend($second, $this->boss))->toThrow(ValidationException::class);
});

it('refuses an email already on the team', function () {
    actingAsAdmin($this->boss);

    Livewire::test(Team::class)
        ->set('name', 'Copy')
        ->set('email', $this->boss->email)
        ->set('role', AdminRole::Support->value)
        ->call('invite')
        ->assertHasErrors('email');
});

it('keeps the Team page from everyone but an Admin', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route('admin.team'))->assertForbidden();
});

it('has every team text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/team', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/team', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
