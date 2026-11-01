<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Safety\Actions\ReportIncidentAction;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Her answer to "Did you ask to get off here?" (TRIP-20, PAY-28; Feature 03 backend file F-9).
 *
 * - **Yes** → nothing changes: she chose it, the full fare stands (PAY-27).
 * - **No** → the other-reason rule (PAY-25): she pays nothing — the fare she handed over comes back
 *   to her as a credit on her next ride — the driver pays 100 % of her seat, and a `dropped_early`
 *   report opens for staff to check the GPS. He can appeal the fee with proof within 48 hours.
 *
 * Once. Only on a booking the check asked about.
 */
final readonly class AnswerEarlyDropAction
{
    public function __construct(private ReportIncidentAction $report) {}

    public function execute(User $passenger, Booking $booking, bool $askedToGetOff): Booking
    {
        if ($booking->early_drop_asked_at === null || $booking->early_drop_answer !== null) {
            throw DomainException::of(ErrorCode::EarlyDropNotAsked);
        }

        DB::transaction(function () use ($passenger, $booking, $askedToGetOff): void {
            $booking->forceFill(['early_drop_answer' => $askedToGetOff ? 'yes' : 'no'])->save();

            if ($askedToGetOff) {
                return;
            }

            DriverFees::charge($booking, DriverFees::DROPPED_EARLY, FeeSettings::driverActiveCancelPercent(), 'PAY-28');

            DebtLedger::credit(
                $passenger,
                DebtEntryType::Credit,
                max(1, (int) $booking->price_snapshot_piastres),
                'dropped_early_refund',
                $booking,
                meta: ['rule' => 'PAY-28'],
                side: DebtSide::Passenger,
                idempotencyKey: "dropped_early_refund:{$booking->getKey()}",
            );
        });

        if (! $askedToGetOff) {
            $this->report->execute($passenger, [
                'category' => IncidentCategory::DroppedEarly->value,
                'bookingId' => $booking->getKey(),
                'description' => "Set down {$booking->early_drop_distance_meters} m before her drop-off; she says she did not ask.",
            ]);
        }

        return $booking->refresh();
    }
}
