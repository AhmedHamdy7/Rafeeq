<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Models\Vehicle;
use App\Livewire\Admin\Directory\Bookings;
use App\Livewire\Admin\Directory\Commutes;
use App\Livewire\Admin\Directory\Demands;
use App\Livewire\Admin\Directory\TripDays;
use App\Livewire\Admin\Directory\Vehicles;
use Livewire\Livewire;

/**
 * Tables · saved views, the column picker and CSV export on the directory pages.
 */
it('saves a view for this staff member and applies it later', function () {
    $admin = adminWithRole(AdminRole::Operations);
    actingAsAdmin($admin);

    Livewire::test(Vehicles::class)
        ->call('pick', 'pending')
        ->set('search', 'ABC')
        ->set('viewName', 'Pending ABC')
        ->call('saveView')
        ->assertHasNoErrors();

    expect($admin->refresh()->table_views['vehicles']['Pending ABC'])->toEqualCanonicalizing(['status' => 'pending', 'q' => 'ABC']);

    Livewire::test(Vehicles::class)
        ->call('applyView', 'Pending ABC')
        ->assertSet('status', 'pending')
        ->assertSet('search', 'ABC')
        ->call('deleteView', 'Pending ABC');

    expect($admin->refresh()->table_views['vehicles'] ?? [])->toBe([]);
});

it('exports the filtered view as CSV for an Admin, and writes it to the audit log', function () {
    CommuteOffer::factory()->published()->create();
    CommuteOffer::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Commutes::class)
        ->call('pick', 'published')
        ->call('export')
        ->assertFileDownloaded();

    $audit = AdminAction::query()->where('action', 'table.export')->sole();

    expect($audit->new_value['table'])->toBe('commutes')
        ->and($audit->new_value['rows'])->toBe(1)
        ->and($audit->new_value['filters'])->toBe(['status' => 'published']);
});

it('exports every directory page', function (string $component) {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test($component)->call('export')->assertFileDownloaded();
})->with([Commutes::class, TripDays::class, Bookings::class, Demands::class, Vehicles::class]);

it('keeps export to Admins', function () {
    Vehicle::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(Vehicles::class)
        ->assertDontSee(__('admin/tables.export_csv'))
        ->call('export')
        ->assertForbidden();
});

it('has the table strings in both languages', function () {
    expect(array_keys(trans('admin/tables', [], 'ar')))->toBe(array_keys(trans('admin/tables', [], 'en')));
});
