<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ChangeApprovalResponse;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Models\ChangeApproval;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A passenger answering a proposed change, and the moment the last yes makes it real
 * (Feature 03 v5 §6).
 *
 * 🔴 **It applies itself on the answer that completes unanimity, rather than waiting for the driver
 * to press Apply.** Every affected passenger has agreed; leaving the change sitting unapplied would
 * mean a commute whose people have all consented to 07:30 still departing at 08:00 until the driver
 * happens to open the app — and if she never does, they turn up at the time they all agreed to
 * leave behind. v5's driver screens read `1 of 2 accepted` then `Applied`, with no step between.
 *
 * 🔴 **One rejection ends it.** v5 forbids partial application outright, so a proposal with a no in
 * it can never apply — and keeping it pending would have everybody else waiting on an answer that
 * cannot change the outcome. The driver can revise, which asks again from scratch.
 */
final readonly class RespondToChangeProposalAction
{
    public function __construct(private ApplyChangeProposalAction $apply) {}

    /**
     * @param  Booking  $booking  the affected booking she is answering for, already checked as hers
     */
    public function respond(
        ChangeProposal $proposal,
        Booking $booking,
        ChangeApprovalResponse $response,
    ): ChangeProposal {
        if (! $proposal->status->isLive()) {
            throw DomainException::of(ErrorCode::ChangeProposalNotLive, fields: [
                'status' => [$proposal->status->value],
            ]);
        }

        if ($proposal->expires_at->isPast()) {
            /*
             * Answered after the car went. Refused rather than honoured: the proposal was about a
             * morning that has happened, and applying it now would rewrite a journey rather than
             * arrange one. The sweep will mark it expired; this makes sure a request that races the
             * sweep gets the same answer.
             */
            throw DomainException::of(ErrorCode::ChangeProposalNotLive, fields: [
                'status' => [ChangeProposalStatus::Expired->value],
            ]);
        }

        return DB::transaction(function () use ($proposal, $booking, $response): ChangeProposal {
            /*
             * Her standing answer for this version, replaced rather than added to.
             *
             * v5 lets her withdraw an acceptance until the change is applied, so changing her mind
             * has to be an update — two contradictory rows would make "did she agree?" a question
             * about row order. The unique index on (proposal, booking, version) is what makes that
             * true under a double tap.
             *
             * 🔴 Found and force-filled rather than `updateOrCreate`, because that MASS-ASSIGNS and
             * this model has no `$fillable` on purpose — the same trap
             * {@see \App\Domains\Booking\Actions\OfferSeatToWaitlistAction} documents. A fillable
             * `response` here would let a client post somebody else's agreement as a field in an
             * unrelated payload, and the field in question is consent to change her morning.
             */
            $answer = ChangeApproval::query()
                ->where('change_proposal_id', $proposal->getKey())
                ->where('booking_id', $booking->getKey())
                ->where('proposal_version', $proposal->version)
                ->first() ?? new ChangeApproval;

            $answer->forceFill([
                'id' => $answer->getKey() ?? (string) Str::ulid(),
                'change_proposal_id' => $proposal->getKey(),
                'booking_id' => $booking->getKey(),
                'proposal_version' => $proposal->version,
                'response' => $response->value,
                'responded_at' => now(),
            ])->save();

            if ($response === ChangeApprovalResponse::Rejected) {
                $proposal->forceFill(['status' => ChangeProposalStatus::Rejected->value])->save();

                $this->tellTheDriver($proposal, NotificationType::ChangeProposalRejected);

                return $proposal->refresh();
            }

            /*
             * Re-read under the transaction before deciding. Unanimity is a fact about every
             * affected booking, and two passengers accepting at the same instant must not both see
             * "one still to go" — the second one's transaction waits here and sees her own answer
             * committed.
             */
            if ($proposal->refresh()->isUnanimous()) {
                $this->apply->execute($proposal);
            }

            return $proposal->refresh();
        });
    }

    private function tellTheDriver(ChangeProposal $proposal, NotificationType $type): void
    {
        $proposal->loadMissing('commuteOffer.driverProfile.user');

        $driver = $proposal->commuteOffer?->driverProfile?->user;

        if ($driver === null) {
            return;
        }

        Notifier::send($driver, $type, data: [
            'changeProposalId' => $proposal->getKey(),
            'commuteId' => $proposal->commute_offer_id,
        ]);
    }
}
