<?php

namespace App\Domains\Verification\Enums;

enum VerificationType: string
{
    case Phone = 'phone';
    case GovernmentId = 'government_id';
    case Selfie = 'selfie';
    case Organization = 'organization';
}
