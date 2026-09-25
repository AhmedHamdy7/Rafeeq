<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Group\Models\GroupMember;

/**
 * What a driver's "yes" actually produced.
 *
 * One shape for both kinds of request, because approval is one operation: the
 * passenger is seated on every day they asked for. A trial asked for one day, so
 * `bookings` holds one; a recurring member asked for a pattern, so it holds every
 * day in the horizon that could be seated.
 *
 * `skipped` is the honest half. A recurring approval over thirty days will meet
 * days that are already full, and refusing the whole membership because of one
 * Wednesday would make recurring commitment nearly impossible. So those days are
 * reported rather than hidden — the driver sees exactly which ones did not take,
 * and why, instead of discovering it a fortnight later.
 */
final readonly class SeatApproval
{
    /**
     * @param  array<int, Booking>  $bookings
     * @param  array<string, string>  $skipped  trip date (Y-m-d) => the reason it could not be seated
     */
    public function __construct(
        public SeatRequest $request,
        public GroupMember $member,
        public array $bookings,
        public array $skipped = [],
    ) {}

    /**
     * The single booking, for the trial case where there is exactly one.
     */
    public function booking(): ?Booking
    {
        return $this->bookings[0] ?? null;
    }
}
