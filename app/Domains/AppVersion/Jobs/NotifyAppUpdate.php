<?php

namespace App\Domains\AppVersion\Jobs;

use App\Domains\AppVersion\Enums\AppPlatform;
use App\Domains\AppVersion\Models\AppRelease;
use App\Domains\AppVersion\Support\AppVersions;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Tells members their app is out of date: the dashboard's "notify users" switch on App versions.
 *
 * Who: every member with an active phone on THIS store whose recorded app version is older than
 * the release, or unknown (an install too old to report its version is the most out of date of
 * all). Somebody already on the release is not bothered.
 *
 * Once per version: the release row remembers `notified_version`, and a second run for the same
 * version — a double click, a retried job — sends nothing.
 *
 * Queued and chunked, like announcements: it can reach every member, and must not run inside
 * the staff member's page request.
 */
final class NotifyAppUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $platform, public readonly string $version) {}

    public function handle(): void
    {
        $platform = AppPlatform::from($this->platform);

        // Claimed atomically, so two runs for the same version cannot both send.
        $claimed = DB::transaction(function () use ($platform): ?AppRelease {
            $release = AppRelease::query()->whereKey($platform->value)->lockForUpdate()->first();

            if ($release === null
                || $release->latest_version !== $this->version
                || $release->notified_version === $this->version) {
                return null;
            }

            $release->forceFill(['notified_version' => $this->version, 'notified_at' => now(), 'notified_count' => 0])->save();

            return $release;
        });

        if ($claimed === null) {
            return;
        }

        $reach = 0;

        foreach (array_chunk(self::outdatedUserIds($platform, $this->version), 500) as $ids) {
            foreach (User::query()->whereKey($ids)->get() as $user) {
                Notifier::send($user, NotificationType::AppUpdate, params: [
                    'version' => $this->version,
                ], data: [
                    'platform' => $platform->value,
                    'version' => $this->version,
                    'storeUrl' => (string) $claimed->store_url,
                    // Whether THIS install must update depends on its own version: the app compares
                    // against these two, or simply calls GET /app/version when the message opens.
                    'minimumVersion' => (string) $claimed->minimum_version,
                    'forceUpdate' => $claimed->force_update ? 'true' : 'false',
                ]);

                $reach++;
            }
        }

        $claimed->forceFill(['notified_count' => $reach])->save();
        AppVersions::forget($platform);
    }

    /**
     * Members with an active phone on this store that is older than `$version` (or does not say).
     *
     * @return array<int, string>
     */
    public static function outdatedUserIds(AppPlatform $platform, string $version): array
    {
        $ids = [];

        Device::query()
            ->where('platform', $platform->value)
            ->whereNull('revoked_at')
            ->select(['id', 'user_id', 'app_version'])
            ->chunkById(1000, function ($devices) use ($version, &$ids): void {
                foreach ($devices as $device) {
                    $installed = AppVersions::normalise($device->app_version);

                    if ($installed === null || AppVersions::compare($installed, $version) < 0) {
                        $ids[$device->user_id] = true;
                    }
                }
            });

        return array_keys($ids);
    }
}
