<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\AdminMaps;
use App\Domains\Commute\Models\CommuteOffer;

/**
 * The dashboard's maps: nothing reaches Google until they are switched on with a browser key.
 */
it('draws no map and loads no script while the maps are off', function () {
    config(['rafeeq.admin_maps_enabled' => false, 'services.google_maps.browser_key' => 'test-browser-key']);
    $commute = CommuteOffer::factory()->published()->create();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route('admin.commutes.show', $commute->id))
        ->assertOk()
        ->assertDontSee('maps.googleapis.com', false)
        ->assertDontSee('class="ad-map', false);
});

it('stays off when switched on without a key', function () {
    config(['rafeeq.admin_maps_enabled' => true, 'services.google_maps.browser_key' => '']);

    expect(AdminMaps::enabled())->toBeFalse();
});

it('draws the commute route once the maps are on', function () {
    config(['rafeeq.admin_maps_enabled' => true, 'services.google_maps.browser_key' => 'test-browser-key']);
    $commute = CommuteOffer::factory()->published()->create();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route('admin.commutes.show', $commute->id))
        ->assertOk()
        ->assertSee('maps.googleapis.com', false)
        ->assertSee('class="ad-map', false);
});

it('labels a commute’s stops A, 1…n, B', function () {
    $stops = [
        (object) ['type' => 'origin', 'lat' => 30.1, 'lng' => 31.3, 'address' => 'Nasr City'],
        (object) ['type' => 'pickup', 'lat' => 30.05, 'lng' => 31.2, 'address' => 'Abbas'],
        (object) ['type' => 'destination', 'lat' => 30.07, 'lng' => 31.0, 'address' => 'Smart Village'],
        (object) ['type' => 'pickup', 'lat' => null, 'lng' => null, 'address' => 'unknown'],
    ];

    expect(array_column(AdminMaps::stops($stops), 'label'))->toBe(['A', '1', 'B']);
});
