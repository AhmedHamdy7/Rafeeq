<?php

use App\Domains\Admin\Enums\AdminRole;
use Illuminate\Support\Arr;

/**
 * Dashboard phase 2: the SOS page (O01–O04) and Live trips (L01–L03) in the new design.
 * Their behaviour is tested in SafetyCasesTest and LiveTripsTest; these check the frame.
 */
it('keeps the emergency numbers and the never-end-the-ride rule on the SOS page', function () {
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $this->get(route('admin.safety'))
        ->assertOk()
        ->assertSee(__('admin/safety.never_end_ride'))
        ->assertSee('tel:122', false)
        ->assertSee('tel:123', false)
        ->assertSee('tel:180', false);
});

it('draws Live trips as a table with its filters as chips', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route('admin.trips'))
        ->assertOk()
        ->assertSee('ad-fchip', false)
        ->assertSee(__('admin.trips.flagged_only'));
});

it('has every SOS and Live trips text in both languages', function () {
    foreach (['admin/safety', 'admin/trips'] as $file) {
        $en = array_keys(Arr::dot(trans($file, [], 'en')));
        $ar = array_keys(Arr::dot(trans($file, [], 'ar')));

        expect($ar)->toEqualCanonicalizing($en);
    }
});
