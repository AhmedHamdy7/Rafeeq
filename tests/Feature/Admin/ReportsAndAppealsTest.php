<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Safety\Models\Incident;
use App\Livewire\Admin\Appeals;
use App\Livewire\Admin\Reports;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Dashboard phase 5: Reports (R) on a page of their own, and Appeals (P) in one list.
 */
it('lists the open reports on their own page', function () {
    Incident::factory()->create();

    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $this->get(route('admin.reports'))
        ->assertOk()
        ->assertSee(__('admin/reports.title'));

    Livewire::test(Reports::class)
        ->assertViewHas('reports', fn ($reports) => $reports->total() === 1);
});

it('decides a report from the Reports page exactly as from the safety desk', function () {
    $report = Incident::factory()->create();
    $lead = adminWithRole(AdminRole::SafetyLead);
    actingAsAdmin($lead);

    Livewire::test(Reports::class)->call('take', $report->id)->assertHasNoErrors();

    expect($report->refresh()->assigned_admin_id)->toBe($lead->id);
});

it('keeps the Reports page from staff without the safety desk', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.reports'))->assertForbidden();
});

it('lists every appeal waiting, each kind only for staff who may decide it', function () {
    FeeAppeal::factory()->create();
    SuspensionAppeal::factory()->create();

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Appeals::class)
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 2)
        ->call('pick', 'suspension')
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()['kind'] === 'suspension');

    // Support reads members, not money: the driver's fee appeal is not theirs to see.
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Appeals::class)
        ->assertViewHas('rows', fn ($rows) => $rows->pluck('kind')->all() === ['suspension'])
        ->call('pick', 'fee')
        ->assertSet('kind', 'all');
});

it('has every Reports and Appeals text in both languages', function () {
    foreach (['admin/reports', 'admin/appeals'] as $file) {
        $en = array_keys(Arr::dot(trans($file, [], 'en')));
        $ar = array_keys(Arr::dot(trans($file, [], 'ar')));

        expect($ar)->toEqualCanonicalizing($en);
    }
});
