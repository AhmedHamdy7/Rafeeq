<?php

namespace App\Domains\Commute\Enums;

enum CommuteRuleKey: string
{
    case NonSmoking = 'nonsmoking';
    case Quiet = 'quiet';
    case Ac = 'ac';
    case NoFood = 'nofood';
    case Luggage = 'luggage';
    case Front = 'front';
}
