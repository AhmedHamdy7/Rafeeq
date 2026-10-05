<?php

use App\Domains\Admin\Actions\ArmEscortAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Enums\CorridorStatus;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Safety\Models\EscortWindow;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Support\EscortCoverage;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Escort;
use App\Livewire\Admin\LiveTrips;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Night escort mode (Phase 13).
 *
 * 🔴 The failure this guards against is quiet: a schedule that stops arming, or arms twice, or a
 * corridor that stays "armed" in a column long after anybody stopped watching it. So the tests are
 * about the window being exactly one per night, ending when it says it does, and being recorded.
 */
beforeEach(function () {
    $this->lead = adminWithRole(AdminRole::SafetyLead);
});

/*
|--------------------------------------------------------------------------
| Tonight
|--------------------------------------------------------------------------
*/

it('means the night that is running after midnight, and the coming one during the day', function () {
    // 02:00 Cairo on the 6th: still the night that began at 21:00 on the 5th.
    [$start, $end] = EscortCoverage::tonight(CarbonImmutable::parse('2026-10-06 02:00', 'Africa/Cairo'));

    expect($start->setTimezone('Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-05 21:00')
        ->and($end->setTimezone('Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-06 05:00');

    // 15:00 on the 6th: the night that starts at 21:00 today.
    [$start] = EscortCoverage::tonight(CarbonImmutable::parse('2026-10-06 15:00', 'Africa/Cairo'));

    expect($start->setTimezone('Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-06 21:00');
});

it('follows the hours in settings', function () {
    PlatformSetting::updateOrCreate(['setting_key' => 'safety.escort_starts_hour'], ['setting_value' => 19, 'value_type' => 'integer']);

    [$start] = EscortCoverage::tonight(CarbonImmutable::parse('2026-10-06 15:00', 'Africa/Cairo'));

    expect($start->setTimezone('Africa/Cairo')->format('H:i'))->toBe('19:00');
});

/*
|--------------------------------------------------------------------------
| The nightly schedule
|--------------------------------------------------------------------------
*/

it('arms every corridor for tonight, once, however many times it runs', function () {
    Corridor::factory()->count(2)->create();

    $this->artisan('escort:arm-tonight')->assertSuccessful();
    $this->artisan('escort:arm-tonight')->assertSuccessful();

    [$start, $end] = EscortCoverage::tonight();

    expect(EscortWindow::query()->count())->toBe(2)
        ->and(EscortWindow::query()->where('is_auto', true)->whereNull('armed_by')->count())->toBe(2)
        ->and(EscortWindow::query()->first()->starts_at->equalTo($start))->toBeTrue()
        ->and(EscortWindow::query()->first()->ends_at->equalTo($end))->toBeTrue();
});

it('arms nothing when night escort is switched off', function () {
    Corridor::factory()->create();
    PlatformSetting::updateOrCreate(['setting_key' => 'safety.night_escort_enabled'], ['setting_value' => 0, 'value_type' => 'integer']);

    expect(app(ArmEscortAction::class)->armTonight())->toBe(0)
        ->and(EscortWindow::query()->exists())->toBeFalse();
});

it('does not stack a nightly window on a corridor somebody already armed by hand', function () {
    $corridor = Corridor::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 22:00', 'Africa/Cairo'));

    app(ArmEscortAction::class)->arm($corridor, $this->lead, 4, 'Incident reported on this road last week.');

    expect(app(ArmEscortAction::class)->armTonight())->toBe(0)
        ->and(EscortWindow::query()->count())->toBe(1);
});

/**
 * 🔴 Armed is a fact about time. Nothing writes it into the corridor or into the timeline of things
 * that happened to members (Screen Map §8.0.1).
 */
it('writes neither a corridor status nor a safety event', function () {
    $corridor = Corridor::factory()->create();

    app(ArmEscortAction::class)->armTonight();
    app(ArmEscortAction::class)->arm(Corridor::factory()->create(), $this->lead, 2, 'Road closure tonight, watching it.');

    expect($corridor->refresh()->status)->toBe(CorridorStatus::Healthy)
        ->and(SafetyEvent::query()->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| By hand
|--------------------------------------------------------------------------
*/

it('lets the safety desk arm a corridor, with a reason that is kept', function () {
    $corridor = Corridor::factory()->create();
    actingAsAdmin($this->lead);

    Livewire::test(Escort::class)
        ->call('open', $corridor->id, 'arm')
        ->set('hours', 3)
        ->set('reason', 'Harassment report on this route yesterday.')
        ->call('arm', $corridor->id)
        ->assertHasNoErrors();

    $window = EscortCoverage::activeFor($corridor->id);

    expect($window)->not->toBeNull()
        ->and($window->armed_by)->toBe($this->lead->id)
        ->and($window->is_auto)->toBeFalse()
        ->and((int) round(now()->diffInMinutes($window->ends_at)))->toBe(180);

    $audit = AdminAction::query()->where('action', 'escort.arm')->sole();

    expect($audit->reason)->toBe('Harassment report on this route yesterday.');
});

it('refuses to arm a corridor that is already under escort', function () {
    $corridor = Corridor::factory()->create();
    $action = app(ArmEscortAction::class);

    $action->arm($corridor, $this->lead, 2, 'First operator arming it.');

    expect(fn () => $action->arm($corridor, $this->lead, 2, 'Second operator, same minute.'))
        ->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe(ErrorCode::EscortAlreadyArmed));
});

it('refuses an arming without a reason, or for longer than a night', function () {
    $corridor = Corridor::factory()->create();
    actingAsAdmin($this->lead);

    Livewire::test(Escort::class)
        ->set('hours', 2)
        ->set('reason', '')
        ->call('arm', $corridor->id)
        ->assertHasErrors('reason');

    Livewire::test(Escort::class)
        ->set('hours', ArmEscortAction::MAX_MANUAL_HOURS + 1)
        ->set('reason', 'A long enough reason for the log.')
        ->call('arm', $corridor->id)
        ->assertHasErrors('hours');

    expect(EscortWindow::query()->exists())->toBeFalse();
});

it('stands a corridor down early, keeping the row with its real end time', function () {
    $corridor = Corridor::factory()->create();
    app(ArmEscortAction::class)->arm($corridor, $this->lead, 4, 'Watching after last night.');
    actingAsAdmin($this->lead);

    Livewire::test(Escort::class)
        ->call('open', $corridor->id, 'disarm')
        ->set('reason', 'Road reopened, nothing unusual tonight.')
        ->call('disarm', $corridor->id)
        ->assertHasNoErrors();

    $window = EscortWindow::query()->sole();

    expect(EscortCoverage::activeFor($corridor->id))->toBeNull()
        ->and($window->ends_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and(AdminAction::query()->where('action', 'escort.disarm')->sole()->reason)
        ->toBe('Road reopened, nothing unusual tonight.');
});

it('refuses to stand down a window that has already ended', function () {
    $window = EscortWindow::factory()->create([
        'starts_at' => now()->subHours(5),
        'ends_at' => now()->subHour(),
    ]);

    expect(fn () => app(ArmEscortAction::class)->disarm($window, $this->lead, 'Too late, already over.'))
        ->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe(ErrorCode::EscortNotActive));
});

it('shows the page to anybody on the safety desk but the switch only to those who may use it', function () {
    Corridor::factory()->create();

    actingAsAdmin($this->lead);
    Livewire::test(Escort::class)->assertOk()->assertSee(__('admin.escort.arm'));
});

it('keeps the page from staff without safety access', function (AdminRole $role) {
    actingAsAdmin(adminWithRole($role));

    $this->get(route('admin.escort'))->assertForbidden();
})->with([AdminRole::Support, AdminRole::Finance, AdminRole::Verification]);

it('refuses arming from somebody who can read the desk but not act on it', function () {
    $corridor = Corridor::factory()->create();
    $reader = adminWithRole(AdminRole::Support);
    $reader->givePermissionTo('safety.view');
    actingAsAdmin($reader);

    Livewire::test(Escort::class)
        ->assertDontSee(__('admin.escort.arm'))
        ->set('hours', 2)
        ->set('reason', 'Trying the action directly.')
        ->call('arm', $corridor->id)
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Trips covered
|--------------------------------------------------------------------------
*/

it('counts a run that starts on a corridor under escort, and badges it on the live board', function () {
    Storage::fake('documents');

    $driver = approvedDriver();
    $commuteId = readyCommute($driver, Vehicle::sole()->id);
    $this->withToken($driver)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $corridor = Corridor::factory()->create();
    CommuteOffer::query()->whereKey($commuteId)->update(['corridor_id' => $corridor->id]);
    $window = app(ArmEscortAction::class)->arm($corridor, $this->lead, 4, 'Watching this route tonight.');

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    runLeavingIn($tripId, 10);
    $this->withToken($driver)->postJson("/api/v1/trips/{$tripId}/start")->assertStatus(201);

    expect($window->refresh()->trips_covered)->toBe(1);

    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    Livewire::test(LiveTrips::class)->assertSee(__('admin.trips.escort'));
    Livewire::test(Dashboard::class)->assertSee(__('admin.dashboard.escort'));
});

it('counts nothing for a run on a corridor nobody is watching', function () {
    Storage::fake('documents');

    $driver = approvedDriver();
    $commuteId = readyCommute($driver, Vehicle::sole()->id);
    $this->withToken($driver)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $watched = Corridor::factory()->create();
    $window = app(ArmEscortAction::class)->arm($watched, $this->lead, 4, 'Watching a different route.');
    CommuteOffer::query()->whereKey($commuteId)->update(['corridor_id' => Corridor::factory()->create()->id]);

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    runLeavingIn($tripId, 10);
    $this->withToken($driver)->postJson("/api/v1/trips/{$tripId}/start")->assertStatus(201);

    expect($window->refresh()->trips_covered)->toBe(0);
});
