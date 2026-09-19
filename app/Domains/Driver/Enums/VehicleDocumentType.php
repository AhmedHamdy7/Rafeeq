<?php

namespace App\Domains\Driver\Enums;

enum VehicleDocumentType: string
{
    case Registration = 'registration';
    case Insurance = 'insurance';
    case Inspection = 'inspection';
}
