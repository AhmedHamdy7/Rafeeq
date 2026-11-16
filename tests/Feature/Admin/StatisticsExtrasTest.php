<?php

use App\Domains\Admin\Actions\UpdatePlatformSettingAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\Statistics as Figures;
use App\Domains\Admin\Support\StatisticsFilter;
use App\Domains\Admin\Support\StatisticsInsights;
use App\Domains\Admin\Support\StatisticsPeriod;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Livewire\Admin\Statistics;
use Livewire\Livewire;

/**
 * Statistics: a custom period, the women-only filter, fees by reason and the money settings that changed.
 */
it('builds a custom period, and refuses one that runs backwards or is too long', function () {
    $period = StatisticsPeriod::custom('2026-11-01', '2026-11-10');

    expect($period->firstDay())->toBe('2026-11-01')
        ->and($period->lastDay())->toBe('2026-11-10')
        ->and($period->previous()->lastDay())->toBe('2026-10-31')
        ->and(StatisticsPeriod::custom('2026-11-10', '2026-11-01'))->toBeNull()
        ->and(StatisticsPeriod::custom('2024-01-01', '2026-01-01'))->toBeNull()
        ->and(StatisticsPeriod::custom('not a date', '2026-01-01'))->toBeNull();
});

it('narrows supply to women-only commutes', function () {
    CommuteOffer::factory()->published()->create(['audience' => CommuteAudience::WomenOnly]);
    CommuteOffer::factory()->published()->create(['audience' => CommuteAudience::AnyVerified]);

    $period = StatisticsPeriod::of('this_week');

    expect(StatisticsInsights::supply($period, StatisticsFilter::of('', '', true))['offers']['published'])->toBe(1);
});

it('groups fees by what they were for', function () {
    $passenger = User::factory()->create();
    DebtLedger::record($passenger->id, DebtSide::Passenger, DebtEntryType::Fee, 2_750, 'passenger_no_show');
    DebtLedger::record($passenger->id, DebtSide::Passenger, DebtEntryType::Fee, 1_000, 'passenger_no_show');

    expect(Figures::feesByReason(StatisticsPeriod::of('this_week')))->toBe(['passenger_no_show' => 3_750]);
});

it('lists the money settings changed over the chart weeks', function () {
    $admin = adminWithRole(AdminRole::SuperAdmin);
    app(UpdatePlatformSettingAction::class)->update('fees.no_show_step_2_percent', 35, $admin, 'Testing a higher first fee');

    $changes = Figures::moneySettingChanges();

    expect($changes)->toHaveCount(1)
        ->and($changes[0]['key'])->toBe('fees.no_show_step_2_percent')
        ->and($changes[0]['to'])->toBe(35);
});

it('opens a custom period on the page', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Statistics::class)
        ->call('pickPeriod', 'custom')
        ->assertSet('period', 'custom')
        ->assertViewHas('customInvalid', false)
        ->set('from', '2026-11-10')->set('to', '2026-11-01')
        ->assertViewHas('customInvalid', true);
});
