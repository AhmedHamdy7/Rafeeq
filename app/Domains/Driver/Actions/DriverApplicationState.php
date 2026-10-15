<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * The driver state machine from Chapter 3 §11, in one place.
 *
 * Kept here rather than on the enum because binding standard #12 keeps domain
 * enums free of anything that could grow framework dependencies, and because
 * "may this be edited?" is a rule about the application flow, not a property of
 * the word itself.
 *
 * §11 also requires every transition to be logged, which `ReviewDriverApplicationAction`
 * does by writing `verification_logs` — an append-only table.
 */
final class DriverApplicationState
{
    /**
     * Draft and rejected are the two states the applicant owns. Everything
     * else belongs to a reviewer or to the platform, and an applicant editing
     * underneath a reviewer would make the decision meaningless.
     */
    public static function isEditable(DriverProfileStatus $status): bool
    {
        return $status === DriverProfileStatus::Draft
            || $status === DriverProfileStatus::Rejected
            // Documents lapsed: the fix is to upload current ones, which means
            // editing has to reopen.
            || $status === DriverProfileStatus::ExpiredDocuments;
    }

    public static function isSubmittable(DriverProfileStatus $status): bool
    {
        return self::isEditable($status);
    }

    /**
     * Whether a vehicle may be ADDED — which is a wider question than whether the application may
     * be edited, and the difference is the whole of vehicle replacement (Feature 02 v3).
     *
     * 🔴 An **approved** driver must be able to add one. v3 says "vehicle replacement = new
     * re-verification request", and until this existed she could not: `assertEditable` refuses once
     * approved, so changing her car was impossible through the API — she would have had to be
     * un-approved by staff first, losing her standing to buy a different car.
     *
     * The reviewer-protection reasoning behind `isEditable` does not reach this case. She is not
     * editing an application somebody is reading; she is submitting a NEW vehicle that starts
     * `pending` and gets reviewed on its own. Her existing approved vehicle, her trip history and
     * her commutes are untouched until a reviewer approves the replacement and she activates it.
     *
     * Still excluded: `PendingReview`, where her first application genuinely is under somebody's
     * eye, and the suspended states, where adding a car is not the conversation to be having.
     */
    public static function mayAddVehicle(DriverProfileStatus $status): bool
    {
        return self::isEditable($status) || $status === DriverProfileStatus::Approved;
    }

    public static function assertMayAddVehicle(DriverProfileStatus $status): void
    {
        if (! self::mayAddVehicle($status)) {
            throw DomainException::of(ErrorCode::DriverApplicationLocked);
        }
    }

    /**
     * Withdrawing is what §9 offers instead of editing under review. It only
     * makes sense while a reviewer has not yet decided.
     */
    public static function isWithdrawable(DriverProfileStatus $status): bool
    {
        return $status === DriverProfileStatus::PendingReview;
    }

    public static function isDecidable(DriverProfileStatus $status): bool
    {
        return $status === DriverProfileStatus::PendingReview;
    }

    /**
     * §9: editing is disabled once an application is with a reviewer, unless it
     * is withdrawn first. Otherwise a reviewer could approve details that
     * changed underneath them while they were reading.
     *
     * Every write to an application goes through this, which is why it lives
     * with the state machine rather than on whichever Action happened to need
     * it first.
     */
    public static function assertEditable(DriverProfileStatus $status): void
    {
        if (! self::isEditable($status)) {
            throw DomainException::of(ErrorCode::DriverApplicationLocked);
        }
    }
}
