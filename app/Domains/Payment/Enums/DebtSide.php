<?php

namespace App\Domains\Payment\Enums;

/**
 * Which half of a person's money a ledger line belongs to.
 *
 * 🔴 One person can be both a driver and a passenger, and the two debts follow different rules:
 * a driver's commission is settled weekly with no cap (PAY-11, PAY-15), while a passenger is
 * suspended at 14 days or 200 EGP (PAY-17). Mixing them would suspend a driver as a passenger for
 * an ordinary week of commission, so every line says which side it is on, and each rule reads only
 * its own side.
 */
enum DebtSide: string
{
    case Driver = 'driver';
    case Passenger = 'passenger';
}
