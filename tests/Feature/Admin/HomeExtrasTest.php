<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\HomeWatch;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Livewire\Admin\Dashboard;
use Livewire\Livewire;

/**
 * Home · Customise, the watch list and the money snapshot (H01, H03, H04).
 */
it('shows the watch list and the money snapshot to an Admin', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(__('admin/home.watch.passengers_near'))
        ->assertSee(__('admin/home.money.commission'));
});

it('keeps money off the Home of staff without payments', function () {
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    Livewire::test(Dashboard::class)
        ->assertViewHas('money', null)
        ->assertViewHas('watchDebts', null)
        ->assertDontSee(__('admin/home.money.commission'));
});

it('saves each staff member’s own cards, and all of them means everything', function () {
    $admin = adminWithRole(AdminRole::SuperAdmin);
    actingAsAdmin($admin);

    Livewire::test(Dashboard::class)
        ->call('saveCards', ['queues', 'nonsense', 'live'])
        ->assertViewHas('cards', ['live', 'queues'])
        ->assertViewHas('money', null)
        ->assertDontSee(__('admin.dashboard.full_log'));

    expect($admin->refresh()->home_cards)->toBe(['live', 'queues']);

    Livewire::test(Dashboard::class)->call('saveCards', Dashboard::CARDS);
    expect($admin->refresh()->home_cards)->toBeNull();
});

it('counts suspended accounts’ debt and women-only commutes waiting on review', function () {
    DebtAccount::factory()->create(['side' => DebtSide::Driver, 'balance_piastres' => 12_000, 'suspended_at' => now()]);
    DebtAccount::factory()->create(['side' => DebtSide::Passenger, 'balance_piastres' => 3_000]);
    CommuteOffer::factory()->create(['status' => CommuteOfferStatus::Paused, 'paused_reason' => CommutePausedReason::GenderReview]);

    expect(HomeWatch::money()['owedNow'])->toBe(15_000)
        ->and(HomeWatch::money()['suspendedOwe'])->toBe(12_000)
        ->and(HomeWatch::debts()['driversBlocked'])->toBe(1)
        ->and(HomeWatch::genderReviewCommutes())->toBe(1);
});

it('has the new Home strings in both languages', function () {
    foreach (['cards', 'watch', 'money'] as $group) {
        expect(array_keys(trans("admin/home.$group", [], 'ar')))->toBe(array_keys(trans("admin/home.$group", [], 'en')));
    }
});
