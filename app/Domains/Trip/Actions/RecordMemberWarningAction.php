<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
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
 * ⚠️ **No amount, ever.** Crossing into the third lateness or the second missed commitment sets
 * `fee_eligible` and nothing more. v5: *"amount not shown until Payments rules"*, and the
 * cancellation policy is still deferred — a number here would be inventing the one thing v5 is
 * most careful not to state.
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

            /*
             * Her position in the sequence, counted from the warnings that still stand. An
             * overturned one does not push the next person's warning up a rung — which is the
             * whole point of a dispute being able to do anything.
             */
            $standing = MemberWarning::query()->standing((string) $member->id, $kind)->count();

            $ordinal = $standing + 1;

            $warning = new MemberWarning;

            $warning->forceFill([
                'id' => (string) Str::ulid(),
                'user_id' => $member->id,
                'kind' => $kind->value,
                'booking_id' => $booking->getKey(),
                'ordinal' => $ordinal,
                'fee_eligible' => $kind->feeBecomesPossibleAt($ordinal),
                'occurred_on' => $occurredOn ?? $booking->scheduledTrip?->trip_date?->toDateString() ?? now()->toDateString(),
                // Null for a lateness: it waits for her journey to end. See `deliver()`.
                'delivered_at' => $kind->waitsForDropOff() ? null : now(),
            ])->save();

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
        Notifier::send($member, $warning->kind === WarningKind::LateBoarding
            ? NotificationType::LateBoardingWarning
            : NotificationType::MissedCommitmentWarning, params: [
                'ordinal' => (string) $warning->ordinal,
                'date' => $warning->occurred_on->toFormattedDateString(),
            ], data: [
                'warningId' => (string) $warning->getKey(),
                'bookingId' => (string) $warning->booking_id,
                'feeEligible' => $warning->fee_eligible,
            ]);
    }
}
