<?php

namespace App\Domains\Admin\Enums;

/**
 * Every distinct thing a staff member can be allowed to do.
 *
 * Chapter 12 §Security requires a permission check on every request, and §Admin Roles
 * describes the roles in prose ("Operations Admin — approve drivers, manage commutes,
 * handle incidents"). Prose is not enforceable, so it is written out here: a permission
 * per capability, and {@see AdminRole::permissions()} maps the prose onto them.
 *
 * Permissions rather than role checks at the call site, and the distinction is the
 * whole point: `$admin->can('verification.decide')` survives somebody inventing a
 * seventh role, while `$admin->hasRole('verification')` has to be found and edited
 * everywhere the day that happens.
 *
 * 🔴 Reading a verification and DECIDING it are separate permissions on purpose. The
 * queue shows a national ID, a selfie and a licence — the most sensitive data the
 * platform holds. A support agent answering "where is my verification" needs to see
 * that it is pending; they do not need to see the document, and they certainly do not
 * need to approve it.
 */
enum AdminPermission: string
{
    // ---- Verification -------------------------------------------------
    case VerificationView = 'verification.view';
    case VerificationDecide = 'verification.decide';
    case VerificationViewDocument = 'verification.view_document';

    // ---- Drivers and vehicles -----------------------------------------
    case DriverView = 'driver.view';
    case DriverDecide = 'driver.decide';

    // ---- Members -------------------------------------------------------
    case MemberView = 'member.view';
    case MemberSuspend = 'member.suspend';

    // ---- Commutes ------------------------------------------------------
    case CommuteView = 'commute.view';
    case CommuteModerate = 'commute.moderate';

    // ---- Safety --------------------------------------------------------
    case SafetyView = 'safety.view';
    case SafetyResolve = 'safety.resolve';

    // ---- Money ---------------------------------------------------------
    case PaymentView = 'payment.view';
    case PayoutApprove = 'payout.approve';
    case RefundIssue = 'refund.issue';

    // ---- Support -------------------------------------------------------
    case TicketView = 'ticket.view';
    case TicketResolve = 'ticket.resolve';

    // ---- Platform ------------------------------------------------------
    case AuditLogView = 'audit_log.view';
    case SettingsManage = 'settings.manage';
    case AdminManage = 'admin.manage';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
