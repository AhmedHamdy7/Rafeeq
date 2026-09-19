<?php

namespace App\Domains\Payment\Enums;

enum DriverFeeLedgerType: string
{
    case FeeDue = 'fee_due';
    case FeeSettled = 'fee_settled';
    case Adjustment = 'adjustment';
    case WriteOff = 'write_off';
}
