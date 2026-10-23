<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Collection;

/**
 * The driver's five-item checklist, as the design draws it (Feature 02 v3: "Driver has **five
 * checklist items in any order**: (1) ID front/back, (2) selfie, (3) licence, (4) vehicle
 * registration, (5) vehicle details; **manual admin approval** after all required items").
 *
 * 🔴 A READ MODEL over three tables that already hold all of it, not a new table.
 *
 *   - items 1–3 are `user_verifications` rows (`government_id`, `selfie`, `driving_licence`)
 *   - item 4 is a `vehicle_documents` row of type `registration`
 *   - item 5 is the `vehicles` row itself — make, model, plate, seats
 *
 * `VerificationCentre` is the equivalent for the IDENTITY levels and is deliberately left alone:
 * it answers "what has been checked about this person" across four levels including `phone` and
 * `organization`, which have nothing to do with being allowed to drive. The design shows the two
 * as separate cards — `Level X of 4` in the verification centre, `Level X of 5` on the driver card
 * — so merging them would have produced a count that matched neither screen.
 *
 * What this does NOT do is decide anything. Publishing and starting are gated by
 * `CreateCommuteOfferAction` and `usableVehicle()`, which read the underlying rows directly. This
 * is the screen's view of the same facts, so a bug here shows the wrong progress bar; it cannot let
 * anybody drive.
 */
final class DriverChecklist
{
    /**
     * @return array{
     *     overall: string,
     *     completed: int,
     *     of: int,
     *     items: array<int, array<string, mixed>>,
     *     licenceExpiresAt: string|null,
     * }
     */
    public static function for(User $user): array
    {
        $user->loadMissing(['verifications.documents', 'driverProfile.vehicles.documents']);

        $verifications = $user->verifications->keyBy(
            fn (UserVerification $verification) => $verification->type->value
        );

        $profile = $user->driverProfile;
        $vehicle = $profile?->vehicles->firstWhere('is_active', true) ?? $profile?->vehicles->first();

        $items = [
            self::fromVerification('government_id', $verifications->get(VerificationType::GovernmentId->value)),
            self::fromVerification('selfie', $verifications->get(VerificationType::Selfie->value)),
            self::fromVerification('driving_licence', $verifications->get(VerificationType::DrivingLicence->value)),
            self::vehicleRegistration($vehicle),
            self::vehicleDetails($vehicle),
        ];

        $completed = count(array_filter($items, fn (array $item): bool => $item['status'] === 'approved'));

        return [
            'overall' => self::overall($profile?->status, $items, $verifications),
            'completed' => $completed,
            'of' => count($items),
            'items' => $items,
            'licenceExpiresAt' => self::licenceExpiry($verifications),
        ];
    }

    /**
     * One of the three document levels.
     *
     * 🔴 `rejectionReason` travels with the item, because the design's `action` state names the
     * single document at fault — "Your driving licence was rejected: blurry photo. **Replace only
     * that document**" — and a per-item reason is the only way a screen can say that. A single
     * overall message would make somebody re-upload all five.
     *
     * @return array<string, mixed>
     */
    private static function fromVerification(string $key, ?UserVerification $verification): array
    {
        $expired = $verification?->expires_at !== null && $verification->expires_at->isPast();

        $status = match (true) {
            $verification === null => 'not_started',
            $expired => 'expired',
            $verification->status === VerificationStatus::Approved => 'approved',
            // `Pending` is this enum's word for "submitted, with a reviewer". There is no
            // `UnderReview` case — the design's "In review" chip maps onto this.
            $verification->status === VerificationStatus::Pending => 'under_review',
            $verification->status === VerificationStatus::ActionNeeded => 'action_needed',
            default => 'in_progress',
        };

        return [
            'key' => $key,
            'status' => $status,
            'rejectionReason' => $verification?->rejection_reason,
            'expiresAt' => $verification?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function vehicleRegistration(?Vehicle $vehicle): array
    {
        $document = $vehicle?->documents
            ->firstWhere('type', VehicleDocumentType::Registration);

        $expired = $document?->expires_at !== null && $document->expires_at->isPast();

        return [
            'key' => 'vehicle_registration',
            'status' => match (true) {
                $vehicle === null || $document === null => 'not_started',
                $expired => 'expired',
                // A vehicle document is approved with the vehicle it belongs to: the reviewer
                // decides on the car, not on each page of its paperwork separately.
                $vehicle->verification_status === VehicleVerificationStatus::Approved => 'approved',
                default => 'under_review',
            },
            'rejectionReason' => null,
            'expiresAt' => $document?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Item 5 is the car's own details. "Not started" means she has not added a vehicle at all;
     * everything else follows the vehicle's own verification.
     *
     * @return array<string, mixed>
     */
    private static function vehicleDetails(?Vehicle $vehicle): array
    {
        return [
            'key' => 'vehicle_details',
            'status' => match (true) {
                $vehicle === null => 'not_started',
                $vehicle->verification_status === VehicleVerificationStatus::Approved => 'approved',
                $vehicle->verification_status === VehicleVerificationStatus::Suspended => 'action_needed',
                default => 'under_review',
            },
            /*
             * No per-vehicle reason column exists, and inventing one here would publish an
             * always-null field. A suspension reason lives on the driver profile, because
             * suspending a car is a decision about the driver who drives it.
             */
            'rejectionReason' => null,
            'expiresAt' => null,
        ];
    }

    /**
     * The one word the card's headline is chosen from.
     *
     * The design has six of these, and the order below is their precedence — which matters, because
     * several are true at once for a real driver. A suspended driver whose licence also expired
     * must read "suspended": it is the one a human being decided, and the one that tells her why
     * her commutes stopped.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<string, UserVerification>  $verifications
     */
    private static function overall(?DriverProfileStatus $status, array $items, $verifications): string
    {
        if ($status === DriverProfileStatus::Suspended) {
            return 'suspended';
        }

        $statuses = array_column($items, 'status');

        if (in_array('expired', $statuses, true)) {
            // The design calls this `renewal`: "publishing and driving are paused until your
            // renewed licence is approved".
            return 'renewal_required';
        }

        if (in_array('action_needed', $statuses, true)) {
            return 'action_required';
        }

        if ($status === DriverProfileStatus::Approved) {
            // `expiring` is a sub-state of verified in the design — the chip still reads
            // "Verified" while the headline warns about the date.
            return self::licenceExpiringSoon($verifications) ? 'expiring' : 'verified';
        }

        if ($status === DriverProfileStatus::PendingReview || in_array('under_review', $statuses, true)) {
            return 'under_review';
        }

        return in_array('approved', $statuses, true) || in_array('in_progress', $statuses, true)
            ? 'in_progress'
            : 'not_started';
    }

    /**
     * @param  Collection<string, UserVerification>  $verifications
     */
    private static function licenceExpiringSoon($verifications): bool
    {
        $licence = $verifications->get(VerificationType::DrivingLicence->value);

        if ($licence?->expires_at === null) {
            return false;
        }

        // The window is a platform setting rather than a literal: v3 says renewal deadlines are
        // configurable and "don't assume 30 days".
        $days = (int) config('rafeeq.verification.licence_expiry_warning_days', 30);

        return $licence->expires_at->isBetween(now(), now()->addDays($days));
    }

    /**
     * @param  Collection<string, UserVerification>  $verifications
     */
    private static function licenceExpiry($verifications): ?string
    {
        return $verifications->get(VerificationType::DrivingLicence->value)
            ?->expires_at?->toIso8601String();
    }
}
