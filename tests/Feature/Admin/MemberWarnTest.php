<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Livewire\Admin\MemberProfile;
use Livewire\Livewire;

/**
 * D20 · warning a member: a staff message titled in her language, with the reason kept internal.
 */
it('sends a warning to the member inbox and keeps the reason in the audit log', function () {
    $member = User::factory()->create(['preferred_language' => 'ar']);
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(MemberProfile::class, ['user' => $member])
        ->call('startAction', 'warn')
        ->set('warningText', 'اتأخرت على الرحلات أكتر من مرة الشهر ده. لو اتكرر ممكن الحساب يتوقف.')
        ->set('reason', 'Four late arrivals this month')
        ->call('runAction')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/actions.done.warn'));

    $row = Notification::query()->where('user_id', $member->id)->sole();
    $audit = AdminAction::query()->where('action', 'member.warn')->sole();

    expect($row->title)->toBe(__('admin/actions.warning_title', [], 'ar'))
        ->and($row->body)->toContain('اتأخرت')
        ->and($row->body)->not->toContain('Four late arrivals')
        ->and($audit->reason)->toBe('Four late arrivals this month');
});

it('keeps warnings from staff who cannot suspend', function () {
    $member = User::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(MemberProfile::class, ['user' => $member])
        ->call('startAction', 'warn')
        ->set('warningText', 'You were late to many rides this month.')
        ->set('reason', 'Late arrivals')
        ->call('runAction')
        ->assertForbidden();
});
