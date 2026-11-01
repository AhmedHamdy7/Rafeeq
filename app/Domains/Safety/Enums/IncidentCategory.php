<?php

namespace App\Domains\Safety\Enums;

/**
 * What a report is about (Chapter 10, SAFE-08; Feature 03 files X-7 added the last four).
 *
 * ⚠️ Only the category exists for the four new ones. What each one then DOES belongs to the money
 * rules and is built with them: `false_arrival` freezes the no-show fee while it is reviewed
 * (TRIP-17), `dropped_early` is opened by the platform itself when a passenger is left before her
 * stop (F-9), `women_only_misuse` follows F-12. Until then each is a report a human reads.
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
}
