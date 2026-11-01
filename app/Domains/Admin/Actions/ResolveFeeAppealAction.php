<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\DisputeResolution;
use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\DB;

/**
 * Staff deciding a passenger's appeal against a warning or its fee (SAFE-11, PAY-10).
 *
 * - **Upheld** (the warning stands) → nothing moves.
 * - **Overturned** → the warning stops counting on her ladder, and its fee goes: never charged if it
 *   was still pending (`waived`), credited back if it was (`reversed`) — which takes it off what
 *   she owes, or leaves a credit for her next ride if she had already paid (PAY-10).
 *
 * Decided once; audited with the reason.
 */
final readonly class ResolveFeeAppealAction
{
    public function execute(MemberWarning $warning, AdminUser $admin, bool $overturn, string $note): MemberWarning
    {
        return DB::transaction(function () use ($warning, $admin, $overturn, $note): MemberWarning {
            $warning = MemberWarning::query()->whereKey($warning->getKey())->lockForUpdate()->firstOrFail();

            if ($warning->disputed_at === null || $warning->dispute_resolution !== null) {
                throw DomainException::of(ErrorCode::FeeAppealNotOpen);
            }

            $resolution = $overturn ? DisputeResolution::Overturned : DisputeResolution::Upheld;
            $feeBefore = $warning->fee_status;

            $warning->forceFill([
                'dispute_resolution' => $resolution,
                'dispute_resolved_by' => $admin->id,
                'dispute_resolved_at' => now(),
            ])->save();

            if ($overturn && $warning->fee_status === WarningFeeStatus::Pending) {
                $warning->forceFill(['fee_status' => WarningFeeStatus::Waived])->save();
            }

            if ($overturn && $warning->fee_status === WarningFeeStatus::Charged) {
                DebtLedger::credit(
                    User::query()->findOrFail($warning->user_id),
                    DebtEntryType::Credit,
                    (int) $warning->fee_piastres,
                    'appeal_upheld',
                    meta: ['rule' => 'PAY-10', 'warningId' => (string) $warning->getKey()],
                    side: DebtSide::Passenger,
                    idempotencyKey: "warning_fee_reversal:{$warning->getKey()}",
                );

                $warning->forceFill(['fee_status' => WarningFeeStatus::Reversed])->save();
            }

            AdminActionLog::record($admin, 'fee.appeal_decide', $warning,
                before: ['fee_status' => $feeBefore->value],
                after: ['dispute_resolution' => $resolution->value, 'fee_status' => $warning->fee_status->value],
                reason: $note,
            );

            return $warning;
        });
    }
}
