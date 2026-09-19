<?php

namespace App\Domains\Payment\Enums;

enum PaymentTransactionType: string
{
    case Charge = 'charge';
    case Refund = 'refund';
    case PartialRefund = 'partial_refund';
}
