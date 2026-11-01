<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\DisputeResolution;
use Illuminate\Support\Facades\DB;

/**
 * Staff deciding a member's appeal against her hold (AUTH-19, follow-up F1-3).
 *
 * - **Upheld** (the hold stands) → nothing moves, and the second-appeal window opens from now.
 * - **Overturned** → the hold is lifted, through the same `reinstate` staff use by hand, so the
 *   lift is recorded, audited and announced exactly once whichever button they pressed.
 *
 * The seats the hold cancelled are not given back: they went to other people on the waiting
 * list, and taking them back would undo somebody else's booking for a decision she had no part in.
 */
final readonly class ResolveSuspensionAppealAction
{
    public function __construct(private SuspendMemberAction $members) {}

    public function execute(SuspensionAppeal $appeal, AdminUser $admin, bool $overturn, string $note): SuspensionAppeal
    {
        return DB::transaction(function () use ($appeal, $admin, $overturn, $note): SuspensionAppeal {
            $appeal = SuspensionAppeal::query()->whereKey($appeal->getKey())->lockForUpdate()->firstOrFail();

            if ($appeal->resolution !== null) {
                throw DomainException::of(ErrorCode::SuspensionAppealNotOpen);
            }

            $resolution = $overturn ? DisputeResolution::Overturned : DisputeResolution::Upheld;

            $appeal->forceFill([
                'resolution' => $resolution,
                'resolved_by_admin_id' => $admin->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            AdminActionLog::record($admin, 'account.suspension_appeal_decide', $appeal,
                after: ['resolution' => $resolution->value, 'attempt' => $appeal->attempt],
                reason: $note,
            );

            $member = User::query()->findOrFail($appeal->user_id);

            if ($overturn && $member->account_status === AccountStatus::Suspended) {
                $this->members->reinstate($member, $admin, $note);
            }

            return $appeal;
        });
    }
}
