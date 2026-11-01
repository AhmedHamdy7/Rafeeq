<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Actions\ChargePendingFeesAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtNotices;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Payment\Support\PassengerFees;
use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Warning somebody, and choosing when to say it (Feature 03 v5 §239 and §10).
 *
 * > *"1st warning, 2nd warning, 3rd fee eligible ... Provide clear explanation when warnings
 * > displayed, with dispute link."* And: *"do **not** show any lateness message during the trip.
 * > After drop-off the passenger receives a push-style notification."*
 *
 * 🔴 **Recording and telling are two steps, and the gap between them is the feature.** A lateness
 * is known the moment the driver taps `Picked Up` — the car waited, a timer was running, she got
 * in. But v5 forbids saying so while the journey is happening, and the reason is not politeness: it
 * would be telling a woman she kept people waiting while she is sitting in the car next to them,
 * with nowhere to go for the next forty minutes. So the row is written now and delivered when her
 * journey ends.
 *
 * A missed commitment has no journey to wait for — she is not in the car — so it is told as it is
 * recorded.
 *
 * 🔴 **And now the fee** (PAY-20 → PAY-22; Feature 03 backend file F-1 → F-3). Past the warnings,
 * each rung carries a share of the seat price ({@see PassengerFees}), frozen on the row and written
 * to her ledger:
 *
 * - a **no-show** or a **lateness** is charged at once (a lateness is still only TOLD after her
 *   drop-off);
 * - a **late cancel** waits, `pending`, until departure: if somebody from the waiting list takes
 *   the seat meanwhile it costs nothing (PAY-21, R-8) — see {@see ChargePendingFeesAction}.
 *
 * Each rung is counted on its own counter, reset after 90 days without one (decision B4).
 */
