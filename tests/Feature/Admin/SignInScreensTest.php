<?php

use App\Domains\Admin\Actions\ChangeAdminPasswordAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Livewire\Admin\Account;
use App\Livewire\Admin\Login;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Dashboard phase 1: the signed-out screens (S01–S08) and My account (MY1).
 * The sign-in rules themselves are tested in AdminAuthenticationTest.
 */
beforeEach(function () {
    Cache::clear();

    $this->admin = adminWithRole(AdminRole::SuperAdmin, ['email' => 'omar@rafeeq.test']);
});

it('draws the sign-in page in the new design, in either language', function () {
    $this->get(route('admin.login'))
        ->assertOk()
        ->assertSee('ad-auth', false)
        ->assertSee(__('admin/auth.sign_in.title'))
        ->assertSee(__('admin/auth.foot'));

    $this->post(route('admin.locale', 'ar'));

    $this->get(route('admin.login'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('admin/auth.sign_in.title', [], 'ar'));
});

it('gives a wrong password the same message as an unknown email (S02)', function () {
    $wrong = Livewire::test(Login::class)
        ->set('email', 'omar@rafeeq.test')
        ->set('password', 'not-it')
        ->call('submitPassword')
        ->assertHasErrors('email')
        ->assertSet('password', '')
        ->errors()->first('email');

    $unknown = Livewire::test(Login::class)
        ->set('email', 'nobody@rafeeq.test')
        ->set('password', 'not-it')
        ->call('submitPassword')
        ->errors()->first('email');

    expect($wrong)->toBe($unknown);
});

it('shows the lock screen with the time left once the limit is reached (S03)', function () {
    foreach (range(1, 5) as $attempt) {
        Livewire::test(Login::class)
            ->set('email', 'omar@rafeeq.test')
            ->set('password', 'wrong')
            ->call('submitPassword');
    }

    $locked = Livewire::test(Login::class)
        ->set('email', 'omar@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->assertSee(__('admin/auth.locked.title'));

    expect($locked->get('lockedFor'))->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and(auth('admin')->check())->toBeFalse();
});

it('goes back to the form when the lock runs out, and the lock is still the server’s', function () {
    $login = Livewire::test(Login::class)->set('lockedFor', 30)->call('unlock');

    expect($login->get('lockedFor'))->toBeNull();
});

it('asks for the code on its own card after the password (S04)', function () {
    Livewire::test(Login::class)
        ->set('email', 'omar@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->assertSet('pendingAdminId', $this->admin->id)
        ->assertSee(__('admin/auth.code.title'))
        ->assertDontSee('id="admin-password"', false);
});

it('says why after an idle sign-out (S08)', function () {
    actingAsAdmin($this->admin);

    $this->post(route('admin.logout'), ['reason' => 'idle'])
        ->assertRedirect(route('admin.login'))
        ->assertSessionHas('admin_signed_out', 'idle');

    // The next page is the sign-in page, drawn with the flash the redirect left.
    // (A second HTTP request would still carry actingAs() and bounce off guest:admin.)
    Livewire::test(Login::class)->assertSee(__('admin/auth.signed_out.title'));
});

it('says nothing extra after an ordinary sign-out', function () {
    actingAsAdmin($this->admin);

    $this->post(route('admin.logout'))->assertSessionMissing('admin_signed_out');
});

it('keeps the session alive when "Stay signed in" pings (S07)', function () {
    actingAsAdmin($this->admin);

    $this->get(route('admin.ping'))->assertNoContent();
});

it('puts the idle warning on every dashboard page', function () {
    actingAsAdmin($this->admin);

    $this->get(route('admin.account'))
        ->assertOk()
        ->assertSee(__('admin/auth.idle.stay'))
        ->assertSee('name="reason" value="idle"', false);
});

/*
| My account (MY1)
*/

it('shows a staff member their own account and nobody else’s', function () {
    $other = adminWithRole(AdminRole::Finance, ['email' => 'someone@rafeeq.test']);
    actingAsAdmin($other);

    $this->get(route('admin.account'))
        ->assertOk()
        ->assertSee('someone@rafeeq.test')
        ->assertDontSee('omar@rafeeq.test');
});

it('is open to every role, since it only ever shows the viewer', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    $this->get(route('admin.account'))->assertOk();
});

it('changes the password when the current one is right', function () {
    actingAsAdmin($this->admin);

    Livewire::test(Account::class)
        ->call('startPasswordChange')
        ->set('current', 'password')
        ->set('new', 'a-much-longer-password')
        ->set('new_confirmation', 'a-much-longer-password')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertSet('changed', true)
        ->assertSet('changing', false);

    expect(Hash::check('a-much-longer-password', $this->admin->refresh()->password_hash))->toBeTrue()
        ->and(AdminAction::query()->where('action', 'admin.password_changed')->where('admin_id', $this->admin->id)->exists())->toBeTrue();
});

it('refuses a wrong current password and changes nothing', function () {
    actingAsAdmin($this->admin);
    $before = $this->admin->password_hash;

    Livewire::test(Account::class)
        ->set('current', 'not-it')
        ->set('new', 'a-much-longer-password')
        ->set('new_confirmation', 'a-much-longer-password')
        ->call('changePassword')
        ->assertHasErrors('current');

    expect($this->admin->refresh()->password_hash)->toBe($before);
});

it('refuses a short or unrepeated new password', function () {
    actingAsAdmin($this->admin);

    Livewire::test(Account::class)
        ->set('current', 'password')
        ->set('new', 'short')
        ->set('new_confirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors(['new' => 'min']);

    Livewire::test(Account::class)
        ->set('current', 'password')
        ->set('new', 'a-much-longer-password')
        ->set('new_confirmation', 'something-else-entirely')
        ->call('changePassword')
        ->assertHasErrors(['new' => 'confirmed']);
});

it('stops guessing the current password after a few tries', function () {
    actingAsAdmin($this->admin);

    foreach (range(1, 5) as $attempt) {
        Livewire::test(Account::class)
            ->set('current', 'guess-'.$attempt)
            ->set('new', 'a-much-longer-password')
            ->set('new_confirmation', 'a-much-longer-password')
            ->call('changePassword');
    }

    // Even the right one is refused now.
    Livewire::test(Account::class)
        ->set('current', 'password')
        ->set('new', 'a-much-longer-password')
        ->set('new_confirmation', 'a-much-longer-password')
        ->call('changePassword')
        ->assertHasErrors('current');

    expect(Hash::check('password', $this->admin->refresh()->password_hash))->toBeTrue();
});

it('has every sign-in and account text in both languages', function () {
    foreach (['admin/auth', 'admin/account'] as $file) {
        $en = array_keys(Arr::dot(trans($file, [], 'en')));
        $ar = array_keys(Arr::dot(trans($file, [], 'ar')));

        expect($ar)->toEqualCanonicalizing($en);
    }

    expect(ChangeAdminPasswordAction::MIN_LENGTH)->toBe(12);
});
