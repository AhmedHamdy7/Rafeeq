<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class StopExpiredLicencesAction
{
    public function __construct(private ChangeCommuteStatusAction $commutes) {}

    public function sweep(): int
    {
        $stopped = 0;

        DriverProfile::query()
            ->where('status', DriverProfileStatus::Approved->value)
            ->whereDate('licence_expiry', '<=', now()->toDateString())
            ->orderBy('user_id')
            ->chunkById(200, function ($profiles) use (&$stopped): void {
                foreach ($profiles as $profile) {
                    try {
                        $stopped += $this->stop($profile->user_id) ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }, 'user_id');

        return $stopped;
    }

    private function stop(string $userId): bool
    {
        return DB::transaction(function () use ($userId): bool {
            $profile = DriverProfile::query()->whereKey($userId)->lockForUpdate()->first();

            if ($profile === null || $profile->status !== DriverProfileStatus::Approved || $profile->hasValidLicence()) {
                return false;
            }

            $profile->status = DriverProfileStatus::ExpiredDocuments->value;
            $profile->save();

            $paused = $this->commutes->pauseUnpublishableOffers($profile, CommutePausedReason::LicenceExpired);

            SecurityLog::record(SecurityEventType::DriverLicenceExpired, $profile->user, metadata: [
                'commutes_paused' => $paused,
            ]);

            Notifier::send($profile->user, NotificationType::DriverLicenceExpired, data: [
                'itemKey' => 'driving_licence',
                'expiresAt' => $profile->licence_expiry->toDateString(),
            ]);

            return true;
        });
    }
}
