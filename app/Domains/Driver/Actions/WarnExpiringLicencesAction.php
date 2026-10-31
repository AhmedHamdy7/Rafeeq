<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Support\DriverChecklist;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Carbon\CarbonImmutable;

/**
 * "Your driving licence expires soon" (Feature 03 files §8, PROF-15's "Expiring").
 *
 * 🔴 **The same window the driver card already uses** (`verification.licence_expiry_warning_days`,
 * read by {@see DriverChecklist}), so the push and the "Expiring" headline on her card start on the
 * same day. A push that arrived a week before the card agreed with it would read as a mistake.
 *
 * 🔒 **Once per expiry date**, recorded as the date it was sent about (`expiry_warned_for`) rather
 * than a flag: a renewed licence with a new expiry is warned about again when its own time comes,
 * and the old one is never warned about twice. The stamp is claimed with a conditional update, so
 * two overlapping runs cannot both send it.
 */
final readonly class WarnExpiringLicencesAction
{
    /**
     * @return int how many drivers were warned
     */
    public function sweep(): int
    {
        $now = CarbonImmutable::now();
        $days = (int) config('rafeeq.verification.licence_expiry_warning_days');

        $sent = 0;

        UserVerification::query()
            ->where('type', VerificationType::DrivingLicence->value)
            ->where('status', VerificationStatus::Approved->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->addDays($days))
            ->where(fn ($query) => $query
                ->whereNull('expiry_warned_for')
                ->orWhereColumn('expiry_warned_for', '!=', 'expires_at'))
            ->with('user')
            ->orderBy('id')
            ->chunkById(200, function ($licences) use (&$sent): void {
                foreach ($licences as $licence) {
                    if ($licence->user === null) {
                        continue;
                    }

                    $claimed = UserVerification::query()
                        ->whereKey($licence->getKey())
                        ->where(fn ($query) => $query
                            ->whereNull('expiry_warned_for')
                            ->orWhereColumn('expiry_warned_for', '!=', 'expires_at'))
                        ->update(['expiry_warned_for' => $licence->expires_at]);

                    if ($claimed === 0) {
                        continue;
                    }

                    Notifier::send($licence->user, NotificationType::LicenceExpiring, params: [
                        'date' => $licence->expires_at->toDateString(),
                    ]);

                    $sent++;
                }
            });

        return $sent;
    }
}
