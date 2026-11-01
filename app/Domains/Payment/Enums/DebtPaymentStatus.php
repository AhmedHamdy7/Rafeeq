<?php

namespace App\Domains\Payment\Enums;

/**
 * An InstaPay payment a member says she made, and what staff found (PAY-09, ADM-03).
 *
 * Nothing is taken off a debt until an admin has seen the money arrive: the member's word and a
 * screenshot are what start the check, not what end it.
 */
enum DebtPaymentStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
