<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\Statistics as Figures;
use App\Domains\Admin\Support\StatisticsPeriod;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\SettlementWeek;
use App\Livewire\Admin\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Dashboard phase 9: Statistics (T01–T05) — revenue, trips and seats, growth, definitions.
 */
it('opens every tab for finance and refuses staff without payments', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    foreach (Statistics::TABS as $tab) {
        $this->get(route('admin.statistics', ['tab' => $tab]))->assertOk();
    }

    actingAsAdmin(adminWithRole(AdminRole::Support));
    $this->get(route('admin.statistics'))->assertForbidden();
});

it('falls back to this week and revenue on a period or tab that does not exist', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::withQueryParams(['period' => 'forever', 'tab' => 'secrets'])
        ->test(Statistics::class)
        ->assertSet('period', 'this_week')
        ->assertSet('tab', 'revenue');
});

it('splits the settlement week from Saturday, and compares with the week before', function () {
    $wednesday = CarbonImmutable::parse('2026-11-11 10:00', 'Africa/Cairo');
    $week = StatisticsPeriod::of('this_week', $wednesday);

    expect($week->firstDay())->toBe('2026-11-07')
        ->and($week->lastDay())->toBe('2026-11-13')
        ->and($week->previous()->firstDay())->toBe('2026-10-31')
        ->and(StatisticsPeriod::of('last_month', $wednesday)->firstDay())->toBe('2026-10-01')
        ->and(StatisticsPeriod::of('last_month', $wednesday)->previous()->firstDay())->toBe('2026-09-01');
});

it('reads revenue from the ledger, inside the period only', function () {
    DebtEntry::factory()->create(['amount_piastres' => 2_000]);
    DebtEntry::factory()->create(['type' => DebtEntryType::Payment, 'amount_piastres' => -1_500, 'reason_code' => 'instapay']);
    // The ledger is append-only: an old line is created old, never edited.
    DebtEntry::factory()->create(['amount_piastres' => 9_999, 'created_at' => now()->subWeeks(3)]);

    $revenue = Figures::revenue(StatisticsPeriod::of('this_week'));

    expect($revenue['commission'])->toBe(2_000)
        ->and($revenue['received'])->toBe(1_500);
});

it('puts every settlement week in the weekly chart, empty ones as zero', function () {
    DebtEntry::factory()->create(['amount_piastres' => 3_000, 'week_of' => SettlementWeek::current()]);

    $weeks = Figures::weeklyMoney(6);

    expect($weeks)->toHaveCount(6)
        ->and(end($weeks)['earned'])->toBe(3_000)
        ->and($weeks[0]['earned'])->toBe(0);
});

it('has every statistics text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/stats', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/stats', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en)
        ->and(array_keys(trans('admin/stats.definitions', [], 'en')))->toEqual(array_keys(trans('admin/stats.terms', [], 'en')));
});
