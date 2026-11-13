<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Safety\Enums\SosResolution;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;
use App\Livewire\Admin\SafetyCases;
use App\Livewire\Admin\SosDetail;
use Livewire\Livewire;

/**
 * SOS detail (O02–O04) and the history under the live alerts (O01).
 */
function openSos(): SosEvent
{
    return SosEvent::factory()->create(['safety_event_id' => SafetyEvent::factory()->create()->id]);
}

it('opens an alert with its timeline and the emergency numbers', function () {
    $sos = openSos();
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $this->get(route('admin.safety.sos', $sos))
        ->assertOk()
        ->assertSee(__('admin/safety.events.raised'))
        ->assertSee(__('admin/safety.events.waiting'))
        ->assertSee('tel:122', false);
});

it('acknowledges from the detail page, as the desk does', function () {
    $sos = openSos();
    $lead = adminWithRole(AdminRole::SafetyLead);
    actingAsAdmin($lead);

    Livewire::test(SosDetail::class, ['sos' => $sos])->call('acknowledge', $sos->id)->assertHasNoErrors();

    expect($sos->refresh()->first_touch_at)->not->toBeNull()
        ->and($sos->responder_admin_id)->toBe($lead->id);
});

it('lists closed alerts under History, newest first', function () {
    $closed = openSos();
    $closed->forceFill(['resolution' => SosResolution::cases()[0], 'first_touch_at' => now()])->save();

    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    Livewire::test(SafetyCases::class)->assertViewHas('history', fn ($history) => $history->count() === 1 && $history->first()->is($closed));
});

it('keeps the detail page from staff without the safety desk', function () {
    $sos = openSos();
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.safety.sos', $sos))->assertForbidden();
});
