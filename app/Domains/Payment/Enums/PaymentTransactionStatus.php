<?php

namespace App\Domains\Payment\Enums;

enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case SettledOffline = 'settled_offline';
}
