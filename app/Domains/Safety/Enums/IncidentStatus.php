<?php

namespace App\Domains\Safety\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
