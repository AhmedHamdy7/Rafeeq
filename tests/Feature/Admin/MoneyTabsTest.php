<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtLedger;
use App\Livewire\Admin\Coupons;
use App\Livewire\Admin\Payments;
use App\Livewire\Admin\Settlements;
use Livewire\Livewire;

/**
 * Money: payments history (I04), passengers' settlements (W02) and credits (K04).
 */
it('lists decided payments in the history, not in the queue', function () {
    $confirmed = DebtPayment::factory()->create(['status' => DebtPaymentStatus::Confirmed, 'decided_at' => now()]);
    DebtPayment::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Payments::class)
        ->assertViewHas('pending', fn ($page) => $page->total() === 1)
        ->set('view', 'history')
        ->assertViewHas('history', fn ($page) => $page->total() === 1 && $page->first()->is($confirmed));
});

it('shows passengers on their own settlement tab', function () {
    $passenger = User::factory()->create();
    DebtLedger::record($passenger->id, DebtSide::Passenger, DebtEntryType::Fee, 2_500, 'passenger_no_show');
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Settlements::class)
        ->assertViewHas('rows', fn ($rows) => $rows->isEmpty())
        ->call('pickSide', 'passenger')
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()['commission'] === 2_500);
});

it('lists credits added by hand next to the coupons', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Coupons::class)
        ->assertViewHas('credits', null)
        ->set('view', 'credits')
        ->assertViewHas('credits', fn ($page) => $page->total() === 0)
        ->assertSee(__('admin/money.coupons.no_credits'));
});
