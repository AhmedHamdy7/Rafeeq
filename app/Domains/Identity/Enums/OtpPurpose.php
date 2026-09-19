<?php

namespace App\Domains\Identity\Enums;

/**
 * Pitfall #31: an OTP verified for one purpose must never authorize another
 * (a login code cannot also authorize a phone-number change).
 */
enum OtpPurpose: string
{
    case Authentication = 'authentication';
    case PinReset = 'pin_reset';
    case PhoneChange = 'phone_change';
    case HighRiskAction = 'high_risk_action';
}
