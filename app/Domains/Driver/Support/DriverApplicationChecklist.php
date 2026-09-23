<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Enums\VirusScanStatus;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\VerificationRequirements;

/**
 * What an application still needs before it can go to a reviewer
 * (Chapter 3 §9's review screen, and the check behind the submit button).
 *
 * Returned as a list rather than a boolean so the review screen can show the
 * applicant exactly what is outstanding. The same list is what the submit
 * Action refuses on, so the screen and the rule can never disagree.
 */
final class DriverApplicationChecklist
{
    /**
     * @return array<int, string>
     */
    public static function missingFor(DriverProfile $profile): array
    {
        $missing = [];

        if ($profile->national_id_hash === null) {
            $missing[] = 'national_id';
        }

        if ($profile->licence_number_hash === null) {
            $missing[] = 'licence_number';
        }

        if ($profile->licence_expiry === null) {
            $missing[] = 'licence_expiry';
        }

        foreach (self::missingLicenceDocuments($profile) as $kind) {
            $missing[] = $kind;
        }

        $vehicle = $profile->vehicles()->first();

        if ($vehicle === null) {
            $missing[] = 'vehicle';
        } else {
            foreach (self::missingVehicleDocuments($vehicle) as $kind) {
                $missing[] = $kind;
            }
        }

        return $missing;
    }

    /**
     * Licence images live in `identity_documents` under a `driving_licence`
     * verification — see the note on that VerificationType for why.
     *
     * @return array<int, string>
     */
    private static function missingLicenceDocuments(DriverProfile $profile): array
    {
        $verification = UserVerification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', VerificationType::DrivingLicence->value)
            ->first();

        $uploaded = [];

        if ($verification !== null) {
            foreach ($verification->documents()->where('virus_scan_status', VirusScanStatus::Clean->value)->get() as $document) {
                $uploaded[] = $document->kind->value;
            }
        }

        $missing = [];

        foreach (VerificationRequirements::documentsFor(VerificationType::DrivingLicence) as $kind) {
            if (! in_array($kind->value, $uploaded, true)) {
                $missing[] = $kind->value;
            }
        }

        return $missing;
    }

    /**
     * §8: registration is required now. Insurance and inspection are marked
     * "future" in the chapter, so they are accepted but not demanded — asking
     * for documents the Egyptian market does not routinely have would block
     * every applicant.
     *
     * @return array<int, string>
     */
    private static function missingVehicleDocuments(Vehicle $vehicle): array
    {
        $hasRegistration = $vehicle->documents()
            ->where('type', 'registration')
            ->exists();

        return $hasRegistration ? [] : ['vehicle_registration'];
    }
}
