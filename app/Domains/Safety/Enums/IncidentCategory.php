<?php

namespace App\Domains\Safety\Enums;

enum IncidentCategory: string
{
    case Harassment = 'harassment';
    case UnsafeDriving = 'unsafe_driving';
    case IdentityMismatch = 'identity_mismatch';
    case Payment = 'payment';
    case NoShow = 'no_show';
    case LostItem = 'lost_item';
    case Other = 'other';
}
