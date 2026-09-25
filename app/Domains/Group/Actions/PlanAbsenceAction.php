<?php

namespace App\Domains\Group\Actions;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Models\GroupAbsence;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "I'm away from the 20th to the 27th."
 *
 * A planned absence is how a committed member says they will not be there without
 * leaving the group — travelling, exams, a fortnight at their mother's. It is the
 * difference between a member the driver can still count on and one who has
 * vanished.
 *
 * 🔴 `releases_seat` is the one column here with teeth. Set, it means "somebody
 * else can have my seat those days" — so the bookings in that range are actually
 * cancelled and the waiting list is offered the seats. Left unset, the seat stays
 * theirs and the car simply travels with an empty seat they have paid nothing for.
 *
 * That asymmetry is deliberate, and it is also why cancelling an absence does NOT
 * bring the bookings back: the seats were given away, and possibly taken. Undoing
 * an absence restores a member's plans, not other people's.
 */
final readonly class PlanAbsenceAction
{
    public function __construct(private CancelBookingAction $cancel) {}

    /**
     * @param  array<string, mixed>  $input  validated upstream
     */
    public function execute(GroupMember $member, array $input): GroupAbsence
    {
        $from = CarbonImmutable::parse($input['fromDate'])->startOfDay();
        $to = CarbonImmutable::parse($input['toDate'])->startOfDay();

        $this->assertLengthIsSane($from, $to);
        $this->assertNoOverlap($member, $from, $to);

        return DB::transaction(function () use ($member, $input, $from, $to): GroupAbsence {
            $absence = new GroupAbsence;

            $absence->fill([
                'commute_group_id' => $member->commute_group_id,
                'user_id' => $member->user_id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'reason' => $input['reason'] ?? null,
                'releases_seat' => (bool) ($input['releasesSeat'] ?? false),
            ]);

            $absence->save();

            if ($absence->releases_seat) {
                $this->releaseSeats($member, $from, $to, $input['reason'] ?? null);
            }

            return $absence;
        });
    }

    /**
     * Cancelling an absence.
     *
     * Deleted rather than kept with a flag: an absence that was called off is not a
     * fact about the past, it is a plan that changed. There is nothing to audit —
     * the bookings it cancelled, if any, carry their own audit trail.
     */
    public function cancel(GroupAbsence $absence): void
    {
        $absence->delete();
    }

    /**
     * Hands the seats back to the trip, one booking at a time, through the ordinary
     * cancellation path.
     *
     * Reusing {@see CancelBookingAction} rather than updating the rows here is what
     * keeps this correct: that path takes the row lock, releases the seat
     * atomically, writes the audit event, and offers the seat to the waiting list.
     * A shortcut would have to reimplement all four, and would get the lock wrong.
     */
    private function releaseSeats(GroupMember $member, CarbonImmutable $from, CarbonImmutable $to, ?string $reason): void
    {
        $bookings = Booking::query()
            ->where('passenger_user_id', $member->user_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $member->commuteGroup->commute_offer_id)
                ->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])
                // A day already travelled is not one anybody can be absent from.
                ->where('departure_at', '>', now())
                ->select('id'))
            ->get();

        foreach ($bookings as $booking) {
            $this->cancel->byPassenger($booking, $reason ?? __('commute.absence.planned'));
        }
    }

    private function assertLengthIsSane(CarbonImmutable $from, CarbonImmutable $to): void
    {
        // The order is also a CHECK constraint on the table; this gives the caller
        // an explanation instead of a constraint violation.
        if ($to->lessThan($from)) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'toDate' => [__('validation.after_or_equal', ['attribute' => 'toDate', 'date' => 'fromDate'])],
            ]);
        }

        $days = $from->diffInDays($to) + 1;

        if ($days > (int) config('rafeeq.group.max_absence_days')) {
            throw DomainException::of(ErrorCode::AbsenceTooLong, fields: [
                'maxDays' => [(string) config('rafeeq.group.max_absence_days')],
            ]);
        }
    }

    /**
     * Overlapping absences are not a second plan, they are the same plan entered
     * twice — and with `releases_seat` involved, the second one would try to cancel
     * bookings the first one already cancelled.
     */
    private function assertNoOverlap(GroupMember $member, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $overlaps = GroupAbsence::query()
            ->where('commute_group_id', $member->commute_group_id)
            ->where('user_id', $member->user_id)
            // Two ranges overlap unless one ends before the other starts.
            ->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())
            ->exists();

        if ($overlaps) {
            throw DomainException::of(ErrorCode::AbsenceOverlaps);
        }
    }
}
