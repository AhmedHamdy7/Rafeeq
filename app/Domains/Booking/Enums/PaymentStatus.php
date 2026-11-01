<?php

namespace App\Domains\Booking\Enums;

enum PaymentStatus: string
{
    case NotDue = 'not_due';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

    /**
     * Nothing is owed for this seat: the driver ended the run she was on (PAY-24, PAY-25), or her
     * share of it was within the free part of a breakdown. Never settled by the cash sweep.
     */
    case Waived = 'waived';
}
