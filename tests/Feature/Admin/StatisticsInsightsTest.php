<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Admin\Support\StatisticsFilter;
use App\Domains\Admin\Support\StatisticsInsights;
use App\Domains\Admin\Support\StatisticsPeriod;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Livewire\Admin\Statistics;
use Livewire\Livewire;

/**
 * Statistics · supply and demand, quality and safety, team performance, money health (T03, T04),
 * the area and car-tier filters, and export.
 */
it('counts published commutes, and narrows them by car tier', function () {
    $comfort = CommuteOffer::factory()->published()->create();
    $comfort->vehicle->forceFill(['tier' => VehicleTier::Comfort->value])->save();
    CommuteOffer::factory()->published()->create();

    $period = StatisticsPeriod::of('this_week');

    expect(StatisticsInsights::supply($period, new StatisticsFilter)['offers']['published'])->toBe(2)
        ->and(StatisticsInsights::supply($period, StatisticsFilter::of('', 'comfort'))['offers']['published'])->toBe(1);
});

it('ignores a car tier or area that does not exist', function () {
    $filter = StatisticsFilter::of('Atlantis', 'limousine');

    expect($filter->isActive())->toBeFalse();
});

it('counts each team member’s actions from the audit log', function () {
    $lead = adminWithRole(AdminRole::SafetyLead);
    AdminActionLog::record($lead, 'sos.acknowledge', $lead);
    AdminActionLog::record($lead, 'escort.arm', $lead);

    $people = StatisticsInsights::team(StatisticsPeriod::of('this_week'))['people'];

    expect($people)->toHaveCount(1)
        ->and($people[0]['sos'])->toBe(1)
        ->and($people[0]['total'])->toBe(2);
});

it('lists the biggest debts, today', function () {
    DebtAccount::factory()->create(['side' => DebtSide::Driver, 'balance_piastres' => 61_200, 'suspended_at' => now()]);
    DebtAccount::factory()->create(['side' => DebtSide::Passenger, 'balance_piastres' => 21_450]);

    $money = StatisticsInsights::money();

    expect($money['biggest'][0]['owed'])->toBe(61_200)
        ->and($money['biggest'][0]['suspended'])->toBeTrue()
        ->and($money['driversBlocked'])->toBe(1);
});

it('opens the new tabs with a filter in the address', function (string $tab) {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.statistics', ['tab' => $tab, 'tier' => 'comfort']))->assertOk();
})->with(['supply', 'quality', 'team', 'money']);

it('exports a tab as CSV for an Admin, writing it to the audit log', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::withQueryParams(['tab' => 'money'])->test(Statistics::class)->call('export')->assertFileDownloaded();

    expect(AdminAction::query()->where('action', 'table.export')->sole()->new_value['table'])->toBe('statistics.money');
});

it('keeps export from Finance, who may read but not export', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Statistics::class)->call('export')->assertForbidden();
});
