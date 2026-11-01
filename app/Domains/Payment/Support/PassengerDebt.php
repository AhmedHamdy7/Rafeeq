<?php

namespace App\Domains\Payment\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * Booking is blocked while she is suspended for debt (PAY-46): no new request, no waiting-list
 * application, no accepting a seat offer. Her trips stay readable, and Pay and Appeal stay open.
 */
final class PassengerDebt
{
    public static function assertMayBook(User|string $passenger): void
    {
        $standing = DebtStanding::of($passenger, DebtSide::Passenger);

        if ($standing->suspendedAt === null) {
            return;
        }

        throw DomainException::of(ErrorCode::RidingSuspendedForDebt, fields: [
            'amountOwedPiastres' => [(string) $standing->owedPiastres()],
        ]);
    }
}
