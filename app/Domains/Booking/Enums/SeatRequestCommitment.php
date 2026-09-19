<?php

namespace App\Domains\Booking\Enums;

enum SeatRequestCommitment: string
{
    case Trial = 'trial';
    case Recurring = 'recurring';
}
