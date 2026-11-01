<?php

namespace App\Domains\Driver\Enums;

/**
 * The car's gearbox (follow-up F2-5). Two values, the two the car form offers.
 *
 * Was free text until the product settled the set: the ERD column had no documented values, so
 * the request accepted anything up to 20 characters and the app had nothing to switch on.
 */
enum Transmission: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
