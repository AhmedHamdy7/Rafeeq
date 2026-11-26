<?php

namespace App\Domains\Driver\Enums;

/**
 * The button "Become a Driver" offers for each unmet requirement — `blockers[].nextStep` on
 * GET /v1/driver/eligibility (see DriverEligibility).
 *
 * Not the sign-in `nextStep` (Identity's NextStep enum): this one only ever says how to clear a
 * blocker. An enum rather than strings at each call site so the mobile guide's check can tell a
 * real value from an invented one.
 */
enum DriverBlockerNextStep: string
{
    case ContactSupport = 'CONTACT_SUPPORT';
    case VerifyPhone = 'VERIFY_PHONE';
    case CompleteProfile = 'COMPLETE_PROFILE';
    case OpenDriverDashboard = 'OPEN_DRIVER_DASHBOARD';
    case WaitForReview = 'WAIT_FOR_REVIEW';
    // Nothing the person can do in the app (below the minimum age).
    case None = 'NONE';
}
