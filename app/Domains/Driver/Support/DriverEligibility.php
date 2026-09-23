<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\ProfileSettings;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Support\VerificationCentre;

/**
 * Chapter 3 §2: what "Become a Driver" checks before letting anyone in.
 *
 * The chapter is explicit that a failed check must "explain exactly why and
 * offer the required next step" — so this returns every unmet requirement with
 * the action that clears it, rather than a bare yes/no. A screen that says
 * "you can't do this" without saying what to do is how someone gives up on an
 * application they were eligible to finish.
 *
 * All of them are reported, not just the first: someone missing two things
 * should learn that once, not discover the second after fixing the first.
 */
final class DriverEligibility
{
    /**
     * @return array{eligible: bool, blockers: array<int, array<string, string>>}
     */
    public static function for(User $user): array
    {
        $blockers = [];

        if ($user->account_status !== AccountStatus::Active) {
            $blockers[] = self::blocker('account_not_active', 'CONTACT_SUPPORT');
        }

        if ($user->phone_verified_at === null) {
            $blockers[] = self::blocker('phone_not_verified', 'VERIFY_PHONE');
        }

        if ($user->profile_status !== ProfileStatus::BasicComplete) {
            $blockers[] = self::blocker('profile_incomplete', 'COMPLETE_PROFILE');
        }

        // A date of birth is optional for a passenger and required here: a
        // driving licence has a legal minimum age, so "we don't know" cannot
        // be treated as "old enough".
        if ($user->date_of_birth === null) {
            $blockers[] = self::blocker('date_of_birth_missing', 'COMPLETE_PROFILE');
        } elseif ($user->date_of_birth->age < ProfileSettings::minimumAgeYears()) {
            $blockers[] = self::blocker('below_minimum_age', 'NONE');
        }

        if (VerificationCentre::missingFrom($user, [VerificationType::GovernmentId]) !== []) {
            $blockers[] = self::blocker('identity_not_verified', 'VERIFY_IDENTITY');
        }

        $profile = $user->driverProfile;

        if ($profile !== null) {
            if ($profile->status === DriverProfileStatus::Approved) {
                $blockers[] = self::blocker('already_a_driver', 'OPEN_DRIVER_DASHBOARD');
            }

            // §14: one pending application only. Without this, someone could
            // queue several and have reviewers duplicate each other's work.
            if ($profile->status === DriverProfileStatus::PendingReview) {
                $blockers[] = self::blocker('application_under_review', 'WAIT_FOR_REVIEW');
            }

            if ($profile->status === DriverProfileStatus::Suspended) {
                $blockers[] = self::blocker('driver_suspended', 'CONTACT_SUPPORT');
            }
        }

        return [
            'eligible' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function blocker(string $reason, string $nextStep): array
    {
        return [
            'reason' => $reason,
            // What the app should offer, so the screen never has to map a
            // reason string to a button itself and get it wrong.
            'nextStep' => $nextStep,
            'message' => __('driver.eligibility.'.$reason),
        ];
    }
}
