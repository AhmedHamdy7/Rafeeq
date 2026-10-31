<?php

namespace App\Domains\Matching\Enums;

enum CommuteDemandStatus: string
{
    case Active = 'active';
    case Matched = 'matched';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * She switched it off for now (Feature 03 S-4, BOOK-06: "can edit, pause or delete each one").
     *
     * 🔴 Every matcher reads `active` only, so a paused demand neither matches nor notifies — with
     * no second check to forget. It does not expire while paused either: the expiry sweep also
     * reads `active` only, and unpausing gives it a fresh expiry, because a demand she deliberately
     * kept is one she still wants.
     */
    case Paused = 'paused';
}
