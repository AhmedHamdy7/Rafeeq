<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Livewire\Admin\Reports;
use Livewire\Livewire;

/**
 * Reports · the closed history (R06), searchable by a person's name.
 */
it('lists closed reports and finds one by the reporter’s name', function () {
    $reporter = User::factory()->create(['full_name' => 'Salma Khaled']);
    $closed = Incident::factory()->create(['reporter_user_id' => $reporter->id]);
    $closed->forceFill(['status' => IncidentStatus::Resolved->value, 'resolved_at' => now(), 'resolution' => 'Warned the driver'])->save();
    $other = Incident::factory()->create();
    $other->forceFill(['status' => IncidentStatus::Closed->value, 'resolved_at' => now()])->save();
    Incident::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    Livewire::test(Reports::class)
        ->set('view', 'closed')
        ->assertViewHas('closed', fn ($page) => $page->total() === 2)
        ->set('search', 'Salma')
        ->assertViewHas('closed', fn ($page) => $page->total() === 1 && $page->first()->is($closed));
});

it('has the history strings in both languages', function () {
    expect(array_keys(trans('admin/reports', [], 'ar')))->toEqualCanonicalizing(array_keys(trans('admin/reports', [], 'en')));
});