final readonly class RecordMemberWarningAction
{
    /**
     * Record a warning. Returns null when this booking already has one of this kind.
     *
     * 🔴 Idempotent on (person, kind, booking), enforced by a unique index as well as checked here.
     * A drop-off that is retried, or a completion sweep running twice over one morning, must not
     * tell somebody she was late twice for the same journey — and "twice" is not a cosmetic bug
     * when the third one makes a fee possible.
     */
    public function record(User $member, WarningKind $kind, Booking $booking, ?string $occurredOn = null): ?MemberWarning
    {
        return DB::transaction(function () use ($member, $kind, $booking, $occurredOn): ?MemberWarning {
            $existing = MemberWarning::query()
                ->where('user_id', $member->id)
                ->where('kind', $kind->value)
                ->where('booking_id', $booking->getKey())
                ->first();

            if ($existing !== null) {
                return null;
            }

            $occurredOn ??= $booking->scheduledTrip?->trip_date?->toDateString() ?? now()->toDateString();

            /*
             * Her rung on this counter, from the warnings that still stand and keep coming less
             * than 90 days apart. An overturned one does not push the next person's warning up a
             * rung — which is the whole point of a dispute being able to do anything.
             */
            $ordinal = PassengerFees::nextRung((string) $member->id, $kind, CarbonImmutable::parse($occurredOn));

            $basisPoints = PassengerFees::basisPoints($kind, $ordinal);
            $fee = PassengerFees::amountPiastres($booking, $basisPoints);

            $warning = new MemberWarning;

            $warning->forceFill([
                'id' => (string) Str::ulid(),
                'user_id' => $member->id,
                'kind' => $kind->value,
                'booking_id' => $booking->getKey(),
                'ordinal' => $ordinal,
                'fee_eligible' => $fee > 0,
                'fee_basis_points' => $fee > 0 ? $basisPoints : 0,
                'fee_piastres' => $fee,
                'fee_status' => match (true) {
                    $fee === 0 => WarningFeeStatus::None,
                    $kind === WarningKind::LateCancel => WarningFeeStatus::Pending,
                    default => WarningFeeStatus::Charged,
                },
                'occurred_on' => $occurredOn,
                // Null for a lateness: it waits for her journey to end. See `deliver()`.
                'delivered_at' => $kind->waitsForDropOff() ? null : now(),
            ])->save();

            if ($warning->fee_status === WarningFeeStatus::Charged) {
                self::charge($warning, $booking);
            }

            if (! $kind->waitsForDropOff()) {
                $this->tell($member, $warning);
            }

            return $warning;
        });
    }

    /**
     * Tell her about every warning of hers that has been waiting for this journey to end.
     *
     * Called from the two places a journey ends for somebody: her own drop-off, and the completion
     * that sets everybody down at once.
     *
     * @return int how many were delivered
     */
    public function deliverFor(Booking $booking): int
    {
        $pending = MemberWarning::query()
            ->undelivered()
            ->where('booking_id', $booking->getKey())
            ->with('user')
            ->get();

        $delivered = 0;

        foreach ($pending as $warning) {
            if ($warning->user === null) {
                continue;
            }

            $warning->forceFill(['delivered_at' => now()])->save();

            $this->tell($warning->user, $warning);

            $delivered++;
        }

        return $delivered;
    }

    /**
     * The message itself.
     *
     * 🔴 It carries the position, whether a fee has become possible, and the day — and v5 asks for
     * all three: *"`Late pickup recorded (1st warning)` / `You boarded after the 5-minute grace on
     * Sun 18 Oct. 1st and 2nd are warnings; a 3rd may lead to a fee. Tap to review or dispute.`"*
     *
     * The `warningId` in the data is the dispute link v5 requires. A warning she cannot answer is a
     * punishment with no appeal, and this is the one notification in the system whose whole purpose
     * is to be challengeable.
     */
    private function tell(User $member, MemberWarning $warning): void
    {
        $data = [
            'warningId' => (string) $warning->getKey(),
            'bookingId' => (string) $warning->booking_id,
            'feeEligible' => $warning->fee_eligible,
            'feePiastres' => (int) $warning->fee_piastres,
        ];

        $date = $warning->occurred_on->toFormattedDateString();

        // A rung with money on it says the amount and the 48 hours to appeal (PAY-20 → PAY-22).
        if ((int) $warning->fee_piastres > 0) {
            Notifier::send($member, match ($warning->kind) {
                WarningKind::LateBoarding => NotificationType::LateBoardingFee,
                WarningKind::LateCancel => NotificationType::LateCancelFee,
                default => NotificationType::NoShowFee,
            }, params: [
                'amount' => DebtNotices::egp((int) $warning->fee_piastres),
                'date' => $date,
                'hours' => (string) FeeSettings::appealHours(),
            ], data: $data);

            return;
        }

        Notifier::send($member, $warning->kind === WarningKind::LateBoarding
            ? NotificationType::LateBoardingWarning
            : NotificationType::MissedCommitmentWarning, params: [
                'ordinal' => (string) $warning->ordinal,
                'date' => $date,
            ], data: $data);
    }

    /**
     * Writes the warning's fee to her ledger, once (keyed by the warning).
     */
    public static function charge(MemberWarning $warning, Booking $booking): void
    {
        $line = DebtLedger::charge(
            User::query()->findOrFail($warning->user_id),
            DebtEntryType::Fee,
            (int) $warning->fee_piastres,
            $warning->kind->value,
            $booking,
            meta: [
                'rule' => match ($warning->kind) {
                    WarningKind::LateBoarding => 'PAY-22',
                    WarningKind::LateCancel => 'PAY-21',
                    default => 'PAY-20',
                },
                'rung' => (int) $warning->ordinal,
                'basisPoints' => (int) $warning->fee_basis_points,
                'warningId' => (string) $warning->getKey(),
            ],
            side: DebtSide::Passenger,
            idempotencyKey: "warning_fee:{$warning->getKey()}",
        );

        $warning->forceFill([
            'fee_status' => WarningFeeStatus::Charged,
            'debt_entry_id' => $line->id,
        ])->save();
    }
}
