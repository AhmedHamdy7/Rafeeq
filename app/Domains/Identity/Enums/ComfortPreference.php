<?php

namespace App\Domains\Identity\Enums;

/**
 * One answer to one comfort question (Feature 02 v3: "Comfort & atmosphere").
 *
 * 🔴 Three values, not a boolean, and `Either` is the default rather than a fallback.
 *
 * "No preference" is the honest answer for most people about most of these, and it is genuinely
 * different from "no". A boolean would collapse the two — so somebody who has never opened the
 * screen would read as actively refusing music, and a driver's car would advertise rules she never
 * set. It also matters for whatever matching rule is eventually written: `Either` is the value that
 * must never narrow a search, and it cannot be distinguished later if it was stored as `false`.
 *
 * The same three values serve both modes, and the mode decides what they MEAN: for a passenger
 * `No` is a request, for a driver it is a rule about her own car.
 */
enum ComfortPreference: string
{
    /** Wanted, or offered. For `conversation`, happy to chat. */
    case Yes = 'yes';

    /** Not wanted, or not allowed. */
    case No = 'no';

    /** No preference — the default, and the one value that must never narrow anything. */
    case Either = 'either';
}
