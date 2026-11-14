<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use App\Livewire\Admin\GlobalSearch;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The top-bar search: people, plates, reference codes and pages, each only for staff who may open them.
 */
function searchGroups(string $q): array
{
    $groups = null;

    Livewire::test(GlobalSearch::class)->set('q', $q)->assertViewHas('groups', function ($value) use (&$groups) {
        $groups = collect($value)->mapWithKeys(fn ($group) => [$group['group'] => collect($group['items'])->pluck('label')->all()])->all();

        return true;
    });

    return $groups;
}

it('finds a member by name and links to the profile, with the number masked', function () {
    $member = User::factory()->create(['full_name' => 'Mariam Tarek']);
    actingAsAdmin(adminWithRole(AdminRole::Support));

    expect(searchGroups('Mariam')['members'] ?? [])->toBe(['Mariam Tarek']);

    Livewire::test(GlobalSearch::class)->set('q', 'Mariam')
        ->assertSee(route('admin.members.show', $member->id), false)
        ->assertDontSee($member->phone_e164);
});

it('finds a car by its plate and a booking by its reference code', function () {
    $commute = CommuteOffer::factory()->published()->create();
    $booking = Booking::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    expect(searchGroups((string) $commute->vehicle->plate_number)['vehicles'] ?? [])->toContain($commute->vehicle->plate_number)
        ->and(searchGroups(Str::lower(Str::substr($booking->id, -6)))['references'] ?? [])->toContain(Str::upper(Str::substr($booking->id, -6)));
});

it('jumps to a page by its name', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    expect(searchGroups('settle')['pages'] ?? [])->toContain(__('admin/shell.nav.settlements'));
});

it('shows nothing to staff who may not open it', function () {
    User::factory()->create(['full_name' => 'Mariam Tarek']);
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    expect(searchGroups('Mariam'))->not->toHaveKey('members');
});

it('draws the search box in the top bar', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.dashboard'))->assertOk()->assertSee(__('admin/shell.search.placeholder'));
});
