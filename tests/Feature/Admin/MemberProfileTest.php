<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\MemberNote;
use App\Domains\Identity\Models\User;
use App\Livewire\Admin\MemberProfile;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * User detail (D01–D10, D15): the member on a page of their own.
 */
beforeEach(function () {
    $this->member = User::factory()->create(['full_name' => 'Karim Fawzy']);
});

it('opens every tab for an Admin', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    foreach (MemberProfile::TABS as $tab) {
        $this->get(route('admin.members.show', [$this->member, 'tab' => $tab]))
            ->assertOk()
            ->assertSee('Karim Fawzy');
    }
});

it('keeps the money tab from a role without payments', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->call('pick', 'money')
        ->assertSet('tab', 'overview');
});

it('keeps the page from a role that may not read members', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.members.show', $this->member))->assertForbidden();
});

it('adds a team note, internal and kept', function () {
    $agent = adminWithRole(AdminRole::Support);
    actingAsAdmin($agent);

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->set('note', 'Called about the AC; told him within 24h.')
        ->call('addNote')
        ->assertHasNoErrors()
        ->assertSet('note', '');

    expect(MemberNote::query()->sole())
        ->user_id->toBe($this->member->id)
        ->admin_id->toBe($agent->id);
});

it('shows the full phone number only after a reason, and audits the reason', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    $page = Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->assertDontSee($this->member->phone_e164)
        ->set('revealing', true)
        ->set('revealReason', '')
        ->call('reveal')
        ->assertHasErrors('revealReason');

    $page->set('revealReason', 'Calling him back about his report')
        ->call('reveal')
        ->assertHasNoErrors()
        ->assertSee($this->member->phone_e164);

    expect(AdminAction::query()->where('action', 'member.reveal_phone')->sole()->reason)->toBe('Calling him back about his report');
});

it('has every profile text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/profile', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/profile', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
