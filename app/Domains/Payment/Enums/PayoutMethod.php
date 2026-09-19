<?php

namespace App\Domains\Payment\Enums;

enum PayoutMethod: string
{
    case Instapay = 'instapay';
    case Wallet = 'wallet';
    case Bank = 'bank';
}
