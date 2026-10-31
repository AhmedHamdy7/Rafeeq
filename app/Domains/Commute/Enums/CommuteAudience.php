<?php

namespace App\Domains\Commute\Enums;

/**
 * 🔒 `women_only`: only a woman may ask for a seat (Feature 03 C-11, BOOK-31).
 *
 * Shown to everybody in search, flagged `womenOnly: true`; anybody whose gender is not `woman` is
 * refused at request time with `SEAT_REQUEST_WOMEN_ONLY`. It is never a score.
 */
enum CommuteAudience: string
{
    case WomenOnly = 'women_only';
    case AnyVerified = 'any_verified';
}
