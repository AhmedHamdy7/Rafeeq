<?php

namespace App\Domains\Verification\Enums;

enum VerificationMethod: string
{
    case Otp = 'otp';
    case OcrReview = 'ocr_review';
    case EmailDomain = 'email_domain';
    case BadgeReview = 'badge_review';
}
