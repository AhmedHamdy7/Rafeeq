<?php

namespace App\Domains\Verification\Enums;

enum PublicTrustTier: string
{
    case New = 'new';
    case Trusted = 'trusted';
    case HighlyTrusted = 'highly_trusted';
}
