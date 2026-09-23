<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\DuplicateDetector;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The licence details behind a driver application (Chapter 3 §6).
 *
 * Both numbers are written through the model's virtual attributes, which split
 * each into an encrypted column and a sha256 hash. Nothing in this class ever
 * holds a decrypted value longer than the call, and nothing ever compares one:
 * duplicates are found on the hash.
 */
final readonly class RecordLicenceDetailsAction
{
    public function execute(
        DriverProfile $profile,
        string $nationalId,
        string $licenceNumber,
        CarbonImmutable $licenceExpiry,
    ): DriverProfile {
        DriverApplicationState::assertEditable($profile->status);
        $this->assertLicenceIsValidLongEnough($licenceExpiry);

        // Checked before writing, so a duplicate never lands in the table even
        // briefly — and excluding this profile, because re-submitting one's own
        // unchanged details is not a duplicate.
        if (DuplicateDetector::nationalIdTaken($nationalId, exceptUserId: $profile->user_id)
            || DuplicateDetector::licenceNumberTaken($licenceNumber, exceptUserId: $profile->user_id)) {
            SecurityLog::record(SecurityEventType::DriverDuplicateDetected, $profile->user, metadata: [
                // Which field matched is recorded for a fraud reviewer but
                // never returned to the applicant.
                'national_id_match' => DuplicateDetector::nationalIdTaken($nationalId, $profile->user_id),
                'licence_match' => DuplicateDetector::licenceNumberTaken($licenceNumber, $profile->user_id),
            ]);

            throw DomainException::of(ErrorCode::DriverDuplicateDetected);
        }

        return DB::transaction(function () use ($profile, $nationalId, $licenceNumber, $licenceExpiry): DriverProfile {
            $profile->national_id = $nationalId;
            $profile->licence_number = $licenceNumber;
            $profile->licence_expiry = $licenceExpiry;
            $profile->save();

            return $profile;
        });
    }

    /**
     * §6: expiry must be in the future — with room for the review itself.
     * Approving a licence that expires tomorrow produces a driver who is
     * already invalid by the time they publish their first commute.
     */
    private function assertLicenceIsValidLongEnough(CarbonImmutable $expiry): void
    {
        $minimumDays = (int) config('rafeeq.driver.licence_minimum_validity_days');

        if ($expiry->lessThan(now()->addDays($minimumDays))) {
            throw DomainException::of(
                ErrorCode::LicenceExpired,
                message: __('errors.DRIVER_LICENCE_EXPIRED', ['days' => $minimumDays]),
            );
        }
    }
}
