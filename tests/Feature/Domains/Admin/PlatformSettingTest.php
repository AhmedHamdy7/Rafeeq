<?php

use App\Domains\Admin\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

it('caches its value indefinitely and invalidates on write', function () {
    $setting = PlatformSetting::factory()->create(['setting_key' => 'trip.grace_period_seconds', 'setting_value' => 300]);

    expect(PlatformSetting::value('trip.grace_period_seconds'))->toBe(300)
        ->and(Cache::has('platform_settings.trip.grace_period_seconds'))->toBeTrue();

    $setting->update(['setting_value' => 900]);

    expect(PlatformSetting::value('trip.grace_period_seconds'))->toBe(900);
});

it('falls back to the given default when the key is missing', function () {
    expect(PlatformSetting::value('does.not.exist', 42))->toBe(42);
});

it('never caches one caller default and serves it to another', function () {
    // Caching the fallback would freeze the first caller's value forever.
    expect(PlatformSetting::value('missing.key', 42))->toBe(42)
        ->and(PlatformSetting::value('missing.key', 99))->toBe(99)
        ->and(PlatformSetting::value('missing.key'))->toBeNull();
});

it('stops serving the default once the setting actually exists', function () {
    expect(PlatformSetting::value('later.key', 'fallback'))->toBe('fallback');

    PlatformSetting::factory()->create(['setting_key' => 'later.key', 'setting_value' => 'real']);

    expect(PlatformSetting::value('later.key', 'fallback'))->toBe('real');
});
