<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Verification\Enums\VerificationStatus;
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

        /*
         * F2-1: the ID and the selfie are two of the five items, and they used to be checked by a
         * route gate BEFORE the application could even start. Now everything is uploaded in any
         * order, so submit is where all five meet: each of these two must have been SENT to a
         * reviewer (pending) or already be approved. Approving them is F2-2's check, at approval.
         */
        foreach ([VerificationType::GovernmentId, VerificationType::Selfie] as $type) {
            if (! self::submitted($profile, $type)) {
                $missing[] = $type->value;
            }
        }

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

    private static function submitted(DriverProfile $profile, VerificationType $type): bool
    {
        return UserVerification::query()
            ->where('user_id', $profile->user_id)
            ->where('type', $type->value)
            ->whereIn('status', [VerificationStatus::Pending->value, VerificationStatus::Approved->value])
            ->exists();
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
     * §8, F2-15 (PROF-13): the registration is the ONLY car paper required. Insurance and
     * inspection are accepted but never demanded — in Egypt a valid car licence already means the
     * car passed inspection and carries the compulsory insurance, and the app never sends them.
     *
     * F2-4 (PROF-14): the registration is two sides, `registration_front` and `registration_back`.
     * The single legacy `registration` image still counts on its own until every installed app
     * sends both sides; a car with only ONE side is missing the other, by name.
     *
     * @return array<int, string>
     */
    private static function missingVehicleDocuments(Vehicle $vehicle): array
    {
        $types = $vehicle->documents()->pluck('type')
            ->map(fn ($type) => $type instanceof VehicleDocumentType ? $type->value : (string) $type)
            ->all();

        if (in_array(VehicleDocumentType::Registration->value, $types, true)) {
            return [];
        }

        $front = in_array(VehicleDocumentType::RegistrationFront->value, $types, true);
        $back = in_array(VehicleDocumentType::RegistrationBack->value, $types, true);

        return match (true) {
            $front && $back => [],
            $front => [VehicleDocumentType::RegistrationBack->value],
            $back => [VehicleDocumentType::RegistrationFront->value],
            default => ['vehicle_registration'],
        };
    }
}
