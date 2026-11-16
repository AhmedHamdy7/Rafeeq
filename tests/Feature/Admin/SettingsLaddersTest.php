<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Support\SettingsLadders;
use App\Domains\Payment\Support\FeeSettings;
use App\Livewire\Admin\Settings;
use Livewire\Livewire;

/**
 * Settings · the fee ladders (F02): edited whole, never going down, priced on an EGP 110 seat.
 */
it('saves a whole ladder, one audit row per rung that changed', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Settings::class)
        ->call('editLadder', 'no_show')
        ->set('ladderValues.3', '60')
        ->set('ladderValues.4', '80')
        ->set('reason', 'Repeat no-shows are up this month')
        ->call('saveLadder')
        ->assertHasNoErrors();

    expect(FeeSettings::noShowPercent(3))->toBe(60)
        ->and(FeeSettings::noShowPercent(4))->toBe(80)
        ->and(AdminAction::query()->where('action', 'settings.update')->count())->toBe(2);
});

it('refuses a ladder that goes down, and changes nothing', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));
    $before = FeeSettings::noShowPercent(4);

    Livewire::test(Settings::class)
        ->call('editLadder', 'no_show')
        ->set('ladderValues.4', '20')
        ->set('reason', 'Trying a lower fourth rung')
        ->call('saveLadder')
        ->assertHasErrors('ladderValues.4');

    expect(FeeSettings::noShowPercent(4))->toBe($before)
        ->and(AdminAction::query()->where('action', 'settings.update')->count())->toBe(0);
});

it('prices a rung on the EGP 110 example seat the way the fee is charged', function () {
    expect(SettingsLadders::example(30))->toBe(3_300)
        ->and(SettingsLadders::example(75))->toBe(8_250);
});

it('shows the ladder card on the settings page', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.settings'))->assertOk()->assertSee(__('admin.settings.ladders.title'));
});
