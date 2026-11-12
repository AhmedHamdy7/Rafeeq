<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Models\CommuteDemand;
use App\Livewire\Admin\Directory\Bookings;
use App\Livewire\Admin\Directory\Commutes;
use App\Livewire\Admin\Directory\Demands;
use App\Livewire\Admin\Directory\TripDays;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Dashboard phase 7: the directory — Commutes (C), Trip days (Y), Bookings (B), Demands (Q).
 * Read-only lists with status tabs.
 */
it('opens every directory page for operations staff', function (string $route) {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route($route))->assertOk();
})->with(['admin.commutes', 'admin.trip-days', 'admin.bookings', 'admin.demands']);

it('keeps the directory from staff without the permission', function (string $route) {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route($route))->assertForbidden();
})->with(['admin.commutes', 'admin.trip-days', 'admin.bookings', 'admin.demands']);

it('filters commutes by status and ignores a status that does not exist', function () {
    CommuteOffer::factory()->published()->create();
    CommuteOffer::factory()->create();

    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(Commutes::class)
        ->assertViewHas('commutes', fn ($page) => $page->total() === 2)
        ->call('pick', 'published')
        ->assertViewHas('commutes', fn ($page) => $page->total() === 1)
        ->call('pick', 'nonsense')
        ->assertSet('status', 'all');
});

it('shows one day of runs, today by default', function () {
    $today = now('Africa/Cairo')->toDateString();
    ScheduledTrip::factory()->create(['trip_date' => $today]);
    ScheduledTrip::factory()->create(['trip_date' => $today, 'status' => ScheduledTripStatus::Cancelled]);
    ScheduledTrip::factory()->create(); // tomorrow

    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(TripDays::class)
        ->assertViewHas('trips', fn ($page) => $page->total() === 2)
        ->call('pick', 'cancelled')
        ->assertViewHas('trips', fn ($page) => $page->total() === 1)
        ->call('pick', 'all')
        ->set('date', now('Africa/Cairo')->addDay()->toDateString())
        ->assertViewHas('trips', fn ($page) => $page->total() === 1);
});

it('lists bookings and demands', function () {
    Booking::factory()->create();
    CommuteDemand::factory()->create();

    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(Bookings::class)->assertViewHas('bookings', fn ($page) => $page->total() === 1);
    Livewire::test(Demands::class)->assertViewHas('demands', fn ($page) => $page->total() === 1);
});

it('has every directory text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/directory', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/directory', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
