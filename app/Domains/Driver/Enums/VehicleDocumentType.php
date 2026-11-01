<?php

namespace App\Domains\Driver\Enums;

enum VehicleDocumentType: string
{
    /*
     * One image of the registration — what every app sent before F2-4. Still accepted, and still
     * enough on its own, until every installed app sends the two sides below.
     */
    case Registration = 'registration';

    // F2-4 (PROF-14): the car licence has two sides, each its own upload.
    case RegistrationFront = 'registration_front';
    case RegistrationBack = 'registration_back';

    // Accepted, never required (F2-15, PROF-13).
    case Insurance = 'insurance';
    case Inspection = 'inspection';

    /** Any of the registration images — the one car paper the five driver items need. */
    public function isRegistration(): bool
    {
        return in_array($this, [self::Registration, self::RegistrationFront, self::RegistrationBack], true);
    }
}
