<?php

namespace App\Domains\Verification\Support;

use App\Domains\Verification\Enums\DocumentKind;
use App\Domains\Verification\Enums\VerificationMethod;
use App\Domains\Verification\Enums\VerificationType;

/**
 * What each of the four trust levels actually requires.
 *
 * One place, because three different things have to agree about it: the
 * Verification Centre showing progress, the upload endpoint deciding whether
 * a document belongs to the level being worked on, and the gate refusing an
 * action until a level is reached. If they disagreed, a person could be shown
 * "complete" and still be blocked.
 *
 * `licence_front`/`licence_back` are absent on purpose: a driving licence
 * backs a DRIVER profile (Chapter 3 / Phase 4), not a trust level.
 */
final class VerificationRequirements
{
    /**
     * The four levels, in the order the Verification Centre lists them. Order
     * is deliberate: each step asks for more than the one before, so someone
     * abandoning halfway has still given the cheapest evidence first.
     *
     * @return array<int, VerificationType>
     */
    public static function levels(): array
    {
        return [
            VerificationType::Phone,
            VerificationType::GovernmentId,
            VerificationType::Selfie,
            VerificationType::Organization,
        ];
    }

    public static function levelCount(): int
    {
        return count(self::levels());
    }

    /**
     * Documents that must be uploaded and scanned clean before this level can
     * be submitted for review. An empty list means the level is proven some
     * other way — a phone by OTP, an organization by its email domain.
     *
     * @return array<int, DocumentKind>
     */
    public static function documentsFor(VerificationType $type): array
    {
        return match ($type) {
            VerificationType::Phone => [],
            VerificationType::GovernmentId => [DocumentKind::NationalIdFront, DocumentKind::NationalIdBack],
            VerificationType::Selfie => [DocumentKind::Selfie],
            // A work or university badge, for someone whose organization has
            // no verified email domain to check against.
            VerificationType::Organization => [DocumentKind::Badge],
            VerificationType::DrivingLicence => [DocumentKind::LicenceFront, DocumentKind::LicenceBack],
        };
    }

    public static function methodFor(VerificationType $type): VerificationMethod
    {
        return match ($type) {
            VerificationType::Phone => VerificationMethod::Otp,
            VerificationType::GovernmentId,
            VerificationType::Selfie,
            VerificationType::DrivingLicence => VerificationMethod::OcrReview,
            VerificationType::Organization => VerificationMethod::BadgeReview,
        };
    }

    /**
     * Whether reaching this level needs a human reviewer. The two that do are
     * the two that carry a real identity claim; the others are proven by a
     * mechanism the server can check itself.
     */
    public static function needsReview(VerificationType $type): bool
    {
        return self::documentsFor($type) !== [];
    }

    public static function accepts(VerificationType $type, DocumentKind $kind): bool
    {
        return in_array($kind, self::documentsFor($type), true);
    }
}
