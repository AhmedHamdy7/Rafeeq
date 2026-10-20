<?php

namespace App\Domains\Rating\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Enums\RatingKind;
use App\Domains\Rating\Enums\RatingTagValue;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\RatingTag;
use App\Domains\Rating\Support\RatingSettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rating the other person on a journey (Chapter 9, screen 37).
 *
 * 🔴 **Double-blind is enforced in the data, not in the screen** — pitfall #26 names this exactly:
 * "if the API returned the rating before both sides rated, the protection is broken even if the UI
 * does not show it". So `visible_at` stays NULL until there is a reason for it to be filled, and
 * every path that could let somebody read or revise a rating with knowledge of the other's is
 * closed here:
 *
 * - **Submission closes when the reveal window closes.** One number, not two. If you could still
 *   rate on day eight, you could wait for the seventh day, read what she wrote about you, and
 *   answer it — which is double-blind defeated through the front door rather than broken.
 * - **An edit is refused the moment the rating is visible**, whatever the edit clock says. The edit
 *   window exists to fix a typo in the minute after writing. Without this rule, "rate five stars,
 *   wait for the reveal, read hers, revise mine to one" would be two legitimate API calls.
 * - **Only visible ratings count towards an average.** That lives in
 *   {@see RecomputeRatingStatsAction} and is the subtler half: an average that moved when a hidden
 *   rating arrived would let anybody read that rating off the arithmetic. The query filter is
 *   worth nothing if the mean leaks around it.
 *
 * 🔒 And what the request may not say. `direction` and `reviewed_user_id` are both derived from the
 * booking, never accepted — the same rule as a report's `reported_user_id`, for the same reason: a
 * field naming who a rating is about is a way to put stars, or a comment, against a stranger.
 */
