<?php

namespace App\Domains\Safety\Enums;

/**
 * What a report is about (Chapter 10, SAFE-08; Feature 03 files X-7 added the last four).
 *
 * What the newer ones do: `dropped_early` is opened when she answers "no" to "Did you ask to get off
 * here?" (F-9, AnswerEarlyDropAction); `women_only_misuse` and `payment` have money outcomes staff
 * apply when resolving (F-11, F-12, SettleIncidentMoneyAction); `false_arrival` puts her no-show on
 * that booking under appeal while it is reviewed (TRIP-17, ReportIncidentAction).
 */
enum IncidentCategory: string
{
    case Harassment = 'harassment';
    case UnsafeDriving = 'unsafe_driving';
    case IdentityMismatch = 'identity_mismatch';
    case Payment = 'payment';
    case NoShow = 'no_show';
    case LostItem = 'lost_item';
    case Other = 'other';

    /** The driver said she had arrived at the pickup, and she was not there (TRIP-17). */
    case FalseArrival = 'false_arrival';

    /** The passenger was put out of the car before her drop-off (F-9). */
    case DroppedEarly = 'dropped_early';

    /** A women-only commute used by, or offered to, somebody it is not for (F-12). */
    case WomenOnlyMisuse = 'women_only_misuse';

    /** A rating or its comment that is abusive rather than an opinion of the journey. */
    case AbusiveReview = 'abusive_review';

    /**
     * "Safety concern" — the first reason on the report form (HISTORY-9).
     *
     * 🔴 Its own category rather than `other`, because the app was sending `other` for it and the
     * queue ranks `other` medium: a passenger who picks "safety" means something a person should
     * read the same morning, and the form's wording must not quietly put it behind that.
     */
    case SafetyConcern = 'safety_concern';
}
