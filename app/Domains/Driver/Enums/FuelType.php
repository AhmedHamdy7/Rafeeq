<?php

namespace App\Domains\Driver\Enums;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case NaturalGas = 'natural_gas';
}
