<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\MemberWarning;

/**
 * Whether somebody has a dispute still open — the one thing that always stops a debt suspension
 * (PAY-19: "for drivers and passengers, suspension for debt is always the last step, and never
 * while a dispute is open").
 *
 * Each counts until staff decide it: an appeal against a fee (SAFE-11 — a passenger's on its
 * warning, a driver's on the fee line), and an attendance dispute ("I was not in that car") on a
 * booking where she is either the passenger or the driver. Given a side, only the disputes about
 * that side's debt count: her passenger debt is not held by a dispute on a run she drove.
 *
 * Payment disputes (PAY-30 → PAY-32) are reports in review; when their outcomes are built they are
 * added here too.
 */
final class OpenDisputes
{
    public static function existFor(string $userId, ?DebtSide $side = null): bool
    {
        return self::listFor($userId, $side) !== [];
    }

    /**
     * @return list<array{type: string, id: string, bookingId: string|null}>
     */
    public static function listFor(string $userId, ?DebtSide $side = null): array
    {
        $open = [];

        if ($side !== DebtSide::Driver) {
            MemberWarning::query()
                ->where('user_id', $userId)
                ->whereNotNull('disputed_at')
                ->whereNull('dispute_resolution')
                ->get(['id', 'booking_id'])
                ->each(function (MemberWarning $warning) use (&$open): void {
                    $open[] = ['type' => 'warning', 'id' => (string) $warning->id, 'bookingId' => $warning->booking_id];
                });
        }

        FeeAppeal::query()
            ->where('fee_appeals.user_id', $userId)
            ->whereNull('fee_appeals.resolution')
            ->when($side !== null, fn ($query) => $query->whereHas(
                'debtEntry',
                fn ($entry) => $entry->where('side', $side->value),
            ))
            ->get(['id'])
            ->each(function (FeeAppeal $appeal) use (&$open): void {
                $open[] = ['type' => 'fee_appeal', 'id' => (string) $appeal->id, 'bookingId' => null];
            });

        Attendance::query()
            ->whereNotNull('disputed_at')
            ->whereNull('dispute_resolution')
            ->whereIn('booking_id', Booking::query()
                ->where(fn ($query) => $query
                    ->when($side !== DebtSide::Driver, fn ($q) => $q->orWhere('passenger_user_id', $userId))
                    ->when($side !== DebtSide::Passenger, fn ($q) => $q->orWhere('driver_profile_id', $userId)))
                ->select('id'))
            ->get(['booking_id'])
            ->each(function (Attendance $attendance) use (&$open): void {
                $open[] = ['type' => 'attendance', 'id' => (string) $attendance->booking_id, 'bookingId' => (string) $attendance->booking_id];
            });

        return $open;
    }
}
