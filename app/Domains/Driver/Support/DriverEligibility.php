<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Enums\DriverBlockerNextStep;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\ProfileSettings;
use App\Domains\Shared\Testing\DriverBlocker;

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
            $blockers[] = self::blocker('account_not_active', DriverBlockerNextStep::ContactSupport);
        }

        if ($user->phone_verified_at === null) {
            $blockers[] = self::blocker('phone_not_verified', DriverBlockerNextStep::VerifyPhone);
        }

        if ($user->profile_status !== ProfileStatus::BasicComplete) {
            $blockers[] = self::blocker('profile_incomplete', DriverBlockerNextStep::CompleteProfile);
        }

        // A date of birth is optional for a passenger and required here: a
        // driving licence has a legal minimum age, so "we don't know" cannot
        // be treated as "old enough".
        if ($user->date_of_birth === null) {
            $blockers[] = self::blocker('date_of_birth_missing', DriverBlockerNextStep::CompleteProfile);
        } elseif ($user->date_of_birth->age < ProfileSettings::minimumAgeYears()) {
            $blockers[] = self::blocker('below_minimum_age', DriverBlockerNextStep::None);
        }

        /*
         * 🔴 An unverified ID no longer blocks starting (F2-1): it is one of the five items, uploaded
         * in any order with the others, and approval is where it must be approved (F2-2). The
         * checklist (`GET /driver/verification`) is what shows it as still to do.
         */

        $profile = $user->driverProfile;

        if ($profile !== null) {
            if ($profile->status === DriverProfileStatus::Approved) {
                $blockers[] = self::blocker('already_a_driver', DriverBlockerNextStep::OpenDriverDashboard);
            }

            // §14: one pending application only. Without this, someone could
            // queue several and have reviewers duplicate each other's work.
            if ($profile->status === DriverProfileStatus::PendingReview) {
                $blockers[] = self::blocker('application_under_review', DriverBlockerNextStep::WaitForReview);
            }

            if ($profile->status === DriverProfileStatus::Suspended) {
                $blockers[] = self::blocker('driver_suspended', DriverBlockerNextStep::ContactSupport);
            }
        }

        if (DriverBlocker::isOn($user->id)) {
            $blockers[] = self::blocker('not_eligible', DriverBlockerNextStep::ContactSupport);
        }

        return [
            'eligible' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function blocker(string $reason, DriverBlockerNextStep $nextStep): array
    {
        return [
            'reason' => $reason,
            // What the app should offer, so the screen never has to map a
            // reason string to a button itself and get it wrong.
            'nextStep' => $nextStep->value,
            'message' => __('driver.eligibility.'.$reason),
        ];
    }
}
