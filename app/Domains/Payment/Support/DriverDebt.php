<?php

namespace App\Domains\Payment\Support;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * The debt cap (Bible §8.1): a driver who owes more than the limit in fees from cash trips may not
 * publish — publishing or resuming a commute is refused. Existing bookings continue, so this is
 * NOT checked when a run starts: the passengers on it did nothing wrong.
 *
 * Compared against the cap in force NOW rather than the stored flag, so staff raising or lowering
 * the limit takes effect at once for everybody.
 */
final class DriverDebt
{
    public static function assertMayPublish(DriverProfile $profile): void
    {
        $outstanding = (int) DriverBalance::query()->whereKey($profile->user_id)->value('outstanding_fee_piastres');
        $cap = PaymentSettings::maxDriverDebtPiastres();

        if ($outstanding > $cap) {
            throw DomainException::of(ErrorCode::DriverDebtLimitReached, fields: [
                'outstandingPiastres' => [(string) $outstanding],
                'capPiastres' => [(string) $cap],
            ]);
        }
    }
}
