<?php

namespace App\Domains\Verification\Enums;

use App\Domains\Verification\Support\VerificationRequirements;

enum VerificationType: string
{
    case Phone = 'phone';
    case GovernmentId = 'government_id';
    case Selfie = 'selfie';
    case Organization = 'organization';

    /**
     * A driving licence, added in Phase 4. Not one of the four trust levels
     * ({@see VerificationRequirements::levels()}),
     * and deliberately so: it says nothing about whether a passenger should
     * trust someone, only whether that person may drive. It never appears in
     * the Verification Centre and never moves `users.trust_level`.
     *
     * It is a verification type because its images need somewhere to live:
     * `identity_documents.user_verification_id` is NOT NULL, and the ERD's own
     * `DocumentKind` already lists `licence_front` and `licence_back` — so the
     * schema was designed expecting exactly this, even though the ERD's list
     * of example values for `user_verifications.type` predates it.
     */
    case DrivingLicence = 'driving_licence';
}
