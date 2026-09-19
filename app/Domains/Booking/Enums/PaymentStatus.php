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
}
