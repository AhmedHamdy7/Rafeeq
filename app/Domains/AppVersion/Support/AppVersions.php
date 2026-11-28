<?php

namespace App\Domains\AppVersion\Support;

use App\Domains\AppVersion\Enums\AppPlatform;
use App\Domains\AppVersion\Models\AppRelease;
use Illuminate\Support\Facades\Cache;

/**
 * Comparing app versions and deciding what a given install must do.
 *
 * 🔴 Versions are compared as numbers part by part — `1.10.0` is NEWER than `1.9.0`. A string
 * comparison gets that backwards, and the result is forcing everybody on 1.10 to "update" to 1.9.
 *
 * The decision, for a version V on a platform:
 *   - V < minimum                      → update REQUIRED (the app blocks until updated)
 *   - force_update on and V < latest   → update REQUIRED
 *   - V < latest                       → update AVAILABLE (the app suggests it)
 *   - otherwise                        → up to date
 */
final class AppVersions
{
    /** `1`, `1.2`, `1.2.3` or `1.2.3.4`; a build suffix (`+45`, `-beta`) is ignored. */
    public const string PATTERN = '/^\d{1,5}(\.\d{1,5}){0,3}$/';

    private const string CACHE_KEY = 'app_versions.release.';

    /** The version without its build suffix, or null when it is not a version at all. */
    public static function normalise(?string $version): ?string
    {
        $version = trim((string) preg_replace('/[+\-\s].*$/', '', trim((string) $version)));

        return preg_match(self::PATTERN, $version) === 1 ? $version : null;
    }

    public static function isValid(?string $version): bool
    {
        return self::normalise($version) !== null && trim((string) $version) === self::normalise($version);
    }

    /** -1, 0 or 1, comparing part by part with missing parts as 0 (`1.2` = `1.2.0`). */
    public static function compare(string $a, string $b): int
    {
        $left = array_map('intval', explode('.', (string) self::normalise($a)));
        $right = array_map('intval', explode('.', (string) self::normalise($b)));
        $length = max(count($left), count($right));

        for ($i = 0; $i < $length; $i++) {
            $difference = ($left[$i] ?? 0) <=> ($right[$i] ?? 0);

            if ($difference !== 0) {
                return $difference;
            }
        }

        return 0;
    }

    /**
     * The release policy for a store, cached briefly: the server-side check runs on every API
     * request that sends a version, and the row changes only when staff save the page.
     */
    public static function release(AppPlatform $platform): ?AppRelease
    {
        $attributes = Cache::remember(self::CACHE_KEY.$platform->value, 300, function () use ($platform): ?array {
            return AppRelease::query()->find($platform->value)?->getAttributes();
        });

        return $attributes === null ? null : (new AppRelease)->newFromBuilder($attributes);
    }

    public static function forget(AppPlatform $platform): void
    {
        Cache::forget(self::CACHE_KEY.$platform->value);
    }

    /**
     * What an install of `$current` on `$platform` should do. `$current` may be null or unreadable:
     * the policy is still returned, and both flags are false.
     *
     * @return array{configured: bool, updateRequired: bool, updateAvailable: bool}
     */
    public static function decide(?AppRelease $release, ?string $current): array
    {
        $current = self::normalise($current);

        if ($release === null || $current === null) {
            return ['configured' => $release !== null, 'updateRequired' => false, 'updateAvailable' => false];
        }

        $belowLatest = self::compare($current, $release->latest_version) < 0;
        $belowMinimum = self::compare($current, $release->minimum_version) < 0;

        return [
            'configured' => true,
            'updateRequired' => $belowMinimum || ($release->force_update && $belowLatest),
            'updateAvailable' => $belowLatest,
        ];
    }
}
