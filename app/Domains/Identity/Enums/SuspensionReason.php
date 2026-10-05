<?php

namespace App\Domains\Identity\Enums;

/**
 * Why an account is on hold, as the MEMBER is told it.
 *
 * A short closed list rather than the staff note, on purpose. The note is written to the
 * file ("third report about her driving this month, see RF-…") and may name the person who
 * reported them; what the member reads has to be something that can be shown to anybody,
 * including the person a report was about. The app words each of these itself.
 */
enum SuspensionReason: string
{
    // "A safety report is under review" — the prototype's own wording on screen 35.
    case SafetyReport = 'safety_report';
    case IdentityCheck = 'identity_check';
    case PaymentIssue = 'payment_issue';
    case PolicyBreach = 'policy_breach';
}
