<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;

/**
 * The safety net under "PAID on drop-off" (PAY-07; Feature 03 backend file L-1).
 *
 * Every journey that ends with her in the car settles itself on the spot
 * ({@see SettleBookingAction}). This sweep catches the ones that did not — a request that died
 * between the drop-off and the settlement, a run closed by a path that predates it — so that no
 * seat somebody travelled in stays unpaid and no commission goes unrecorded.
 *
 * What it settles: a cash booking still `not_due` whose passenger travelled (present or late) and
 * whose journey has ENDED (checked out), with no attendance dispute open. A no-show is never
 * settled here — its money is a fee, not a fare (PAY-20).
 *
 * 🔴 There is no waiting period any more. The two-hour `payment.collection_delay_minutes` went
 * with D-0004.
 *
 * Idempotent like the action it calls, so a missed run is caught up by the next one and a double
 * run settles nothing twice.
 */
final readonly class SettleCashBookingsAction
{
    public function __construct(private SettleBookingAction $settle) {}

    /**
     * @return int how many bookings were settled
     */
    public function execute(): int
    {
        $settled = 0;

        Attendance::query()
            ->whereIn('status', array_map(fn (AttendanceStatus $s) => $s->value, array_filter(
                AttendanceStatus::cases(),
                fn (AttendanceStatus $s) => $s->travelled(),
            )))
            ->whereNotNull('checked_out_at')
            ->whereNull('disputed_at')
            ->whereHas('booking', fn ($booking) => $booking
                ->where('payment_type', PaymentType::Cash->value)
                ->where('payment_status', PaymentStatus::NotDue->value))
            ->with('booking')
            ->chunkById(200, function ($rows) use (&$settled): void {
                foreach ($rows as $attendance) {
                    $settled += $this->settle->execute($attendance->booking) ? 1 : 0;
                }
            }, 'booking_id');

        return $settled;
    }
}
