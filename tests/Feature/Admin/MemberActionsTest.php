<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\MemberNote;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtStanding;
use App\Livewire\Admin\MemberProfile;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * User detail · More actions (D14, D16–D18, D22).
 */
beforeEach(function () {
    $this->member = User::factory()->create(['full_name' => 'Mariam Tarek', 'gender' => Gender::Woman]);
});

it('corrects details with a reason, and audits before and after', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('startAction', 'correct')
        ->set('fullName', 'Mariam Tarek Hassan')
        ->set('reason', 'Name on her ID card')
        ->call('runAction')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/actions.done.correct'));

    $audit = AdminAction::query()->where('action', 'member.correct')->sole();

    expect($this->member->refresh()->full_name)->toBe('Mariam Tarek Hassan')
        ->and($audit->old_value)->toBe(['full_name' => 'Mariam Tarek'])
        ->and($audit->reason)->toBe('Name on her ID card');
});

it('pauses women-only commutes when a woman’s gender is corrected away', function () {
    $offer = CommuteOffer::factory()->published()->create(['audience' => CommuteAudience::WomenOnly]);
    $driver = $offer->driverProfile->user;
    $driver->forceFill(['gender' => Gender::Woman])->save();

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(MemberProfile::class, ['user' => $driver])
        ->call('startAction', 'correct')
        ->set('gender', Gender::Man->value)
        ->set('reason', 'Gender was entered wrongly')
        ->call('runAction')
        ->assertHasNoErrors();

    expect($offer->refresh()->status)->toBe(CommuteOfferStatus::Paused);
});

it('writes off everything owed on one side', function () {
    DebtLedger::record($this->member->id, DebtSide::Driver, DebtEntryType::Commission, 5_000, 'seat_commission');
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('startAction', 'write_off')
        ->set('side', 'driver')
        ->set('reason', 'Agreed after the car accident')
        ->call('runAction')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/actions.done.write_off', ['amount' => '50.00']));

    expect(DebtStanding::of($this->member, DebtSide::Driver)->owedPiastres())->toBe(0)
        ->and(AdminAction::query()->where('action', 'debt.write_off')->exists())->toBeTrue();
});

it('refuses a write-off when nothing is owed', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('startAction', 'write_off')
        ->set('reason', 'Trying anyway')
        ->call('runAction')
        ->assertHasErrors('debt');
});

it('keeps money actions from a role without debt.manage', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('startAction', 'credit')
        ->set('amount', '20')
        ->set('reason', 'Should not work')
        ->call('runAction')
        ->assertForbidden();
});

it('unlocks a locked sign-in now', function () {
    OtpChallenge::factory()->create([
        'phone_e164' => $this->member->phone_e164,
        'status' => OtpStatus::Blocked,
        'locked_until' => now()->addMinutes(5),
    ]);
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->assertSee(__('admin/actions.locked_banner'))
        ->call('startAction', 'unlock')
        ->set('reason', 'Verified her on the phone')
        ->call('runAction')
        ->assertHasNoErrors();

    expect(OtpChallenge::query()->where('locked_until', '>', now())->exists())->toBeFalse();
});

it('escalates to an Admin as a support case, with the note kept', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('startAction', 'escalate')
        ->set('reason', 'Asks for a refund we cannot give from CS')
        ->call('runAction')
        ->assertHasNoErrors();

    expect(SupportTicket::query()->sole())->category->toBe('escalation')
        ->and(MemberNote::query()->sole()->body)->toContain('refund');
});

it('asks for a reason before every action', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('startAction', 'correct')
        ->set('fullName', 'Someone Else')
        ->set('reason', '')
        ->call('runAction')
        ->assertHasErrors('reason');

    expect($this->member->refresh()->full_name)->toBe('Mariam Tarek');
});

it('has every action text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/actions', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/actions', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
