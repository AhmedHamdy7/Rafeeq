<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Actions\SuspendForDebtAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The money a report's outcome moves, decided by staff when they resolve it (Feature 03 backend
 * file F-11, F-12). Then the case is resolved as usual ({@see HandleIncidentAction}).
 *
 * **Payment disputes** (PAY-30 → PAY-32), on a `payment` report. The booking stayed PAID through
 * the review and the commission stayed on the driver; `$fare` below is the seat price plus any fee
 * line that rode on it:
 *
 * | outcome                | money                                                                 |
 * |------------------------|-----------------------------------------------------------------------|
 * | `passenger_lied`       | she owes 150 % of it on her next ride; he is made whole: all of it off his debt (credit beyond) |
 * | `driver_lied`          | he pays 50 % of it on top                                              |
 * | `fee_line_not_received`| an honest miss on the fee line: it moves off his debt and back onto hers |
 *
 * "First case, no evidence, no pattern" is simply resolving or closing the case with no money step.
 *
 * **Women-only misuse** (PAY-47, BOOK-31), on a `women_only_misuse` report: the man owes 100 % of
 * the seat and is suspended from booking until he pays.
 *
 * Each money step is written once (keyed by the report) and audited.
 */
final readonly class SettleIncidentMoneyAction
{
    public const array PAYMENT_OUTCOMES = ['passenger_lied', 'driver_lied', 'fee_line_not_received'];

    public function __construct(private SuspendForDebtAction $suspend) {}

    public function paymentDispute(Incident $incident, AdminUser $admin, string $outcome): void
    {
        $booking = $this->bookingFor($incident, IncidentCategory::Payment);

        if (! in_array($outcome, self::PAYMENT_OUTCOMES, true)) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['outcome' => ['UNKNOWN']]);
        }

        DB::transaction(function () use ($incident, $admin, $outcome, $booking): void {
            $passenger = User::query()->findOrFail($booking->passenger_user_id);
            $driver = User::query()->findOrFail($booking->driver_profile_id);
            $fare = (int) $booking->price_snapshot_piastres + max(0, (int) $booking->fee_line_piastres);
            $line = max(0, (int) $booking->fee_line_piastres);
            $key = "incident:{$incident->id}";
            $meta = ['incidentId' => $incident->id];

            if ($outcome === 'passenger_lied') {
                DebtLedger::charge($passenger, DebtEntryType::Fee, intdiv($fare * FeeSettings::paymentLiePassengerPercent(), 100),
                    'payment_lie', $booking, $meta + ['rule' => 'PAY-31'], DebtSide::Passenger, "{$key}:passenger");
                DebtLedger::credit($driver, DebtEntryType::Credit, max(1, $fare), 'made_whole', $booking,
                    $meta + ['rule' => 'PAY-31'], DebtSide::Driver, "{$key}:driver");
            }

            if ($outcome === 'driver_lied') {
                DebtLedger::charge($driver, DebtEntryType::Fee, max(1, intdiv($fare * FeeSettings::paymentLieDriverPercent(), 100)),
                    'payment_lie', $booking, $meta + ['rule' => 'PAY-31'], DebtSide::Driver, "{$key}:driver");
            }

            if ($outcome === 'fee_line_not_received' && $line > 0) {
                DebtLedger::credit($driver, DebtEntryType::Credit, $line, 'fee_line_not_received', $booking,
                    $meta + ['rule' => 'PAY-32'], DebtSide::Driver, "{$key}:driver");
                DebtLedger::charge($passenger, DebtEntryType::Fee, $line, 'fee_line_not_received', $booking,
                    $meta + ['rule' => 'PAY-32'], DebtSide::Passenger, "{$key}:passenger");
            }

            AdminActionLog::record($admin, 'incident.money', $incident, after: ['outcome' => $outcome]);
        });
    }

    public function womenOnlyMisuse(Incident $incident, AdminUser $admin): void
    {
        $booking = $this->bookingFor($incident, IncidentCategory::WomenOnlyMisuse);

        DB::transaction(function () use ($incident, $admin, $booking): void {
            $man = User::query()->findOrFail($booking->passenger_user_id);

            DebtLedger::charge(
                $man,
                DebtEntryType::Fee,
                max(1, intdiv((int) $booking->price_snapshot_piastres * FeeSettings::womenOnlyMisusePercent(), 100)),
                'women_only_misuse',
                $booking,
                ['rule' => 'PAY-47', 'incidentId' => $incident->id],
                DebtSide::Passenger,
                "incident:{$incident->id}:passenger",
            );

            AdminActionLog::record($admin, 'incident.money', $incident, after: ['outcome' => 'women_only_misuse']);
        });

        // PAY-47: suspended until he pays, whatever the amount.
        $this->suspend->passengerNow($booking->passenger_user_id);
    }

    private function bookingFor(Incident $incident, IncidentCategory $category): Booking
    {
        if ($incident->category !== $category || $incident->booking_id === null) {
            throw DomainException::of(ErrorCode::IncidentTransitionNotAllowed);
        }

        return Booking::query()->findOrFail($incident->booking_id);
    }
}
