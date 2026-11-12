<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\DashboardSummary;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Models\FeeAppeal;
use App\Livewire\Admin\Dashboard;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Dashboard phase 2: the redrawn Home (H01–H05). The older tiles are covered by
 * DashboardTest; these are the cards the new design adds.
 */
it('greets the staff member and draws the new Home', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin, ['name' => 'Omar Aly']));

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Omar')
        ->assertDontSee('Omar Aly,')
        ->assertSee(__('admin/home.queues_label'))
        ->assertSee(__('admin/home.team'));
});

it('counts InstaPay payments waiting exactly as the Payments page lists them', function () {
    DebtPayment::factory()->count(2)->create();
    DebtPayment::factory()->create(['status' => DebtPaymentStatus::Confirmed]);

    expect(DashboardSummary::paymentsToConfirm()['count'])->toBe(2);

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Dashboard::class)
        ->assertViewHas('payments', fn (array $payments) => $payments['count'] === 2)
        ->assertSee(__('admin/home.queues.payments'));
});

it('counts the appeals each role may decide, and only those', function () {
    FeeAppeal::factory()->create();
    SuspensionAppeal::factory()->create();

    expect(DashboardSummary::appeals(money: true, suspensions: true)['count'])->toBe(2)
        ->and(DashboardSummary::appeals(money: true, suspensions: false)['count'])->toBe(1)
        ->and(DashboardSummary::appeals(money: false, suspensions: true)['count'])->toBe(1);

    // Support may read members but not money: it sees the suspension appeal only.
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Dashboard::class)
        ->assertViewHas('appeals', fn (array $appeals) => $appeals['count'] === 1)
        ->assertViewHas('payments', null);
});

it('splits today’s runs into to run, completed and cancelled', function () {
    $today = now('Africa/Cairo')->toDateString();

    ScheduledTrip::factory()->create(['trip_date' => $today]);
    ScheduledTrip::factory()->create(['trip_date' => $today, 'status' => ScheduledTripStatus::Completed]);
    ScheduledTrip::factory()->create(['trip_date' => $today, 'status' => ScheduledTripStatus::Cancelled]);
    ScheduledTrip::factory()->create(); // tomorrow: not today's

    expect(DashboardSummary::tripDays())->toBe(['scheduled' => 1, 'completed' => 1, 'cancelled' => 1]);
});

it('turns a queue red once its oldest item is past a day', function () {
    DebtPayment::factory()->create()->forceFill(['created_at' => now()->subHours(30)])->save();

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Dashboard::class)->assertSee(__('admin/home.chip.late', ['hours' => 24]));
});

it('has every Home text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/home', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/home', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
