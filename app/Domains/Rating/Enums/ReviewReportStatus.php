<?php

namespace App\Domains\Rating\Enums;

enum ReviewReportStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Upheld = 'upheld';
    case Dismissed = 'dismissed';
}
