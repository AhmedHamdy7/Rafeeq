<?php

namespace App\Domains\Admin\Support;

use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Payment\Actions\SuspendForDebtAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\DebtSettings;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\SettlementWeek;
use Carbon\CarbonImmutable;

/**
 * Home · the watch list and the money snapshot (H03, H04).
 *
 * The watch list is "about to go wrong", not "already a queue": each line is somebody the
 * rules will act on soon unless a person does something first. Counted the way the rules
 * that will act on them count — {@see DebtStanding} and {@see SuspendForDebtAction} — so
 * the Home never disagrees with what the sweep then does.
 */
final class HomeWatch
{
    /** How many accounts a line looks at, so a bad week cannot make Home slow. */
    private const int SCAN_LIMIT = 500;

    /** Papers count as expiring this many days ahead. */
    public const int EXPIRY_DAYS = 30;

    /**
     * @return array{driversOwing: int, driversBlocked: int, passengersNear: int}
     */
    public static function debts(): array
    {
        $drivers = DebtAccount::query()->where('side', DebtSide::Driver->value)->where('balance_piastres', '>', 0)
            ->orderByDesc('balance_piastres')->limit(self::SCAN_LIMIT)->pluck('user_id');

        $owing = $drivers->filter(fn (string $id) => DebtStanding::of($id, DebtSide::Driver)->overduePiastres > 0)->count();

        $limit = DebtSettings::passengerSuspensionLimitPiastres();
        $nearAge = now()->subDays(max(0, DebtSettings::passengerSuspensionDays() - 3));

        $passengers = DebtAccount::query()->where('side', DebtSide::Passenger->value)->where('balance_piastres', '>', 0)->whereNull('suspended_at')
            ->orderByDesc('balance_piastres')->limit(self::SCAN_LIMIT)->pluck('user_id');

        $near = $passengers->filter(function (string $id) use ($limit, $nearAge): bool {
            $standing = DebtStanding::of($id, DebtSide::Passenger);

            return $standing->owedPiastres() * 10 >= $limit * 9
                || ($standing->oldestUnpaidAt !== null && $standing->oldestUnpaidAt->lte($nearAge));
        })->count();

        return [
            'driversOwing' => $owing,
            'driversBlocked' => DebtAccount::query()->where('side', DebtSide::Driver->value)->whereNotNull('suspended_at')->count(),
            'passengersNear' => $near,
        ];
    }

    /**
     * @return array{licences: int, carPapers: int}
     */
    public static function expiringPapers(): array
    {
        $today = now('Africa/Cairo')->toDateString();
        $until = now('Africa/Cairo')->addDays(self::EXPIRY_DAYS)->toDateString();

        return [
            'licences' => DriverProfile::query()->where('status', DriverProfileStatus::Approved->value)->whereHas('user')
                ->whereBetween('licence_expiry', [$today, $until])->count(),
            'carPapers' => VehicleDocument::query()->whereBetween('expires_at', [$today, $until])
                ->whereHas('vehicle', fn ($query) => $query->where('verification_status', VehicleVerificationStatus::Approved->value))->count(),
        ];
    }

    public static function genderReviewCommutes(): int
    {
        return CommuteOffer::query()->where('paused_reason', CommutePausedReason::GenderReview->value)->count();
    }

    /**
     * This settlement week, in piastres.
     *
     * @return array{weekStart: CarbonImmutable, commission: int, received: int, owedNow: int, suspendedOwe: int}
     */
    public static function money(): array
    {
        $week = SettlementWeek::current();
        $start = CarbonImmutable::parse($week, SettlementWeek::TIMEZONE);

        return [
            'weekStart' => $start,
            'commission' => (int) DebtEntry::query()->where('side', DebtSide::Driver->value)->where('week_of', $week)
                ->whereIn('type', [DebtEntryType::Commission->value, DebtEntryType::LateFee->value])->sum('amount_piastres'),
            'received' => -1 * (int) DebtEntry::query()->where('type', DebtEntryType::Payment->value)
                ->where('created_at', '>=', $start->utc())->sum('amount_piastres'),
            'owedNow' => (int) DebtAccount::query()->where('balance_piastres', '>', 0)->sum('balance_piastres'),
            'suspendedOwe' => (int) DebtAccount::query()->where('balance_piastres', '>', 0)->whereNotNull('suspended_at')->sum('balance_piastres'),
        ];
    }
}
