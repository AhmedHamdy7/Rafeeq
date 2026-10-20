<?php

namespace App\Domains\Commute\Models;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\ProposeCommuteChangeAction;
use App\Domains\Commute\Enums\ChangeApprovalResponse;
use App\Domains\Commute\Enums\ChangeProposalScope;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use Database\Factories\ChangeProposalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A change a driver has proposed to a commute people have already booked (Feature 03 v5 §6).
 *
 * No `$fillable`: every column is written by
 * {@see ProposeCommuteChangeAction} from a validated payload, and
 * `status`, `version` and `expires_at` are decisions the server makes. A mass-assignable `status`
 * would let a client apply its own proposal.
 */
class ChangeProposal extends Model
{
    /** @use HasFactory<ChangeProposalFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'scope' => ChangeProposalScope::class,
            'status' => ChangeProposalStatus::class,
            'changes' => 'array',
            'previous' => 'array',
            'version' => 'integer',
            'effective_from' => 'date',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeApproval::class);
    }

    /**
     * The bookings whose passengers get a say, **as things stand right now**.
     *
     * 🔴 Recomputed on every read rather than frozen when the proposal was sent, and that is how
     * v5's hardest rule in this area is honoured: *"New affected passengers confirmed while proposal
     * pending must also approve."* A seat confirmed an hour ago appears here, has no answer, and
     * unanimity is no longer met — with nothing to remember to hook into at the moment of booking.
     *
     * Confirmed only. A pending request has not been promised the old terms, so it has no standing
     * to object to new ones; it will simply be answered against whatever is current.
     *
     * @return Builder<Booking>
     */
    public function affectedBookings(): Builder
    {
        return Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', $this->affectedTripIds())
            ->orderBy('id');
    }

    /**
     * The days this proposal would change.
     *
     * @return Builder<ScheduledTrip>
     */
    public function affectedTrips(): Builder
    {
        $trip = $this->scheduledTrip;

        if ($this->scope === ChangeProposalScope::OnlyThisOccurrence || $trip === null) {
            return ScheduledTrip::query()->whereKey($this->scheduled_trip_id);
        }

        /*
         * The same weekday, from the effective date on. Not every weekday: v5 keeps each weekday's
         * departure and arrival independent, so a driver moving her Tuesday has said nothing about
         * her Thursday.
         */
        return ScheduledTrip::query()
            ->where('commute_offer_id', $this->commute_offer_id)
            ->where('commute_schedule_id', $trip->commute_schedule_id)
            ->whereDate('trip_date', '>=', $this->effective_from ?? $trip->trip_date)
            ->whereRaw('WEEKDAY(trip_date) = WEEKDAY(?)', [$trip->trip_date->toDateString()])
            ->where('departure_at', '>', now());
    }

    /**
     * @return Builder<ScheduledTrip>
     */
    private function affectedTripIds(): Builder
    {
        return $this->affectedTrips()->select('id');
    }

    /**
     * Every affected booking has said yes to THIS version.
     *
     * 🔴 A proposal with no affected booking is NOT unanimous. Nobody saying no is not the same as
     * everybody saying yes, and a change that needed approval from an empty set would apply itself
     * — which is a quiet route around the whole mechanism when the last passenger cancels.
     * A driver with no passengers does not need a proposal at all; she edits the day.
     */
    public function isUnanimous(): bool
    {
        $affected = $this->affectedBookings()->pluck('id');

        if ($affected->isEmpty()) {
            return false;
        }

        $accepted = $this->approvals()
            ->where('proposal_version', $this->version)
            ->where('response', ChangeApprovalResponse::Accepted->value)
            ->whereIn('booking_id', $affected->all())
            ->pluck('booking_id');

        return $accepted->count() === $affected->count();
    }

    /**
     * Each affected booking with the answer it has given, for the driver's pending view.
     *
     * v5 asks for per-passenger `Accepted`, `Awaiting Response`, `Rejected`, `Withdrawn`, and
     * "awaiting" is the absence of a row — which is why this joins rather than reading approvals
     * alone.
     *
     * @return Collection<int, array{booking: Booking, response: ChangeApprovalResponse|null}>
     */
    public function responseRoster(): Collection
    {
        $bookings = $this->affectedBookings()->with('passenger')->get();

        $answers = $this->approvals()
            ->where('proposal_version', $this->version)
            ->get()
            ->keyBy('booking_id');

        return $bookings->map(fn (Booking $booking): array => [
            'booking' => $booking,
            'response' => $answers->get($booking->getKey())?->response,
        ]);
    }
}
