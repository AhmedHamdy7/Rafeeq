<?php

namespace App\Domains\Booking\Enums;

enum PaymentType: string
{
    case Cash = 'cash';
    case Online = 'online';
}
