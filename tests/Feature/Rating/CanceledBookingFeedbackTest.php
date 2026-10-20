<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Actions\RecomputeRatingStatsAction;
use App\Domains\Rating\Enums\RatingKind;
use App\Domains\Rating\Models\Rating;

/**
 * Feedback on a seat that was cancelled (Feature 03 v5).
 *
 * > *"Passenger affected by **canceled confirmed booking** may leave rating/comment even if no ride
 * > occurred. Display as **Canceled Booking Feedback**, distinct from completed-ride review;
 * > visible immediately. Driver can report comment for Admin review."*
 *
 * 🔴 This reverses a rule the codebase stated in writing. `assertRateable()` refused anything but a
 * completed booking, with the reasoning: *"A cancelled or no-show booking is not a shared ride to
 * have an opinion about, and letting it be rated would make 'book then cancel' a way to leave stars
 * on somebody."*
 *
 * That reasoning survives — and is what decides the shape of the exception. The attack it names is
 * a PASSENGER booking and cancelling to leave stars on a driver. So only a booking the **driver**
 * cancelled is rateable, and only by the passenger who lost the seat. A passenger who cancels her
 * own seat can still rate nobody, which is exactly the hole the old rule was protecting.
 */
beforeEach(function () {
    $this->driverToken = approvedDriver();
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->requestId = requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $this->tripId])
        ->assertStatus(201)->json('data.id');
    $this->bookingId = approveSeat($this->driverToken, $this->requestId);
});

/**
 * The driver cancels the seat she had confirmed.
 */
function cancelledByHer(string $driverToken, string $bookingId): void
{
    test()->flushHeaders()->withToken($driverToken)
        ->patchJson("/api/v1/bookings/{$bookingId}/cancel")
        ->assertOk();
}

it('lets the passenger rate a seat the driver cancelled', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    $rating = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", [
            'stars' => 2,
            'comment' => 'Cancelled the night before and I had no way to get in.',
        ])
        ->assertStatus(201)
        ->json('data');

    expect($rating['kind'])->toBe('canceled_booking')
        ->and($rating['stars'])->toBe(2);
});

/**
 * 🔴 v5: *"visible immediately"* — and the reason matters more than the rule. Double-blind exists
 * so neither person can read the other's rating before answering it. The driver cannot rate a
 * passenger for a journey that did not happen, so there is no pair to reveal and nothing to
 * retaliate against; holding it back would delay it for nobody's protection.
 */
it('publishes the feedback at once, with no second rating to wait for', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 1])
        ->assertStatus(201);

    $rating = Rating::sole();

    expect($rating->visible_at)->not->toBeNull()
        ->and($rating->kind)->toBe(RatingKind::CanceledBooking);
});

/**
 * 🔴 And it does not move her star average. v5 displays this "distinct from completed-ride review"
 * and gives cancellations their own instrument — the Reliability Score. The stars answer "what is
 * she like to ride with", and a cancellation is not a ride; counting it would both answer that
 * question with evidence about something else and punish the same event twice.
 */
it('keeps the cancellation out of the driver’s star average', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 1])
        ->assertStatus(201);

    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    app(RecomputeRatingStatsAction::class)->execute($driver->id);

    $driver->load('stats');

    // Null, not 1.0: nobody has reviewed a journey with her, and a cancellation is not one.
    expect($driver->stats->avg_rating_as_driver)->toBeNull();
});

/**
 * 🔴 The hole the old rule was protecting, still shut: a passenger who cancels her own seat may
 * not rate the driver. Otherwise "book then cancel" is a way to leave stars on somebody.
 */
it('refuses to let a passenger rate a seat she cancelled herself', function () {
    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")
        ->assertOk();

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRIP_NOT_RATEABLE');

    expect(Rating::query()->count())->toBe(0);
});

/**
 * 🔒 And the driver does not get to rate a passenger for a ride that did not happen — least of all
 * one she cancelled herself. v5 gives the feedback to the passenger affected, and only to her.
 */
it('refuses to let the driver rate the passenger she cancelled', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRIP_NOT_RATEABLE');
});

/**
 * 🔴 A displacement is a driver cancellation, so the women it displaces get the same say. Pausing a
 * commute cancels every future confirmed seat, and v5's own pause dialog warns that late
 * cancellation fees may apply — it is her decision and it lands on them.
 */
it('lets a passenger displaced by a pause leave feedback', function () {
    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    expect(Booking::findOrFail($this->bookingId)->status->value)->toBe('cancelled_by_driver');

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", [
            'stars' => 2,
            'comment' => 'Paused with no notice and I had to find another way in.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.kind', 'canceled_booking');
});

/**
 * 🔴 The ordinary rule refuses an edit once a rating is readable, because "rate five stars, wait
 * for the reveal, read hers, revise mine to one" would otherwise be two legitimate calls. With no
 * other rating to read, that attack does not exist — so the edit window goes back to being what it
 * is for, and v5 asks for editable feedback.
 */
it('lets her correct what she wrote even though it is already visible', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    $ratingId = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 2, 'comment' => 'Typo here'])
        ->assertStatus(201)->json('data.id');

    // `stars` travels with every edit: the endpoint shares its FormRequest with submission, where
    // stars are mandatory. Not a quirk of this kind of feedback.
    test()->flushHeaders()->withToken($this->paxToken)
        ->patchJson("/api/v1/ratings/{$ratingId}", ['stars' => 2, 'comment' => 'Fixed'])
        ->assertOk()
        ->assertJsonPath('data.comment', 'Fixed');
});

/**
 * 🔒 A completed ride's review is still double-blind and still uneditable once visible. The
 * exception above is scoped to the kind that has no counterpart, and this pins that it did not
 * leak.
 */
it('leaves the double-blind rule alone for a journey that happened', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $ratingId = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 5])
        ->assertStatus(201)->json('data.id');

    $rating = Rating::findOrFail($ratingId);

    expect($rating->kind)->toBe(RatingKind::CompletedRide)
        // Hidden until the driver rates too, exactly as before.
        ->and($rating->visible_at)->toBeNull();
});

/**
 * v5: *"Driver can report comment for Admin review."* The existing report route takes it, because
 * this is a rating like any other as far as moderation is concerned.
 */
it('lets the driver report the comment for admin review', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    $ratingId = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", [
            'stars' => 1,
            'comment' => 'Something she considers unfair.',
        ])
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/ratings/{$ratingId}/report", ['reason' => 'This is not what happened at all.'])
        ->assertStatus(201);
});

/**
 * 🔴 Shown under its own heading on her profile, separate from the reviews of journeys — which is
 * what v5 means by "distinct from completed-ride review".
 */
it('shows the feedback separately on the driver’s public profile', function () {
    cancelledByHer($this->driverToken, $this->bookingId);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", [
            'stars' => 2,
            'comment' => 'Cancelled the night before.',
        ])->assertStatus(201);

    $driver = User::query()->where('phone_e164', '+201012345678')->sole();

    $profile = test()->flushHeaders()->withToken($this->paxToken)
        ->getJson("/api/v1/users/{$driver->getKey()}/public-profile")
        ->assertOk()->json('data');

    expect($profile['canceledBookingFeedback']['count'])->toBe(1)
        ->and($profile['canceledBookingFeedback']['items'][0]['comment'])->toBe('Cancelled the night before.')
        // 🔴 And it is NOT mixed in with the reviews of journeys.
        ->and($profile['reviews']['count'])->toBe(0);
});
