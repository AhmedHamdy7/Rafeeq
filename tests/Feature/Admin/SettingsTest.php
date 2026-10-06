<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Admin\Support\SettingsCatalogue;
use App\Domains\Booking\Support\FeeSplit;
use App\Domains\Trip\Support\TripSettings;
use App\Livewire\Admin\Settings;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

/**
 * The SETTINGS page (Phase 13) — the half of standard #11 that was never built: every
 * settings class read `platform_settings` first, and nothing but a console could write it.
 */

/**
 * Every key the code reads through a settings class or `PlatformSetting::value()`.
 *
 * @return list<string>
 */
function settingKeysReadByTheCode(): array
{
    $keys = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // `self::get('trip.wait_grace_seconds')` and `PlatformSetting::value(\n 'x.y', …)`.
        preg_match_all("/(?:self::get|PlatformSetting::value)\(\s*'([a-z_]+(?:\.[a-z0-9_]+)+)'/", $source, $matches);

        array_push($keys, ...$matches[1]);
    }

    return array_values(array_unique($keys));
}

/**
 * 🔴 The catalogue is the whitelist the page edits, so it must be exactly what the code reads:
 * a missing key is a number nobody can tune; an extra key is a field that changes nothing.
 */
it('lists exactly the settings the code reads', function () {
    $read = settingKeysReadByTheCode();

    expect($read)->not->toBeEmpty();

    expect(array_values(array_diff($read, array_keys(SettingsCatalogue::ENTRIES))))->toBe([],
        'Read by the code but missing from SettingsCatalogue — nobody can tune it.');

    expect(array_values(array_diff(array_keys(SettingsCatalogue::ENTRIES), $read)))->toBe([],
        'In SettingsCatalogue but read by nothing — changing it would change nothing.');
});

it('ships every default inside its own bounds, with wording in both languages', function () {
    foreach (SettingsCatalogue::ENTRIES as $key => $entry) {
        $default = SettingsCatalogue::defaultFor($key);

        expect($default)->not->toBeNull("{$key} has no default in config/rafeeq.php")
            ->and((int) $default >= $entry['min'] && (int) $default <= $entry['max'])
            ->toBeTrue("{$key}'s shipped default {$default} is outside {$entry['min']}–{$entry['max']}");

        foreach (['en', 'ar'] as $locale) {
            expect(Lang::has(SettingsCatalogue::labelKey($key), $locale))->toBeTrue("{$key} has no {$locale} label");
            expect(Lang::has('admin.settings.units.'.$entry['unit'], $locale))->toBeTrue("unit {$entry['unit']} has no {$locale} label");
        }
    }
});

function changeSetting(string $key, string $value, string $reason = 'Drivers report the Ring Road needs longer.')
{
    return Livewire::test(Settings::class)
        ->call('edit', $key)
        ->set('value', $value)
        ->set('reason', $reason)
        ->call('save');
}

it('changes a setting, and the code reads the new value at once', function () {
    $admin = actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    expect(TripSettings::waitGraceSeconds())->toBe(300);

    changeSetting('trip.wait_grace_seconds', '420')->assertHasNoErrors();

    expect(TripSettings::waitGraceSeconds())->toBe(420);

    $audit = AdminAction::query()->where('action', 'settings.update')->sole();

    expect($audit->admin_id)->toBe($admin->id)
        ->and($audit->entity_id)->toBe('trip.wait_grace_seconds')
        ->and($audit->old_value)->toBe(['value' => 300])
        ->and($audit->new_value)->toBe(['value' => 420])
        ->and($audit->reason)->toContain('Ring Road')
        ->and(PlatformSetting::find('trip.wait_grace_seconds')->updated_by)->toBe($admin->id);
});

it('refuses a value outside the bounds', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    // A retention of 9 days instead of 90 — the slip of the keyboard the bounds exist for.
    changeSetting('trip.location_retention_days', '9')->assertHasErrors('value');

    expect(PlatformSetting::find('trip.location_retention_days'))->toBeNull();
});

it('requires a reason', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    changeSetting('trip.wait_grace_seconds', '420', reason: '')->assertHasErrors('reason');
});

it('keeps a minimum below its maximum', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    // The highest seat price is 12,000; a lowest of 15,000 would make every commute unpublishable.
    changeSetting('commute.min_price_piastres', '15000')->assertHasErrors('value');

    expect(PlatformSetting::find('commute.min_price_piastres'))->toBeNull();
});

it('will not change a locked setting', function (string $key) {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    changeSetting($key, (string) SettingsCatalogue::ENTRIES[$key]['min'])->assertHasErrors('value');

    expect(PlatformSetting::find($key))->toBeNull();
})->with(['auth.pin.length', 'auth.otp.length']);

/**
 * Decided 2026-10-06: the fee is deducted from the driver at 3% and staff may change it. A booking
 * freezes its own split when it is approved, so the new figure only reaches bookings made after.
 */
it('lets staff change the platform fee, and only new bookings use it', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    changeSetting('booking.platform_fee_percent', '5')->assertHasNoErrors();

    expect(FeeSplit::forSeats(10_000, 1))
        ->toBe(['price' => 10_000, 'platformFee' => 500, 'driverAmount' => 9_500]);
});

it('will not write a key that is not in the catalogue', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Settings::class)
        ->call('edit', 'trip.made_up_setting')
        ->set('value', '5')
        ->set('reason', 'Trying to write something that does not exist.')
        ->call('save')
        ->assertNotFound();

    expect(PlatformSetting::find('trip.made_up_setting'))->toBeNull();
});

/**
 * Removing the row rather than writing the default, so a later change to the shipped default
 * reaches this instance too.
 */
it('resets a setting by removing the override, and audits it', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    changeSetting('trip.wait_grace_seconds', '420');

    Livewire::test(Settings::class)
        ->call('edit', 'trip.wait_grace_seconds', 'reset')
        ->set('reason', 'Back to the default after the trial week.')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlatformSetting::find('trip.wait_grace_seconds'))->toBeNull()
        ->and(TripSettings::waitGraceSeconds())->toBe(300)
        ->and(AdminAction::query()->where('action', 'settings.reset')->sole()->new_value)->toBe(['value' => 300]);
});

it('belongs to the super admin alone', function (AdminRole $role) {
    actingAsAdmin(adminWithRole($role));

    Livewire::test(Settings::class)->assertForbidden();
})->with([AdminRole::Operations, AdminRole::SafetyLead, AdminRole::Verification, AdminRole::Finance, AdminRole::Support]);
