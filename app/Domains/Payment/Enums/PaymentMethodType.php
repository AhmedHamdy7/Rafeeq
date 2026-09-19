<?php

namespace App\Domains\Payment\Enums;

enum PaymentMethodType: string
{
    case Card = 'card';
    case Wallet = 'wallet';
    case Instapay = 'instapay';
}
