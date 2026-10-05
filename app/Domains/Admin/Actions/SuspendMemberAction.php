<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Putting a member's account on hold, and lifting it (Chapter 12 §User Management).
 *
 * 🔴 What a hold does is decided elsewhere and was already in place: `account.active` refuses
 * every booking, request, publish and trip action with `ACCOUNT_SUSPENDED`, while the routes
 * a person in trouble needs — SOS, reports, emergency contacts, signing out — stay open. What
 * this adds is the act itself, and the two things screen 35 promises the member: a reference
 * to quote, and when somebody will look again.
 *
 * What it deliberately does NOT do:
 *
 * - **Sign them out.** The restricted screen is shown TO a signed-in person ("your current
 *   group can still see your attendance"); revoking the session would hide the reason and
 *   bounce them to the phone screen, which is the very thing the middleware was written to
 *   avoid.
 * - **Cancel a suspended driver's booked days.** Who is refunded, and whether it counts
 *   against anyone, is the open single-day-cancellation decision. The dashboard shows staff
 *   how many booked days a hold strands before they confirm it, so the consequence is never
 *   a surprise — but the cancelling waits for that decision.
 *
 * Both steps refuse rather than repeat: a second "suspend" would mint a second case number
 * for the same hold, and the member would be quoting one the team cannot find.
 */
final readonly class SuspendMemberAction
{
    public function suspend(
        User $member,
        AdminUser $admin,
        SuspensionReason $reason,
        string $note,
        ?Incident $incident = null,
    ): AccountSuspension {
        return DB::transaction(function () use ($member, $admin, $reason, $note, $incident): AccountSuspension {
            $member = $this->locked($member);

            if ($member->account_status !== AccountStatus::Active) {
                throw DomainException::of(ErrorCode::MemberNotActive);
            }

            // A report can only justify a hold on the person it is about.
            if ($incident !== null && $incident->reported_user_id !== $member->id) {
                throw DomainException::of(ErrorCode::NotFound);
            }

            $suspension = new AccountSuspension;
            $suspension->user_id = $member->id;
            $suspension->case_number = self::newCaseNumber();
            $suspension->reason_code = $reason;
            $suspension->note = $note;
            $suspension->incident_id = $incident?->id;
            $suspension->suspended_by_admin_id = $admin->id;
            $suspension->suspended_at = now();
            $suspension->review_due_at = now()->addHours(self::reviewHours());
            $suspension->save();

            // Not fillable on User: an account's standing is never submitted.
            $member->account_status = AccountStatus::Suspended;
            $member->suspension_reason = $reason->value;
            $member->save();

            AdminActionLog::record(
                $admin,
                'account.suspend',
                $member,
                before: ['account_status' => AccountStatus::Active->value],
                after: [
                    'account_status' => AccountStatus::Suspended->value,
                    'case_number' => $suspension->case_number,
                    'reason_code' => $reason->value,
                    'incident_id' => $incident?->id,
                ],
                reason: $note,
            );

            return $suspension;
        });
    }

    public function reinstate(User $member, AdminUser $admin, string $note): User
    {
        return DB::transaction(function () use ($member, $admin, $note): User {
            $member = $this->locked($member);

            if ($member->account_status !== AccountStatus::Suspended) {
                throw DomainException::of(ErrorCode::MemberNotSuspended);
            }

            $suspension = AccountSuspension::query()
                ->where('user_id', $member->id)
                ->inForce()
                ->lockForUpdate()
                ->latest('suspended_at')
                ->first();

            /*
             * `null` is possible for an account suspended before this table existed (by hand,
             * in a console). It is still lifted — leaving somebody locked out because their
             * hold predates the paperwork would punish them for our missing record.
             */
            $suspension?->forceFill([
                'lifted_at' => now(),
                'lifted_by_admin_id' => $admin->id,
                'lift_note' => $note,
            ])->save();

            $member->account_status = AccountStatus::Active;
            $member->suspension_reason = null;
            $member->save();

            AdminActionLog::record(
                $admin,
                'account.reactivate',
                $member,
                before: ['account_status' => AccountStatus::Suspended->value, 'case_number' => $suspension?->case_number],
                after: ['account_status' => AccountStatus::Active->value],
                reason: $note,
            );

            return $member;
        });
    }

    /**
     * `RF-` and six random digits — the shape the prototype prints ("#RF-20418"), one digit
     * longer so collisions stay rare as the table grows. Random rather than sequential: a
     * sequence tells anybody holding one how many accounts have been suspended.
     *
     * The unique index is the real guarantee; the loop only spares the caller an exception
     * for the one-in-a-million clash.
     */
    private static function newCaseNumber(): string
    {
        do {
            $number = sprintf('RF-%06d', random_int(0, 999_999));
        } while (AccountSuspension::query()->where('case_number', $number)->exists());

        return $number;
    }

    private static function reviewHours(): int
    {
        return max(1, (int) PlatformSetting::value(
            'admin.suspension_review_hours',
            config('rafeeq.admin.suspension_review_hours'),
        ));
    }

    private function locked(User $member): User
    {
        return User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
    }
}