final readonly class SubmitRatingAction
{
    public function __construct(private RevealRatingsAction $reveals) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function submit(User $reviewer, Booking $booking, array $attributes): Rating
    {
        $direction = $this->directionFor($reviewer, $booking);

        $kind = $this->kindFor($reviewer, $booking, $direction);

        $this->assertRateable($booking, $kind);

        if ($this->existing($reviewer, $booking) !== null) {
            throw DomainException::of(ErrorCode::RatingAlreadySubmitted);
        }

        $rating = DB::transaction(function () use ($reviewer, $booking, $attributes, $direction, $kind): Rating {
            $rating = new Rating;

            $rating->fill([
                'booking_id' => $booking->id,
                'reviewer_user_id' => $reviewer->id,
                // 🔒 Both derived. See the class note.
                'reviewed_user_id' => $this->otherParty($reviewer, $booking),
                'direction' => $direction->value,
                'stars' => (int) $attributes['stars'],
                'comment' => $attributes['comment'] ?? null,
            ]);

            // Not fillable: when it may be changed, and when it may be read, are not the
            // reviewer's to set.
            $rating->edit_deadline_at = now()->addMinutes(RatingSettings::editWindowMinutes());

            /*
             * 🔒 Nor is which KIND of rating this is. It decides whether double-blind applies and
             * whether the stars count, so a fillable `kind` would let a client publish a review
             * immediately and keep it out of its own average by saying so in the payload.
             */
            $rating->kind = $kind->value;

            /*
             * 🔴 Feedback on a cancelled seat is visible at once (Feature 03 v5), and the reason is
             * what makes it safe rather than an exception bolted on. Double-blind exists so neither
             * person can read the other's rating before answering it — and the driver cannot rate a
             * passenger for a journey that did not happen, so there is no pair to reveal and nothing
             * to retaliate against. Holding it back would delay it for nobody's protection.
             */
            if (! $kind->isDoubleBlind()) {
                $rating->visible_at = now();
            }

            $rating->save();

            $this->syncTags($rating, $attributes['tags'] ?? []);

            return $rating;
        });

        /*
         * If the other side has already rated, this submission is what completes the pair — so
         * both become visible now, together and with one timestamp. `RevealRatingsAction` owns
         * that; see its note on why revealing one before the other, even by the length of a
         * request, is the exact asymmetry the design exists to prevent.
         *
         * Skipped for a cancellation: there is no pair, and asking the reveal to consider one would
         * invite it to publish a hidden completed-ride rating on the strength of feedback about
         * something else.
         */
        if ($kind->isDoubleBlind()) {
            $this->reveals->pair($booking);
        }

        return $rating->refresh();
    }

    /**
     * Changing what you wrote, while nobody has been able to read it.
     *
     * 🔒 Two conditions, and the second is the one that matters: still inside the edit window AND
     * still invisible. See the class note on why the clock alone is not enough.
     */
    public function update(User $reviewer, Rating $rating, array $attributes): Rating
    {
        if ($rating->reviewer_user_id !== $reviewer->id) {
            // 404-shaped: somebody else's rating must not be probeable through this endpoint, and
            // confirming one exists would itself say they rated.
            throw DomainException::of(ErrorCode::NotFound);
        }

        /*
         * 🔴 "Visible" only blocks an edit where double-blind applies.
         *
         * The rule exists because "rate five stars, wait for the reveal, read hers, revise mine to
         * one" would otherwise be two legitimate calls. Feedback on a cancelled seat is published
         * at once and has no counterpart to read, so that attack does not exist for it — and the
         * edit window goes back to being what it is for, which is fixing what you wrote. v5 asks
         * for editable feedback; this is how it stays safe for the kind that needs protecting.
         */
        $blockedByVisibility = $rating->isVisible() && $rating->kind->isDoubleBlind();

        if ($blockedByVisibility || $rating->edit_deadline_at === null || $rating->edit_deadline_at->isPast()) {
            throw DomainException::of(ErrorCode::RatingNotEditable);
        }

        return DB::transaction(function () use ($rating, $attributes): Rating {
            if (array_key_exists('stars', $attributes)) {
                $rating->stars = (int) $attributes['stars'];
            }

            if (array_key_exists('comment', $attributes)) {
                $rating->comment = $attributes['comment'];
            }

            // Stated rather than inferred from `updated_at`, which moves for reasons that are not
            // the reviewer's: the reveal writes to this row too.
            $rating->edited_at = now();

            $rating->save();

            if (array_key_exists('tags', $attributes)) {
                $this->syncTags($rating, $attributes['tags']);
            }

            return $rating->refresh();
        });
    }

    /**
     * 🔴 Why a rating may not be written at all.
     *
     * The window is measured from the trip's own date rather than from the booking row's
     * timestamps: a booking is created when a seat is approved, which can be a month before the
     * journey, and measuring from that would close the window before the trip had even happened.
     */
    private function assertRateable(Booking $booking, RatingKind $kind): void
    {
        /*
         * 🔴 A cancelled seat is rateable now, and the old rule's reasoning is what shapes the
         * exception rather than being thrown away.
         *
         * This used to refuse anything but a completed booking, because "a cancelled or no-show
         * booking is not a shared ride to have an opinion about, and letting it be rated would make
         * 'book then cancel' a way to leave stars on somebody". Feature 03 v5 overrides the first
         * half — *"Passenger affected by canceled confirmed booking may leave rating/comment even if
         * no ride occurred"* — and the second half still stands. The attack it names is a PASSENGER
         * booking and cancelling to leave stars, so {@see kindFor()} allows this only for a booking
         * the DRIVER cancelled, and only from the passenger who lost the seat. A passenger who
         * cancels her own seat can still rate nobody.
         */
        $rateable = $kind === RatingKind::CanceledBooking
            || $booking->status === BookingStatus::Completed;

        if (! $rateable) {
            throw DomainException::of(ErrorCode::TripNotRateable, fields: [
                'bookingStatus' => [strtoupper($booking->status->value)],
            ]);
        }

        if ($this->deadlineFor($booking)->isPast()) {
            throw DomainException::of(ErrorCode::RatingWindowClosed);
        }
    }

    /**
     * The last moment this booking may be rated.
     *
     * 🔴 A cancellation's window runs from the cancellation, not from the departure. A pause can
     * cancel a morning three weeks out, and measuring from the departure would both open the window
     * before anything had happened and keep it open for a month after she had made other
     * arrangements. What she is giving feedback about is the cancellation, so that is the event the
     * clock starts from.
     */
    public function deadlineFor(Booking $booking): CarbonInterface
    {
        $from = $booking->status === BookingStatus::CancelledByDriver && $booking->cancelled_at !== null
            ? $booking->cancelled_at
            : $booking->scheduledTrip->departure_at;

        return $from->copy()->addDays(RatingSettings::windowDays());
    }

    /**
     * Whether this is a review of a journey or feedback on a seat that vanished.
     *
     * 🔒 Derived from the booking and the reviewer, never accepted from the request — for the same
     * reason as `direction` and `reviewed_user_id`. The kind decides whether the rating is published
     * immediately and whether it counts towards an average, so a client that could name it could
     * publish a review at once and keep it out of its own mean.
     *
     * Only the PASSENGER of a driver-cancelled booking gets the cancellation kind. The driver asking
     * about the same booking falls through to the completed-ride path and is refused there, which is
     * the honest answer: she cannot rate somebody for a journey she called off.
     */
    private function kindFor(User $reviewer, Booking $booking, RatingDirection $direction): RatingKind
    {
        $hersToJudge = $booking->status === BookingStatus::CancelledByDriver
            && $direction === RatingDirection::PassengerToDriver;

        return $hersToJudge ? RatingKind::CanceledBooking : RatingKind::CompletedRide;
    }

    /**
     * 🔒 Which way round this rating goes — read from the booking, never from the request.
     *
     * A caller who is neither the passenger nor the driver on this booking gets a 404 rather than a
     * 403: whether a particular journey exists is not something a stranger may learn by asking.
     */
    private function directionFor(User $reviewer, Booking $booking): RatingDirection
    {
        if ($reviewer->id === $booking->passenger_user_id) {
            return RatingDirection::PassengerToDriver;
        }

        if ($reviewer->id === $booking->driver_profile_id) {
            return RatingDirection::DriverToPassenger;
        }

        throw DomainException::of(ErrorCode::NotFound);
    }

    private function otherParty(User $reviewer, Booking $booking): string
    {
        return $reviewer->id === $booking->passenger_user_id
            ? $booking->driver_profile_id
            : $booking->passenger_user_id;
    }

    private function existing(User $reviewer, Booking $booking): ?Rating
    {
        return Rating::query()
            ->where('booking_id', $booking->id)
            ->where('reviewer_user_id', $reviewer->id)
            ->first();
    }

    /**
     * Replaces the tags on a rating with exactly the ones given.
     *
     * Deleted and rewritten rather than diffed: the set is at most six rows, `(rating_id, tag)` is
     * unique, and a diff is where a duplicate-key error comes from on an edit that changed nothing.
     *
     * @param  array<int, string>  $tags
     */
    private function syncTags(Rating $rating, array $tags): void
    {
        $rating->tags()->delete();

        foreach (array_unique($tags) as $tag) {
            // Validated at the request, parsed here, so a tag that is not one of the six cannot
            // reach the table even if some other caller skips the FormRequest.
            RatingTag::query()->create([
                'rating_id' => $rating->id,
                'tag' => RatingTagValue::from($tag)->value,
            ]);
        }
    }
}
