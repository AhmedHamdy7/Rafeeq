<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VerificationLog;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer's decision on a driver application (Chapter 3 §10, §11).
 *
 * Every decision writes a `verification_logs` row inside the same transaction
 * as the change it records. That table is append-only and keeps the old and new
 * value, so the audit trail cannot drift from what happened — and cannot be
 * edited afterwards by whoever made the decision.
 *
 * The HTTP/Livewire surface belongs to the admin dashboard (Phase 13); this is
 * the rule about what a decision means, which Phase 4 owns.
 */
final readonly class ReviewDriverApplicationAction
{
    public function approve(DriverProfile $profile, AdminUser $reviewer): DriverProfile
    {
        $this->assertDecidable($profile);

        // §16 story 2: an expired licence blocks approval, not just
        // submission. Time passes while an application sits in the queue.
        if (! $profile->hasValidLicence()) {
            throw DomainException::of(ErrorCode::LicenceExpired);
        }

        return DB::transaction(function () use ($profile, $reviewer): DriverProfile {
            $previous = $profile->status;

            $profile->status = DriverProfileStatus::Approved->value;
            $profile->reviewer_id = $reviewer->id;
            $profile->verified_at = now();
            $profile->rejection_reason = null;
            $profile->save();

            // Approving the driver approves the car they were reviewed with.
            // Leaving it pending would produce an approved driver who still
            // cannot publish anything, with nothing on screen explaining why.
            $profile->vehicles()->get()->each(function (Vehicle $vehicle): void {
                $vehicle->forceFill([
                    'verification_status' => VehicleVerificationStatus::Approved->value,
                ])->save();
            });

            $this->log($profile, $reviewer, VerificationLogAction::Approve, $previous, null);

            SecurityLog::record(SecurityEventType::DriverApproved, $profile->user, metadata: [
                'reviewer_id' => $reviewer->id,
            ]);

            return $profile;
        });
    }

    /**
     * @param  string  $reason  §10 makes this mandatory, and it is shown to the
     *                          applicant verbatim — so it has to say what to change
     */
    public function reject(DriverProfile $profile, AdminUser $reviewer, string $reason): DriverProfile
    {
        $this->assertDecidable($profile);

        if (trim($reason) === '') {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'reason' => [__('validation.required', ['attribute' => 'reason'])],
            ]);
        }

        return DB::transaction(function () use ($profile, $reviewer, $reason): DriverProfile {
            $previous = $profile->status;

            // `rejected`, not a dead end: §16 story 3 has the applicant
            // resubmitting only the document that was wrong, so the state has
            // to be one that reopens editing.
            $profile->status = DriverProfileStatus::Rejected->value;
            $profile->reviewer_id = $reviewer->id;
            $profile->rejection_reason = $reason;
            $profile->save();

            $this->log($profile, $reviewer, VerificationLogAction::Reject, $previous, $reason);

            SecurityLog::record(SecurityEventType::DriverRejected, $profile->user, metadata: [
                'reviewer_id' => $reviewer->id,
            ]);

            return $profile;
        });
    }

    /**
     * Suspending an already-approved driver — a safety decision rather than a
     * verification one, so it applies from any state except draft.
     */
    public function suspend(DriverProfile $profile, AdminUser $reviewer, string $reason): DriverProfile
    {
        return DB::transaction(function () use ($profile, $reviewer, $reason): DriverProfile {
            $previous = $profile->status;

            $profile->status = DriverProfileStatus::Suspended->value;
            $profile->reviewer_id = $reviewer->id;
            $profile->rejection_reason = $reason;
            $profile->save();

            // The vehicles go too: a suspended driver whose car is still
            // approved could otherwise keep carrying passengers through any
            // code path that checks only the vehicle.
            $profile->vehicles()->get()->each(function (Vehicle $vehicle): void {
                $vehicle->forceFill([
                    'verification_status' => VehicleVerificationStatus::Suspended->value,
                    'is_active' => false,
                ])->save();
            });

            $this->log($profile, $reviewer, VerificationLogAction::Suspend, $previous, $reason);

            SecurityLog::record(SecurityEventType::DriverSuspended, $profile->user, metadata: [
                'reviewer_id' => $reviewer->id,
            ]);

            return $profile;
        });
    }

    private function assertDecidable(DriverProfile $profile): void
    {
        if (! DriverApplicationState::isDecidable($profile->status)) {
            throw DomainException::of(ErrorCode::DriverApplicationLocked);
        }
    }

    /**
     * §12: who decided, when, the old value, the new value, and why. Written
     * inside the caller's transaction so a decision and its record cannot
     * exist apart.
     */
    private function log(
        DriverProfile $profile,
        AdminUser $reviewer,
        VerificationLogAction $action,
        DriverProfileStatus $previous,
        ?string $reason,
    ): void {
        VerificationLog::create([
            'entity_type' => 'driver_profile',
            'entity_id' => $profile->user_id,
            'admin_id' => $reviewer->id,
            'action' => $action->value,
            'old_value' => ['status' => $previous->value],
            'new_value' => ['status' => $profile->status->value],
            'reason' => $reason,
        ]);
    }
}
