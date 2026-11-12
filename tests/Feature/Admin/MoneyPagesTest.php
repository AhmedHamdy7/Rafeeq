<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\Coupon;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\SettlementWeek;
use App\Livewire\Admin\Coupons;
use App\Livewire\Admin\Ledger;
use App\Livewire\Admin\Settlements;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Dashboard phase 6: Weekly settlements (W), Ledger (G), Coupons (K). All read-only.
 */
it('adds up a driver’s week from the ledger', function () {
    $line = DebtEntry::factory()->create(['amount_piastres' => 2_500]);
    DebtEntry::factory()->create(['user_id' => $line->user_id, 'amount_piastres' => 1_500]);
    DebtEntry::factory()->create(['user_id' => $line->user_id, 'type' => DebtEntryType::Payment, 'amount_piastres' => -3_000, 'reason_code' => 'instapay']);
    // Another week: not counted.
    DebtEntry::factory()->create(['user_id' => $line->user_id, 'week_of' => SettlementWeek::shift(SettlementWeek::current(), -1)]);
    // Passenger side: not a settlement.
    DebtEntry::factory()->create(['side' => DebtSide::Passenger]);

    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Settlements::class)
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 1
            && $rows->first()['commission'] === 4_000
            && $rows->first()['paid'] === 3_000);
});

it('moves between weeks but never past this one', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Settlements::class)
        ->assertSet('week', SettlementWeek::current())
        ->call('next')
        ->assertSet('week', SettlementWeek::current())
        ->call('previous')
        ->assertSet('week', SettlementWeek::shift(SettlementWeek::current(), -1));
});

it('turns any date in the address into the Saturday that starts its week', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.settlements', ['week' => 'not-a-date']))->assertOk();

    Livewire::withQueryParams(['week' => SettlementWeek::shift(SettlementWeek::current(), -2)])
        ->test(Settlements::class)
        ->assertSet('week', SettlementWeek::shift(SettlementWeek::current(), -2));
});

it('filters the ledger by side and type', function () {
    DebtEntry::factory()->create();
    DebtEntry::factory()->create(['side' => DebtSide::Passenger, 'type' => DebtEntryType::Fee, 'reason_code' => 'passenger_no_show']);

    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Ledger::class)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 2)
        ->set('side', 'passenger')
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 1)
        ->set('side', '')
        ->set('type', 'commission')
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 1);
});

it('lists the coupons issued', function () {
    Coupon::factory()->create();

    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Coupons::class)->assertViewHas('coupons', fn ($coupons) => $coupons->total() === 1);
});

it('keeps the money pages from staff without payments', function (string $route) {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    $this->get(route($route))->assertForbidden();
})->with(['admin.settlements', 'admin.ledger', 'admin.coupons']);

it('has every money text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/money', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/money', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
