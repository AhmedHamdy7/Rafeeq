<?php

namespace App\Domains\Trip\Enums;

/**
 * What became of the fee a warning carries. See the migration that added it.
 */
enum WarningFeeStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Charged = 'charged';
    case Waived = 'waived';
    case Reversed = 'reversed';
}
