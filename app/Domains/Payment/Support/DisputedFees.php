<?php

namespace App\Domains\Payment\Support;

use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Database\Eloquent\Builder;

final class DisputedFees
{
    public static function heldPiastres(string $userId): int
    {
        return (int) self::held()
            ->where('user_id', $userId)
            ->whereIn('booking_id', Attendance::query()
                ->whereNotNull('disputed_at')
                ->whereNull('dispute_resolution')
                ->select('booking_id'))
            ->sum('fee_piastres');
    }

    public static function feeFor(string $bookingId, string $userId): int
    {
        return (int) MemberWarning::query()
            ->where('user_id', $userId)
            ->where('booking_id', $bookingId)
            ->where('kind', WarningKind::NoShow->value)
            ->whereIn('fee_status', [WarningFeeStatus::Charged->value, WarningFeeStatus::Pending->value])
            ->sum('fee_piastres');
    }

    private static function held(): Builder
    {
        return MemberWarning::query()
            ->where('kind', WarningKind::NoShow->value)
            ->where('fee_status', WarningFeeStatus::Charged->value);
    }
}
