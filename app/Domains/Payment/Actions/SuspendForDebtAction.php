<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtNotices;
use App\Domains\Payment\Support\DebtSettings;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\OpenDisputes;
use Illuminate\Support\Facades\DB;

/**
 * Suspension for debt, and lifting it (PAY-17, PAY-45, PAY-46, PAY-19; Feature 03 backend file L-7,
 * L-12).
 *
 * 🔴 The suspension is a mark on the DEBT ACCOUNT of one side (`debt_accounts.suspended_at`), not
 * on the account. An account hold (`account_status = suspended`) shuts the whole app; a driver
 * suspended for commission keeps Passenger mode (PAY-45), so the two cannot be the same switch.
 *
 * | side      | when                                                  | what happens                                   |
 * |-----------|-------------------------------------------------------|------------------------------------------------|
 * | driver    | money from a week that has missed its 2nd Friday       | every published commute paused, every upcoming seat cancelled — inside the 16h lock too — with no fee; riders back on the waiting list |
 * | passenger | a fee unpaid 14 days, or her debt over 200 EGP         | every upcoming seat cancelled, inside the lock too; the seat goes to the waiting list; no new requests |
 *
 * Never while a dispute of hers is open (PAY-19). Lifted on its own once the money is paid: the
 * driver's when nothing is overdue, the passenger's when she owes nothing. A driver resumes her
 * commutes herself; nothing comes back on its own.
 *
 * Every method is safe to call again: a second call finds the mark already there and does nothing.
 */
final readonly class SuspendForDebtAction
{
    public function __construct(
        private ChangeCommuteStatusAction $commutes,
        private CancelBookingAction $cancel,
    ) {}

    /**
     * PAY-45. Called by the daily debt sweep.
     *
     * @return bool whether she was suspended now
     */
    public function driverIfDue(string $userId): bool
    {
        $standing = DebtStanding::of($userId, DebtSide::Driver);

        if ($standing->suspendedAt !== null || $standing->severePiastres <= 0 || OpenDisputes::existFor($userId)) {
            return false;
        }

        $profile = DriverProfile::query()->find($userId);

        DB::transaction(function () use ($userId, $profile): void {
            DebtLedger::lockedAccount($userId, DebtSide::Driver)->forceFill(['suspended_at' => now()])->save();

            /*
             * Pausing is what cancels the seats: every upcoming confirmed seat, inside the 16-hour
             * lock as well, and each rider back on that commute's waiting list (PAY-45). With no
             * fee — `CommissionUnpaid` is the reason the fee work (W3) must leave alone.
             */
            if ($profile !== null) {
                $this->commutes->pauseUnpublishableOffers($profile, CommutePausedReason::CommissionUnpaid);
            }
        });

        DebtNotices::send($userId, NotificationType::DrivingSuspendedForDebt, ['amount' => $standing->owedPiastres()]);

        return true;
    }

    /**
     * PAY-17, PAY-46. Called the moment her debt grows (the ledger) and by the daily sweep (the
     * 14 days pass with nothing being written).
     *
     * @return bool whether she was suspended now
     */
    public function passengerIfDue(string $userId): bool
    {
        $standing = DebtStanding::of($userId, DebtSide::Passenger);

        if ($standing->suspendedAt !== null || ! self::passengerIsDue($standing) || OpenDisputes::existFor($userId)) {
            return false;
        }

        return $this->passengerNow($userId);
    }

    /**
     * Suspends her now, whatever the amount (PAY-47: a man confirmed to have booked a women-only
     * commute stays suspended until he pays). Lifted like any other once she owes nothing.
     */
    public function passengerNow(string $userId): bool
    {
        $standing = DebtStanding::of($userId, DebtSide::Passenger);

        if ($standing->suspendedAt !== null) {
            return false;
        }

        DB::transaction(function () use ($userId): void {
            DebtLedger::lockedAccount($userId, DebtSide::Passenger)->forceFill(['suspended_at' => now()])->save();

            foreach ($this->upcomingSeatsOf($userId) as $booking) {
                // Hers, by the system: no fee, nothing on her reliability record, the seat to the
                // waiting list, and the driver pays nothing (PAY-46).
                $this->cancel->bySuspension($booking, 'debt_suspended');
            }
        });

        DebtNotices::send($userId, NotificationType::RidingSuspendedForDebt, ['amount' => $standing->owedPiastres()]);

        return true;
    }

    /**
     * Takes the mark off once the money that put it there is paid. Called after anything comes off
     * a debt (a confirmed payment, a credit, a coupon).
     */
    public function liftIfPaid(string $userId, DebtSide $side): bool
    {
        $account = DebtAccount::query()->where('user_id', $userId)->where('side', $side->value)->first();

        if ($account?->suspended_at === null) {
            return false;
        }

        $standing = DebtStanding::of($userId, $side);

        $paid = $side === DebtSide::Driver
            ? ! $standing->isBlocked()
            : $standing->owedPiastres() === 0;

        if (! $paid) {
            return false;
        }

        $account->forceFill(['suspended_at' => null])->save();

        return true;
    }

    public static function isSuspended(string $userId, DebtSide $side): bool
    {
        return DebtAccount::query()
            ->where('user_id', $userId)
            ->where('side', $side->value)
            ->whereNotNull('suspended_at')
            ->exists();
    }

    private static function passengerIsDue(DebtStanding $standing): bool
    {
        if ($standing->owedPiastres() <= 0) {
            return false;
        }

        // "Over 200 EGP": exactly 200 is not yet.
        if ($standing->owedPiastres() > DebtSettings::passengerSuspensionLimitPiastres()) {
            return true;
        }

        return $standing->oldestUnpaidAt !== null
            && $standing->oldestUnpaidAt->lte(now()->subDays(DebtSettings::passengerSuspensionDays()));
    }

    /**
     * @return iterable<Booking>
     */
    private function upcomingSeatsOf(string $userId): iterable
    {
        return Booking::query()
            ->where('passenger_user_id', $userId)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('status', ScheduledTripStatus::Scheduled->value)
                ->where('departure_at', '>', now())
                ->select('id'))
            ->orderBy('id')
            ->get();
    }
}
