<?php

namespace App\Domains\Admin\Enums;

/**
 * The RBAC role names Spatie Permission stores in the `roles` table (Bible
 * §Group 13). Kept as an enum so role names are never hand-typed as strings
 * scattered across Actions/Policies/seeders.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Verification = 'verification';
    case Finance = 'finance';
    case Support = 'support';
    case SafetyLead = 'safety_lead';

    /**
     * What each role may actually do — Chapter 12 §Admin Roles, written as something
     * the framework can enforce instead of something a reviewer has to remember.
     *
     * 🔴 Least privilege, and the two places it bites hardest:
     *
     * - **Support** can see that a verification exists and answer "where is mine",
     *   and cannot open the document or decide it. A support queue does not need to
     *   look at national IDs, and the day one agent's account is phished is the day
     *   that distinction is the only thing standing between an attacker and every
     *   identity document on the platform.
     * - **Finance** touches money and nothing else. Approving payouts is not a reason
     *   to be able to read a member's address or suspend them.
     *
     * The chapter names four roles and the enum has six: `verification` and
     * `safety_lead` were split out of "Operations Admin" because the platform's two
     * most sensitive queues — identity documents and safety cases — should each be
     * grantable on their own rather than as a side effect of being able to manage
     * commutes.
     *
     * @return array<int, AdminPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Everything, including granting roles. Deliberately the only role that
            // can create another admin.
            self::SuperAdmin => AdminPermission::cases(),

            self::Operations => [
                AdminPermission::DriverView,
                AdminPermission::DriverDecide,
                AdminPermission::MemberView,
                AdminPermission::MemberSuspend,
                AdminPermission::CommuteView,
                AdminPermission::CommuteModerate,
                AdminPermission::TripView,
                AdminPermission::TicketView,
                // Can see a verification's state to understand why a driver is stuck,
                // but not open the document or decide it.
                AdminPermission::VerificationView,
            ],

            self::Verification => [
                AdminPermission::VerificationView,
                AdminPermission::VerificationDecide,
                AdminPermission::VerificationViewDocument,
                // Driver approval is a verification decision in practice: the same
                // person reads the licence and the registration.
                AdminPermission::DriverView,
                AdminPermission::DriverDecide,
                AdminPermission::MemberView,
            ],

            self::Finance => [
                AdminPermission::PaymentView,
                AdminPermission::PayoutApprove,
                AdminPermission::RefundIssue,
            ],

            self::Support => [
                AdminPermission::TicketView,
                AdminPermission::TicketResolve,
                AdminPermission::MemberView,
                // State only — not the document, not the decision. See the note above.
                AdminPermission::VerificationView,
            ],

            self::SafetyLead => [
                AdminPermission::SafetyView,
                AdminPermission::SafetyResolve,
                // An SOS arrives attached to a car on the road; the person answering it
                // has to be able to see that car.
                AdminPermission::TripView,
                AdminPermission::MemberView,
                AdminPermission::MemberSuspend,
                AdminPermission::TicketView,
            ],
        };
    }
}
