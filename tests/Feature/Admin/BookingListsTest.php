<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Booking\Models\SeatRequest;
use App\Livewire\Admin\Directory\ChangeProposals;
use App\Livewire\Admin\Directory\SeatOffers;
use App\Livewire\Admin\Directory\SeatRequests;
use Livewire\Livewire;

/**
 * Bookings and requests: seat requests (B02), waiting lists and seat offers (B05) and change
 * proposals (B06), next to the bookings list.
 */
it('opens the three lists for operations staff, with the section links', function (string $route) {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route($route))
        ->assertOk()
        ->assertSee(route('admin.bookings'), false)
        ->assertSee(__('admin/directory.booking_nav.change_proposals'));
})->with(['admin.seat-requests', 'admin.seat-offers', 'admin.change-proposals']);

it('keeps them from staff without the commute desk', function (string $route) {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route($route))->assertForbidden();
})->with(['admin.seat-requests', 'admin.seat-offers', 'admin.change-proposals']);

it('lists seat requests by status', function () {
    $request = SeatRequest::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(SeatRequests::class)
        ->assertViewHas('requests', fn ($page) => $page->total() === 1)
        ->call('pick', $request->status->value === 'pending' ? 'approved' : 'pending')
        ->assertViewHas('requests', fn ($page) => $page->total() === 0);
});

it('exports every new list for an Admin', function (string $component) {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test($component)->call('export')->assertFileDownloaded();
})->with([SeatRequests::class, SeatOffers::class, ChangeProposals::class]);

it('has the new list strings in both languages', function () {
    foreach (['statuses', 'booking_nav', 'scopes', 'commitments'] as $group) {
        expect(array_keys(trans("admin/directory.$group", [], 'ar')))->toBe(array_keys(trans("admin/directory.$group", [], 'en')));
    }
});
