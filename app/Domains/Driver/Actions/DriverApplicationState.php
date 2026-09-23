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
