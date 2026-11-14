<?php

namespace App\Domains\Reliability\Enums;

enum AppealDeclineReason: string
{
    case NotEnoughEvidence = 'not_enough_evidence';
    case EvidenceDoesNotMatch = 'evidence_does_not_match';
    case WithinDriverControl = 'within_member_control';
    case Other = 'other';
}
