<?php

namespace App\Domains\Driver\Enums;

enum DriverProfileStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case ExpiredDocuments = 'expired_documents';
}
