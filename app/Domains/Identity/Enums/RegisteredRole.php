<?php

namespace App\Domains\Identity\Enums;

/**
 * The intent declared at sign-up. Both capabilities always exist on one
 * account (Bible §1.4) — this only drives onboarding, not authorization.
 */
enum RegisteredRole: string
{
    case Driver = 'driver';
    case Passenger = 'passenger';
    case Both = 'both';
}
