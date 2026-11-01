<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * A driver appealing one of his fees, with his evidence in words (SAFE-11; PAY-23 → PAY-29).
 *
 * His own fee lines only, once each, within 48 hours of the fee appearing. A passenger's fees are
 * appealed on their warning (`POST /account/warnings/{warning}/dispute`). While it waits, no
 * suspension for debt touches him (PAY-19).
 */
final readonly class AppealFeeAction
{
    public function execute(User $user, string $entryId, string $reason): FeeAppeal
    {
        $entry = DebtEntry::query()
            ->whereKey($entryId)
            ->where('user_id', $user->id)
            ->where('side', DebtSide::Driver->value)
            ->where('type', DebtEntryType::Fee->value)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if (FeeAppeal::query()->where('debt_entry_id', $entry->id)->exists()) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['appeal' => ['ALREADY_APPEALED']]);
        }

        if ($entry->created_at->addHours(FeeSettings::appealHours())->isPast()) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['appeal' => ['APPEAL_WINDOW_CLOSED']]);
        }

        $appeal = new FeeAppeal;
        $appeal->debt_entry_id = $entry->id;
        $appeal->user_id = $user->id;
        $appeal->reason = $reason;
        $appeal->save();

        return $appeal;
    }
}
