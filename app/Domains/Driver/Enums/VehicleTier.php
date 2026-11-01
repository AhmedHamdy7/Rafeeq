<?php

namespace App\Domains\Driver\Enums;

/**
 * Which km rate a car is priced at (PROF-26, PAY-42).
 *
 * 🔒 Set by an admin at car verification, never by the driver: the tier is what moves a seat from
 * 7.5 to 10 EGP a kilometre, so a driver who could pick it would be setting his own price — the
 * one thing D-0016 takes away from him.
 *
 * Comfort is a badge as well as a rate. Passengers see it on the car in search and can filter on
 * it (BOOK-02), because they are the ones paying the difference.
 */
enum VehicleTier: string
{
    case Standard = 'standard';
    case Comfort = 'comfort';
}
