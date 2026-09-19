<?php

namespace App\Domains\Verification\Enums;

enum DocumentKind: string
{
    case NationalIdFront = 'national_id_front';
    case NationalIdBack = 'national_id_back';
    case Selfie = 'selfie';
    case LicenceFront = 'licence_front';
    case LicenceBack = 'licence_back';
    case Badge = 'badge';
}
