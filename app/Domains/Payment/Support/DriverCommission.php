<?php

namespace App\Domains\Payment\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * The Friday deadline, enforced (PAY-12, PAY-15; Feature 03 backend file L-4).
 *
 * A driver whose commission is still unpaid after its Friday may not publish or resume a commute,
 * and may not take NEW riders (approve a request, offer a counter-proposal). Everything already
 * booked carries on for one more week — the passengers on it did nothing wrong — so this is NOT
 * checked when a run starts.
 *
 * 🔴 There is no amount cap any more (PAY-15, decision D-0006): owing 5,000 EGP on Wednesday is
 * fine, owing 5 EGP from last week on Saturday is not. That replaced `DRIVER_DEBT_LIMIT_REACHED`
 * and the 200 EGP `payment.max_driver_debt_piastres`.
 */
final class DriverCommission
{
    public static function assertNotOverdue(User|string $driver): void
    {
        $standing = DebtStanding::of($driver, DebtSide::Driver);

        if (! $standing->isBlocked()) {
            return;
        }

        throw DomainException::of(ErrorCode::DriverCommissionOverdue, fields: [
            'amountOwedPiastres' => [(string) $standing->owedPiastres()],
            'dueAt' => [$standing->dueAt()->utc()->toIso8601String()],
        ]);
    }
}
