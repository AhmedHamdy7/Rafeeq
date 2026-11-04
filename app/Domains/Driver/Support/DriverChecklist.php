<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Carbon\CarbonInterface;
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
     *     actionItem: array{key: string, status: string, rejectionReason: string|null}|null,
     *     suspensionReason: string|null,
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
            self::fromVerification('driving_licence', $verifications->get(VerificationType::DrivingLicence->value), self::licenceExpiry($verifications, $profile)),
            self::vehicleRegistration($vehicle, $profile),
            self::vehicleDetails($vehicle, $profile),
        ];

        $completed = count(array_filter($items, fn (array $item): bool => $item['status'] === 'approved'));

        return [
            'overall' => self::overall($profile, $items, $verifications),
            'completed' => $completed,
            'of' => count($items),
            'items' => $items,
            'licenceExpiresAt' => self::licenceExpiry($verifications, $profile)?->toIso8601String(),
            'actionItem' => self::actionItem($items, $profile),
            'suspensionReason' => $profile?->status === DriverProfileStatus::Suspended ? $profile->rejection_reason : null,
        ];
    }

    /**
     * @return array{
     *     overall: string,
     *     completed: int,
     *     of: int,
     *     licenceExpiresAt: string|null,
     *     actionItem: array{key: string, status: string, rejectionReason: string|null}|null,
     *     suspensionReason: string|null,
     * }
     */
    public static function summary(User $user): array
    {
        $checklist = self::for($user);

        return [
            'overall' => $checklist['overall'],
            'completed' => $checklist['completed'],
            'of' => $checklist['of'],
            'licenceExpiresAt' => $checklist['licenceExpiresAt'],
            'actionItem' => $checklist['actionItem'],
            'suspensionReason' => $checklist['suspensionReason'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{key: string, status: string, rejectionReason: string|null}|null
     */
    private static function actionItem(array $items, ?DriverProfile $profile): ?array
    {
        if ($profile?->status === DriverProfileStatus::Suspended) {
            return null;
        }

        $item = collect($items)->firstWhere('status', 'action_needed')
            ?? collect($items)->firstWhere('status', 'expired');

        if ($item !== null) {
            return [
                'key' => (string) $item['key'],
                'status' => (string) $item['status'],
                'rejectionReason' => $item['rejectionReason'],
            ];
        }

        if ($profile?->status === DriverProfileStatus::Rejected) {
            return [
                'key' => 'application',
                'status' => 'action_needed',
                'rejectionReason' => $profile->rejection_reason,
            ];
        }

        return null;
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
    private static function fromVerification(string $key, ?UserVerification $verification, ?CarbonInterface $expiry = null): array
    {
        $expiry ??= $verification?->expires_at;
        $expired = $expiry !== null && $expiry->isPast();

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
            'expiresAt' => $expiry?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function vehicleRegistration(?Vehicle $vehicle, ?DriverProfile $profile): array
    {
        // F2-4: the single legacy image, or the two sides. One side alone is a start, not a finish.
        $papers = $vehicle?->documents->filter(fn ($doc) => $doc->type->isRegistration()) ?? collect();
        $types = $papers->map(fn ($doc) => $doc->type)->all();
        $complete = in_array(VehicleDocumentType::Registration, $types, true)
            || (in_array(VehicleDocumentType::RegistrationFront, $types, true) && in_array(VehicleDocumentType::RegistrationBack, $types, true));

        $document = $papers->sortBy(fn ($doc) => $doc->expires_at)->first();

        $expired = $document?->expires_at !== null && $document->expires_at->isPast();

        return [
            'key' => 'vehicle_registration',
            'status' => match (true) {
                $vehicle === null || $document === null => 'not_started',
                ! $complete => 'in_progress',
                $expired => 'expired',
                // A vehicle document is approved with the vehicle it belongs to: the reviewer
                // decides on the car, not on each page of its paperwork separately.
                default => self::carStatus($vehicle, $profile),
            },
            'rejectionReason' => self::carRejection($vehicle, $profile),
            'expiresAt' => $document?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Item 5 is the car's own details. "Not started" means she has not added a vehicle at all;
     * everything else follows the vehicle's own verification.
     *
     * @return array<string, mixed>
     */
    private static function vehicleDetails(?Vehicle $vehicle, ?DriverProfile $profile): array
    {
        return [
            'key' => 'vehicle_details',
            'status' => match (true) {
                $vehicle === null => 'not_started',
                default => self::carStatus($vehicle, $profile),
            },
            'rejectionReason' => self::carRejection($vehicle, $profile),
            'expiresAt' => null,
        ];
    }

    private static function carStatus(Vehicle $vehicle, ?DriverProfile $profile): string
    {
        return match (true) {
            $vehicle->verification_status === VehicleVerificationStatus::Approved => 'approved',
            $vehicle->verification_status === VehicleVerificationStatus::Rejected,
            $vehicle->verification_status === VehicleVerificationStatus::Suspended => 'action_needed',
            in_array($profile?->status, [DriverProfileStatus::PendingReview, DriverProfileStatus::Approved], true) => 'under_review',
            default => 'in_progress',
        };
    }

    private static function carRejection(?Vehicle $vehicle, ?DriverProfile $profile): ?string
    {
        return $vehicle?->verification_status === VehicleVerificationStatus::Rejected
            ? $profile?->rejection_reason
            : null;
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
    private static function overall(?DriverProfile $profile, array $items, $verifications): string
    {
        $status = $profile?->status;

        if ($status === DriverProfileStatus::Suspended) {
            return 'suspended';
        }

        $statuses = array_column($items, 'status');

        if (in_array('expired', $statuses, true) || $status === DriverProfileStatus::ExpiredDocuments) {
            return 'renewal_required';
        }

        if (in_array('action_needed', $statuses, true) || $status === DriverProfileStatus::Rejected) {
            return 'action_required';
        }

        if ($status === DriverProfileStatus::Approved) {
            return self::licenceExpiringSoon($verifications, $profile) ? 'expiring' : 'verified';
        }

        if ($status === DriverProfileStatus::PendingReview) {
            return 'under_review';
        }

        return array_intersect(['approved', 'in_progress', 'under_review'], $statuses) !== []
            ? 'in_progress'
            : 'not_started';
    }

    /**
     * @param  Collection<string, UserVerification>  $verifications
     */
    private static function licenceExpiringSoon($verifications, ?DriverProfile $profile): bool
    {
        $expiry = self::licenceExpiry($verifications, $profile);

        if ($expiry === null) {
            return false;
        }

        $days = (int) config('rafeeq.verification.licence_expiry_warning_days', 30);

        return $expiry->isBetween(now(), now()->addDays($days));
    }

    /**
     * @param  Collection<string, UserVerification>  $verifications
     */
    private static function licenceExpiry($verifications, ?DriverProfile $profile): ?CarbonInterface
    {
        $licence = $verifications->get(VerificationType::DrivingLicence->value);

        return $licence?->expires_at
            ?? ($licence?->status === VerificationStatus::Approved ? $profile?->licence_expiry : null);
    }
}
