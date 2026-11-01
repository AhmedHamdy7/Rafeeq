<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A suspended member appealing her hold (AUTH-19, follow-up F1-3).
 *
 * The rule the product gives is "one appeal, and a second with new evidence, within 48 hours":
 *
 * | attempt | allowed when                                         | needs            |
 * |---------|------------------------------------------------------|------------------|
 * | 1       | within 48 h of the hold                              | a message        |
 * | 2       | the first was turned down, within 48 h of that answer | `evidence` too   |
 *
 * The second window runs from the DECISION, not from the hold: staff may take a day to answer the
 * first, and a second chance that closed while she was waiting for it would be no chance at all.
 *
 * 🔒 A permanent ban is not appealed here. It is the one hold that keeps the Account restricted
 * screen, which carries a reference and the support contact instead (AUTH-19).
 *
 * Every refusal is `422` with `fields.appeal`, the same shape as the fee appeal.
 */
final readonly class AppealSuspensionAction
{
    public function execute(User $user, string $message, ?string $evidence): SuspensionAppeal
    {
        return DB::transaction(function () use ($user, $message, $evidence): SuspensionAppeal {
            $suspension = $this->suspensionOf($user);

            if ($suspension->is_permanent) {
                self::refuse('PERMANENT_BAN');
            }

            $appeals = $suspension->appeals()->lockForUpdate()->get();
            $last = $appeals->last();

            if ($last !== null && $last->resolution === null) {
                self::refuse('APPEAL_PENDING');
            }

            if ($appeals->count() >= 2) {
                self::refuse('ALREADY_APPEALED');
            }

            $evidence = $evidence === null ? null : trim($evidence);

            // The second appeal is only for something the first did not have.
            if ($last !== null && ($evidence === null || $evidence === '')) {
                self::refuse('EVIDENCE_REQUIRED');
            }

            if (self::windowClosesAt($suspension, $last)->isPast()) {
                self::refuse('APPEAL_WINDOW_CLOSED');
            }

            $appeal = new SuspensionAppeal;
            $appeal->account_suspension_id = $suspension->id;
            $appeal->user_id = $user->id;
            $appeal->attempt = $appeals->count() + 1;
            $appeal->message = trim($message);
            $appeal->evidence = $evidence === '' ? null : $evidence;
            $appeal->save();

            return $appeal;
        });
    }

    /**
     * When she can no longer file the next appeal: 48 h after the hold for the first, 48 h after
     * the first was turned down for the second. `null` once nothing more can be filed.
     */
    public static function nextWindowClosesAt(AccountSuspension $suspension): ?CarbonInterface
    {
        if ($suspension->is_permanent) {
            return null;
        }

        $appeals = $suspension->appeals;
        $last = $appeals->last();

        if ($appeals->count() >= 2 || ($last !== null && $last->resolution === null)) {
            return null;
        }

        $closes = self::windowClosesAt($suspension, $last);

        return $closes->isPast() ? null : $closes;
    }

    public static function appealHours(): int
    {
        return max(1, (int) PlatformSetting::value(
            'admin.suspension_appeal_hours',
            config('rafeeq.admin.suspension_appeal_hours'),
        ));
    }

    private static function windowClosesAt(AccountSuspension $suspension, ?SuspensionAppeal $last): CarbonInterface
    {
        $from = $last === null ? $suspension->suspended_at : ($last->resolved_at ?? $last->created_at);

        return $from->copy()->addHours(self::appealHours());
    }

    /**
     * Her hold in force. A member who is not suspended has nothing to appeal, and the answer is
     * the same 404 whatever else is true about her.
     */
    private function suspensionOf(User $user): AccountSuspension
    {
        if ($user->account_status !== AccountStatus::Suspended) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return AccountSuspension::query()
            ->where('user_id', $user->id)
            ->inForce()
            ->latest('suspended_at')
            ->lockForUpdate()
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private static function refuse(string $reason): never
    {
        throw DomainException::of(ErrorCode::ValidationFailed, fields: ['appeal' => [$reason]]);
    }
}
