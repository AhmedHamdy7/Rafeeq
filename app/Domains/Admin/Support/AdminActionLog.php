<?php

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * 🔒 One writer for `admin_actions`, the immutable record of what staff did.
 *
 * Chapter 12 §Security lists "immutable audit logs" and "sensitive actions require
 * confirmation and are audited" as requirements, and the table is INSERT-only (in
 * production the app's grant on it is SELECT+INSERT). This class is where that stops
 * being a comment: every decision an admin makes about somebody else's account goes
 * through here, with the same shape, or it is not recorded at all.
 *
 * Why a single writer rather than `AdminAction::create()` at each call site: the three
 * things most easily forgotten are the reason, the request context, and the before
 * value. A reviewer approving a driver and a reviewer suspending an account should
 * produce rows a human can read side by side two years later, and that only holds if
 * one place decides the shape.
 *
 * What is deliberately NOT stored: the document itself, a national ID, a phone number,
 * a token. An audit row says who did what to which record and why. A row that also
 * carried the evidence would turn the audit trail into a second copy of the most
 * sensitive data on the platform, retained for 24 months, readable by everyone who can
 * read the log.
 */
final class AdminActionLog
{
    /**
     * Actions that change somebody's standing on the platform and therefore cannot be
     * recorded without a reason. The list is here rather than at the call sites
     * because "which actions are sensitive" is one decision, not twelve.
     */
    private const array REQUIRES_REASON = [
        'verification.request_info',
        'driver.reject',
        'account.suspend',
        'account.reactivate',
        'commute.unpublish',
        'booking.refund',
        // How an emergency ended, and every decision about a report. Months later these
        // rows are read by somebody asking "why was this closed" — sometimes a lawyer.
        'sos.resolve',
        'incident.escalate',
        'incident.resolve',
        'incident.close',
        // A number every member is subject to. "Who changed the OTP lifetime, and why".
        'settings.update',
        'settings.reset',
    ];

    /**
     * @param  string  $action  dotted, entity-first: `verification.approve`
     * @param  array<string, mixed>|null  $before  the fields this changed, as they were
     * @param  array<string, mixed>|null  $after  the same fields, as they now are
     */
    public static function record(
        AdminUser $admin,
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): AdminAction {
        if (in_array($action, self::REQUIRES_REASON, true) && trim((string) $reason) === '') {
            /*
             * Refused rather than recorded with an empty reason. An audit row that
             * says a reviewer suspended an account and not why is worse than no row:
             * it documents that a decision was taken and leaves the only question
             * anybody will ever ask about it unanswerable.
             */
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'reason' => [__('validation.required', ['attribute' => 'reason'])],
            ]);
        }

        return AdminAction::create([
            'admin_id' => $admin->id,
            'action' => $action,
            // The class basename, not the FQCN: `UserVerification`, not a namespace
            // that changes when the code is reorganised and breaks every old row's
            // readability.
            'entity_type' => class_basename($subject),
            'entity_id' => $subject->getKey(),
            'old_value' => $before,
            'new_value' => $after,
            'reason' => $reason,
            /*
             * Chapter 12 §Security: "IP monitoring". Hashed with the same keyed
             * helper the security events use — an admin's home address is not
             * something this table needs in the clear either.
             */
            'ip_hash' => SecurityLog::hashIp(Request::ip()),
            'user_agent_hash' => self::hashUserAgent(Request::userAgent()),
        ]);
    }

    /**
     * The user agent is hashed rather than stored because its only use here is
     * "is this the same browser as last time" — a comparison, not a fact anybody
     * needs to read. Keyed for the same reason the address is.
     */
    private static function hashUserAgent(?string $userAgent): ?string
    {
        return $userAgent === null
            ? null
            : hash_hmac('sha256', $userAgent, (string) config('app.key'));
    }
}
