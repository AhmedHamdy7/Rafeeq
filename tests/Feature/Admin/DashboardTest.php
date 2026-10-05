<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\SafetyCaseQueue;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Verification\Models\UserVerification;
use App\Livewire\Admin\Dashboard;
use Livewire\Livewire;

/**
 * The DASHBOARD (Phase 13). Its tiles count the same way as the pages they link to; a tile
 * that disagrees with its page teaches staff to stop reading the dashboard.
 */
it('counts open safety cases exactly as the safety page does', function () {
    SosEvent::factory()->create(['safety_event_id' => SafetyEvent::factory()->create()->id]);
    Incident::factory()->create(['severity' => SafetySeverity::Critical]);
    Incident::factory()->create();

    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $total = SafetyCaseQueue::liveAlertCount() + SafetyCaseQueue::openReportCount();

    expect($total)->toBe(3);

    Livewire::test(Dashboard::class)
        ->assertViewHas('safety', fn (array $safety) => $safety['alerts'] + $safety['reports'] === 3
            && $safety['criticalUnassigned'] === 1)
        ->assertSee(trans_choice('admin.dashboard.critical_unassigned', 1, ['count' => 1]));
});

it('splits the runs on the road by status', function () {
    TripSession::factory()->inProgress()->count(2)->create();
    TripSession::factory()->create(['current_status' => TripSessionStatus::EnRoute->value]);
    TripSession::factory()->create(['current_status' => TripSessionStatus::Completed->value]);

    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(Dashboard::class)
        ->assertViewHas('trips', fn (array $trips) => $trips['total'] === 3
            && $trips['byStatus']['in_progress'] === 2
            && $trips['byStatus']['en_route'] === 1);
});

/**
 * 🔒 Each tile is gated on the permission of the page it links to.
 */
it('shows each admin only the tiles for queues they may open', function () {
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    Livewire::test(Dashboard::class)
        ->assertViewHas('verifications', fn ($value) => $value !== null)
        ->assertViewHas('safety', null)
        ->assertViewHas('trips', null)
        ->assertViewHas('recent', null)
        ->assertDontSee(__('admin.dashboard.safety'));
});

it('has no dashboard for a role with no tile on it', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Dashboard::class)->assertForbidden();
});

it('never says the verification queue is empty while it has people in it', function () {
    UserVerification::factory()->create(['submitted_at' => null]);

    actingAsAdmin(adminWithRole(AdminRole::Verification));

    Livewire::test(Dashboard::class)->assertDontSee(__('admin.queue.empty'));
});
