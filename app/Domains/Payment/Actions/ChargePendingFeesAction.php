<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Support\DebtNotices;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Trip\Actions\RecordMemberWarningAction;
use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\DB;

/**
 * The late-cancel fee, decided at departure (PAY-21; Feature 03 backend file R-8).
 *
 * A late cancellation is recorded with its fee `pending`. Once the day has departed (or stopped),
 * each one is settled:
 *
 * - **the seat was refilled** — somebody else holds a seat on that day, booked after she let hers
 *   go → **waived**, and she is told it cost nothing;
 * - **the day did not run** (cancelled or expired) → **waived** quietly: nobody was left short;
 * - **otherwise** → **charged** to her ledger.
 *
 * Run by the hourly debt sweep. Safe to repeat: each warning is settled once, under a lock.
 */
final readonly class ChargePendingFeesAction
{
    /**
     * @return array{charged: int, waived: int}
     */
    public function execute(): array
    {
        $done = ['charged' => 0, 'waived' => 0];

        MemberWarning::query()
            ->where('kind', WarningKind::LateCancel->value)
            ->where('fee_status', WarningFeeStatus::Pending->value)
            ->whereHas('booking.scheduledTrip', fn ($trip) => $trip->where('departure_at', '<=', now()))
            ->orderBy('id')
            ->chunkById(200, function ($warnings) use (&$done): void {
                foreach ($warnings as $warning) {
                    $outcome = $this->settle($warning);

                    if ($outcome !== null) {
                        $done[$outcome]++;
                    }
                }
            });

        return $done;
    }

    /**
     * @return 'charged'|'waived'|null
     */
    private function settle(MemberWarning $warning): ?string
    {
        return DB::transaction(function () use ($warning): ?string {
            $warning = MemberWarning::query()->whereKey($warning->getKey())->lockForUpdate()->first();

            if ($warning === null || $warning->fee_status !== WarningFeeStatus::Pending) {
                return null;
            }

            $booking = Booking::query()->with('scheduledTrip')->find($warning->booking_id);
            $trip = $booking?->scheduledTrip;

            if ($booking === null || $trip === null
                || in_array($trip->status, [ScheduledTripStatus::Cancelled, ScheduledTripStatus::Expired], true)) {
                $warning->forceFill(['fee_status' => WarningFeeStatus::Waived])->save();

                return 'waived';
            }

            if (self::wasRefilled($booking)) {
                $warning->forceFill(['fee_status' => WarningFeeStatus::Waived])->save();

                if (($user = User::query()->find($warning->user_id)) !== null) {
                    Notifier::send($user, NotificationType::LateCancelFeeWaived, params: [
                        'date' => $warning->occurred_on->toFormattedDateString(),
                    ], data: ['warningId' => (string) $warning->getKey(), 'bookingId' => (string) $booking->id]);
                }

                return 'waived';
            }

            RecordMemberWarningAction::charge($warning, $booking);

            if (($user = User::query()->find($warning->user_id)) !== null) {
                Notifier::send($user, NotificationType::LateCancelFeeCharged, params: [
                    'amount' => DebtNotices::egp((int) $warning->fee_piastres),
                    'date' => $warning->occurred_on->toFormattedDateString(),
                    'hours' => (string) FeeSettings::appealHours(),
                ], data: ['warningId' => (string) $warning->getKey(), 'bookingId' => (string) $booking->id]);
            }

            return 'charged';
        });
    }

    /**
     * Somebody else took a seat on that day after she cancelled — the waiting list did its job.
     */
    public static function wasRefilled(Booking $cancelled): bool
    {
        return Booking::query()
            ->where('scheduled_trip_id', $cancelled->scheduled_trip_id)
            ->whereKeyNot($cancelled->getKey())
            ->where('passenger_user_id', '!=', $cancelled->passenger_user_id)
            ->where('created_at', '>=', $cancelled->cancelled_at ?? $cancelled->updated_at)
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Completed->value,
                BookingStatus::NoShow->value,
                BookingStatus::EndedEarly->value,
            ])
            ->exists();
    }
}
