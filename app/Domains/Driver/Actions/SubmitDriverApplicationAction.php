<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\DriverApplicationChecklist;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Hands a complete application to the review queue, and takes it back
 * (Chapter 3 §9).
 *
 * Submitting locks editing. Withdrawing is the only way to reopen it, which is
 * what the chapter offers instead of letting details change while a reviewer is
 * reading them.
 */
final readonly class SubmitDriverApplicationAction
{
    public function submit(DriverProfile $profile): DriverProfile
    {
        if ($profile->status === DriverProfileStatus::PendingReview) {
            // Idempotent: a second tap on a slow connection must not queue the
            // application twice for two reviewers to duplicate.
            return $profile;
        }

        if (! DriverApplicationState::isSubmittable($profile->status)) {
            throw DomainException::of(ErrorCode::DriverApplicationLocked);
        }

        $missing = DriverApplicationChecklist::missingFor($profile);

        if ($missing !== []) {
            throw DomainException::of(ErrorCode::DriverApplicationIncomplete, fields: [
                'missing' => $missing,
            ]);
        }

        // Re-checked at submission, not only when it was typed: an application
        // can sit in draft for weeks, and a licence that was valid then may not
        // be now.
        if (! $profile->hasValidLicence()) {
            throw DomainException::of(
                ErrorCode::LicenceExpired,
                message: __('errors.DRIVER_LICENCE_EXPIRED', [
                    'days' => config('rafeeq.driver.licence_minimum_validity_days'),
                ]),
            );
        }

        return DB::transaction(function () use ($profile): DriverProfile {
            $profile->status = DriverProfileStatus::PendingReview->value;
            $profile->rejection_reason = null;
            $profile->save();

            SecurityLog::record(SecurityEventType::DriverApplicationSubmitted, $profile->user);

            return $profile;
        });
    }

    public function withdraw(DriverProfile $profile): DriverProfile
    {
        if (! DriverApplicationState::isWithdrawable($profile->status)) {
            throw DomainException::of(ErrorCode::DriverApplicationLocked);
        }

        $profile->status = DriverProfileStatus::Draft->value;
        $profile->save();

        return $profile;
    }
}
