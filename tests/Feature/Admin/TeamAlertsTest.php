<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\TeamAlerts;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;

/**
 * Team alerts (N03): the bell counts what is waiting past its promise, per permission.
 */
it('rings for an untouched SOS and an overdue report on the safety desk', function () {
    SosEvent::factory()->create(['safety_event_id' => SafetyEvent::factory()->create()->id]);
    Incident::factory()->create()->forceFill(['sla_due_at' => now()->subHour()])->save();

    $keys = collect(TeamAlerts::for(adminWithRole(AdminRole::SafetyLead)))->pluck('count', 'key');

    expect($keys->get('sos_untouched'))->toBe(1)
        ->and($keys->get('reports_overdue'))->toBe(1)
        ->and($keys->has('payments_late'))->toBeFalse();
});

it('shows Finance late payments only, never the safety desk', function () {
    SosEvent::factory()->create(['safety_event_id' => SafetyEvent::factory()->create()->id]);
    DebtPayment::factory()->create(['created_at' => now()->subHours(TeamAlerts::LATE_HOURS + 1)]);
    DebtPayment::factory()->create(); // fresh: not late yet

    $keys = collect(TeamAlerts::for(adminWithRole(AdminRole::Finance)))->pluck('count', 'key');

    expect($keys->get('payments_late'))->toBe(1)
        ->and($keys->has('sos_untouched'))->toBeFalse();
});

it('draws the bell in the top bar with the lines and an empty state', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));
    $this->get(route('admin.home'))->assertOk()->assertSee(__('admin/shell.alerts.none'));

    DebtPayment::factory()->create(['created_at' => now()->subHours(TeamAlerts::LATE_HOURS + 1)]);
    $this->get(route('admin.home'))->assertOk()->assertSee(trans_choice('admin/shell.alerts.payments_late', 1, ['count' => 1, 'hours' => TeamAlerts::LATE_HOURS]))->assertDontSee(__('admin/shell.alerts.none'));
});

it('has every alert line in both languages', function () {
    expect(array_keys(trans('admin/shell.alerts', [], 'ar')))->toBe(array_keys(trans('admin/shell.alerts', [], 'en')));
});
