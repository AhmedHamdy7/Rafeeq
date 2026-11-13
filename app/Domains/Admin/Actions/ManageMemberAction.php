<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\SupportTicketPriority;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\MemberNote;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtStanding;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The user detail's "More actions" (D16, D17, D18, D22). Each checks its own permission,
 * asks for a reason, and writes one audit row with what it changed.
 *
 * - correct()       D17 · fix name, gender or date of birth. A woman changed to another
 *                    gender has her women-only commutes paused for review (COM-45), the same
 *                    way the app does it when she changes it herself.
 * - writeOff()      D18 · forgive everything owed on one side, as a write_off ledger line.
 * - unlockSignIn()  D22 · lift the sign-in code lock on her number now, not at its expiry.
 * - escalate()      D16 · a support case for an Admin, with the note on the member.
 */
final class ManageMemberAction
{
    /**
     * @param  array{full_name?: string, gender?: string, date_of_birth?: string}  $changes
     */
    public function correct(AdminUser $by, User $user, array $changes, string $reason): User
    {
        $this->authorize($by, AdminPermission::MemberSuspend);

        $before = [];
        $after = [];

        foreach (['full_name', 'gender', 'date_of_birth'] as $field) {
            if (! array_key_exists($field, $changes) || $changes[$field] === null || $changes[$field] === '') {
                continue;
            }

            $old = $user->{$field};
            $old = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old);
            $new = $field === 'gender' ? Gender::from($changes[$field])->value : trim((string) $changes[$field]);

            if ((string) $old !== (string) $new) {
                $before[$field] = $old;
                $after[$field] = $new;
            }
        }

        if ($after === []) {
            throw ValidationException::withMessages(['correct' => __('admin/actions.nothing_changed')]);
        }

        return DB::transaction(function () use ($by, $user, $before, $after, $reason) {
            $wasWoman = $user->gender === Gender::Woman;

            $user->forceFill($after)->save();

            if ($wasWoman && $user->refresh()->gender !== Gender::Woman) {
                app(ChangeCommuteStatusAction::class)->pauseWomenOnlyForReview((string) $user->id);
            }

            AdminActionLog::record($by, 'member.correct', $user, $before, $after, $reason);

            return $user;
        });
    }

    public function writeOff(AdminUser $by, User $user, DebtSide $side, string $reason): int
    {
        $this->authorize($by, AdminPermission::DebtManage);

        $owed = DebtStanding::of($user, $side)->owedPiastres();

        if ($owed === 0) {
            throw ValidationException::withMessages(['debt' => __('admin/actions.nothing_owed')]);
        }

        DB::transaction(function () use ($by, $user, $side, $owed, $reason) {
            DebtLedger::record(
                $user->getKey(),
                $side,
                DebtEntryType::WriteOff,
                -$owed,
                'staff_write_off',
                adminId: $by->id,
                meta: ['note' => $reason],
            );

            AdminActionLog::record($by, 'debt.write_off', $user, ['owed_piastres' => $owed, 'side' => $side->value], ['owed_piastres' => 0], $reason);
        });

        return $owed;
    }

    public function unlockSignIn(AdminUser $by, User $user, string $reason): int
    {
        $this->authorize($by, AdminPermission::MemberSuspend);

        return DB::transaction(function () use ($by, $user, $reason) {
            $lifted = OtpChallenge::query()
                ->where('phone_e164', $user->phone_e164)
                ->where('locked_until', '>', now())
                ->update(['locked_until' => now()]);

            if ($lifted === 0) {
                throw ValidationException::withMessages(['unlock' => __('admin/actions.not_locked')]);
            }

            AdminActionLog::record($by, 'account.unlock_sign_in', $user, null, ['locks_lifted' => $lifted], $reason);

            return $lifted;
        });
    }

    public function escalate(AdminUser $by, User $user, string $note): SupportTicket
    {
        $this->authorize($by, AdminPermission::MemberView);

        return DB::transaction(function () use ($by, $user, $note) {
            $ticket = SupportTicket::query()->create([
                'user_id' => $user->id,
                'category' => 'escalation',
                'priority' => SupportTicketPriority::High,
                'sla_due_at' => CarbonImmutable::now()->addHours(4),
                'related_entity_type' => 'user',
                'related_entity_id' => $user->id,
            ]);

            MemberNote::query()->create(['user_id' => $user->id, 'admin_id' => $by->id, 'body' => __('admin/actions.escalated_note', ['note' => trim($note)])]);

            AdminActionLog::record($by, 'member.escalate', $user, null, ['ticket' => $ticket->id], $note);

            return $ticket;
        });
    }

    /** Whether her number is locked out of sign-in right now. */
    public static function isSignInLocked(User $user): bool
    {
        return OtpChallenge::query()->where('phone_e164', $user->phone_e164)->where('locked_until', '>', now())->exists();
    }

    private function authorize(AdminUser $by, AdminPermission $permission): void
    {
        if (! $by->can($permission->value)) {
            throw new AuthorizationException;
        }
    }
}
