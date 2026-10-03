<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Rating\Actions\RecomputeRatingStatsAction;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\ReviewReport;
use Illuminate\Support\Facades\Storage;

/**
 * Reviews as a stranger sees them (screen 12's "التقييمات"), and as their subject does.
 *
 * 🔒 The judgement this file pins, because the sources are silent on it: **a review carries no
 * reviewer and no exact date.**
 *
 * A commute seats one to three people. A review dated to the day therefore identifies the journey,
 * and the journey identifies the person — so a precise date names the reviewer even when the
 * payload does not. That matters more here than on an ordinary marketplace for one concrete
 * reason: the driver already has the passenger's pickup point. She knows her front door. A
 * passenger who writes honestly about a driver who can find her house, and who can be identified
 * from the date, is exposed in a way an anonymous shopper never is — and the result is not fairer
 * reviews, it is quieter ones.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');

    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

/**
 * Both sides rate on this file's reference journey, which reveals everything.
 *
 * A thin wrapper over the shared `bothRated()` so each test reads as one line; the defaults are
 * this file's story — a five-star review with words and tags on it.
 */
function reviewed(int $paxStars = 5, ?string $comment = null): void
{
    bothRated(
        test()->driverToken,
        test()->paxToken,
        test()->tripId,
        test()->bookingId,
        passengerPayload: [
            'stars' => $paxStars,
            'comment' => $comment ?? 'سواقة هادية ومواعيد مظبوطة.',
            'tags' => ['safe_driving', 'on_time'],
        ],
    );
}

/*
|--------------------------------------------------------------------------
| The driver's reviews, on a commute
|--------------------------------------------------------------------------
*/

it('shows a passenger the driver reviews before she asks for a seat', function () {
    reviewed();

    $reviews = test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/reviews")
        ->assertOk()->json('data');

    expect($reviews)->toHaveCount(1)
        ->and($reviews[0]['stars'])->toBe(5)
        ->and($reviews[0]['comment'])->toContain('هادية')
        ->and($reviews[0]['tags'])->toEqualCanonicalizing(['safe_driving', 'on_time'])
        ->and($reviews[0]['direction'])->toBe('passenger_to_driver')
        // The month, not the day.
        ->and($reviews[0]['month'])->toBe(now()->format('Y-m'));
});

/**
 * 🔒 The central privacy property of this feature. See the file note.
 */
it('names nobody and dates nothing to the day', function () {
    reviewed();

    $review = test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/reviews")
        ->assertOk()->json('data.0');

    expect($review)->not->toHaveKey('reviewer')
        ->not->toHaveKey('reviewerUserId')
        ->not->toHaveKey('publicFirstName')
        ->not->toHaveKey('person')
        ->not->toHaveKey('createdAt')
        ->not->toHaveKey('visibleAt')
        // 🔒 And no booking id: it would let the subject look the journey up and read the
        // reviewer's name off its passenger list.
        ->not->toHaveKey('bookingId');

    // Nothing in the serialised row carries the reviewer's name or a full timestamp.
    expect(json_encode($review, JSON_UNESCAPED_UNICODE))
        ->not->toContain('سارة')
        ->not->toContain(now()->toDateString());
});

/**
 * 🔴 Pitfall #26. The filter is in the query, and no parameter can take it out.
 */
it('never shows a review that has not been revealed', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    // One side only, so nothing is visible.
    test()->withToken($this->paxToken)->postJson("/api/v1/bookings/{$this->bookingId}/rating", [
        'stars' => 1,
        'comment' => 'ماوصلتش في الميعاد.',
    ])->assertStatus(201);

    $reviews = test()->withToken($this->paxToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/reviews")
        ->assertOk();

    expect($reviews->json('data'))->toBe([]);

    // And the words are nowhere in the response, not merely hidden from the list.
    expect($reviews->getContent())->not->toContain('ماوصلتش');
});

it('shows only the reviews of this commute driver', function () {
    reviewed();

    // A second driver with her own commute and no reviews at all.
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01555666777', devicePublicId: 'driver-2', seed: 2);
    $otherCommute = readyCommute($otherDriver, Vehicle::query()->where('plate_number', 'ABC 1236')->sole()->id);

    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$otherCommute}/publish")->assertOk();

    expect(test()->withToken($this->paxToken)->getJson("/api/v1/commutes/{$otherCommute}/reviews")
        ->assertOk()->json('data'))->toBe([]);
});

it('refuses a commute that does not exist', function () {
    test()->withToken($this->paxToken)
        ->getJson('/api/v1/commutes/'.str_repeat('0', 26).'/reviews')
        ->assertStatus(404);
});

it('requires a signed-in account', function () {
    test()->withoutToken()->getJson("/api/v1/commutes/{$this->commuteId}/reviews")->assertStatus(401);
    test()->withoutToken()->getJson('/api/v1/ratings/about-me')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| What was said about me
|--------------------------------------------------------------------------
*/

it('lets a driver read what was said about her', function () {
    reviewed();

    $about = test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk()->json('data');

    expect($about)->toHaveCount(1)
        ->and($about[0]['stars'])->toBe(5)
        ->and($about[0]['direction'])->toBe('passenger_to_driver');
});

/**
 * 🔒 Anonymous to the subject too, and this is the point rather than an oversight. Telling somebody
 * who gave them two stars IS the retaliation vector — and she already knows where that passenger
 * waits each morning.
 */
it('does not tell the subject who said it', function () {
    reviewed(paxStars: 2, comment: 'حسّيت إني مش مرتاحة في الرحلة.');

    $about = test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk();

    expect($about->json('data.0'))->not->toHaveKey('reviewer')
        ->not->toHaveKey('bookingId')
        ->not->toHaveKey('createdAt');

    expect($about->getContent())->not->toContain('سارة');
});

it('does not show the subject a review before it is revealed', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    test()->withToken($this->paxToken)->postJson("/api/v1/bookings/{$this->bookingId}/rating", [
        'stars' => 1,
    ])->assertStatus(201);

    // 🔴 Including her: double-blind is symmetric, and a driver who could read it early would be
    // writing her own rating with knowledge of it.
    expect(test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk()->json('data'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Reporting one
|--------------------------------------------------------------------------
*/

it('lets the subject report a review, and leaves the review standing', function () {
    reviewed(paxStars: 1, comment: 'كلام مش لائق.');

    $id = test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk()->json('data.0.id');

    $report = test()->withToken($this->driverToken)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'المراجعة فيها إساءة شخصية.',
    ])->assertStatus(201)->json('data');

    expect($report['status'])->toBe('PENDING')
        // 🔴 Said plainly, because the expectation matters: a client that implied a takedown would
        // be promising something only a human can decide.
        ->and($report['reviewRemains'])->toBeTrue();

    expect(ReviewReport::sole()->reason)->toContain('إساءة')
        // Flagged, which is a QUEUE and not a verdict.
        ->and(Rating::query()->whereKey($id)->sole()->moderation_status)->toBe(ModerationStatus::Flagged);
});

/**
 * 🔴 The reason the flag does not hide anything. If a report removed the review, or dropped it from
 * the average, then "report every review under four stars" would be a mechanical way to launder a
 * record — and the people most motivated to do it are exactly the ones a rating system exists to
 * surface.
 */
it('keeps a reported review on the profile and in the average', function () {
    reviewed(paxStars: 1);

    $id = test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk()->json('data.0.id');

    test()->withToken($this->driverToken)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'مش عاجبني التقييم ده.',
    ])->assertStatus(201);

    expect(test()->withToken($this->paxToken)->getJson("/api/v1/commutes/{$this->commuteId}/reviews")
        ->assertOk()->json('data'))->toHaveCount(1);

    $driverUserId = Booking::sole()->driver_profile_id;

    app(RecomputeRatingStatsAction::class)->execute($driverUserId);

    expect((float) UserStat::query()->whereKey($driverUserId)
        ->sole()->avg_rating_as_driver)->toBe(1.0);
});

/**
 * 🔒 A moderator's decision DOES remove it. The difference between `flagged` and `hidden` is the
 * difference between a complaint and a judgement.
 */
it('drops a review a moderator has hidden, from the profile and the average', function () {
    reviewed(paxStars: 1);

    Rating::query()->where('direction', 'passenger_to_driver')
        ->update(['moderation_status' => ModerationStatus::Hidden->value]);

    expect(test()->withToken($this->paxToken)->getJson("/api/v1/commutes/{$this->commuteId}/reviews")
        ->assertOk()->json('data'))->toBe([]);

    $driverUserId = Booking::sole()->driver_profile_id;

    app(RecomputeRatingStatsAction::class)->execute($driverUserId);

    expect(UserStat::query()->whereKey($driverUserId)
        ->sole()->avg_rating_as_driver)->toBeNull();
});

it('refuses to report the same review twice', function () {
    reviewed();

    $id = test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk()->json('data.0.id');

    test()->withToken($this->driverToken)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'سبب أول.',
    ])->assertStatus(201);

    test()->withToken($this->driverToken)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'سبب تاني.',
    ])->assertStatus(409)->assertJsonPath('error.code', 'REVIEW_ALREADY_REPORTED');

    expect(ReviewReport::count())->toBe(1);
});

/**
 * 🔒 Only the subject. Not the person who wrote it, and not a passer-by: a stranger reporting other
 * people's reviews is a way to put a moderation queue to work against somebody.
 */
it('refuses a report from anybody but the subject', function () {
    reviewed();

    $id = Rating::query()->where('direction', 'passenger_to_driver')->sole()->id;

    // The reviewer herself.
    test()->withToken($this->paxToken)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'عاوزة أمسح اللي كتبته.',
    ])->assertStatus(404);

    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    test()->withToken($stranger)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'مش عاجبني.',
    ])->assertStatus(404);

    expect(ReviewReport::count())->toBe(0);
});

/**
 * 🔒 404-shaped, so it is indistinguishable from a wrong id: a hidden rating is one nobody has
 * seen, including its subject, so a report on it could only come from having been told.
 */
it('refuses a report on a review nobody can read yet', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = test()->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 1])
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)->postJson("/api/v1/ratings/{$id}/report", [
        'reason' => 'إساءة.',
    ])->assertStatus(404);

    expect(ReviewReport::count())->toBe(0);
});

it('requires a reason to report', function () {
    reviewed();

    $id = test()->withToken($this->driverToken)->getJson('/api/v1/ratings/about-me')
        ->assertOk()->json('data.0.id');

    // A moderation queue with no reason on the row is a queue a human has to guess at.
    test()->withToken($this->driverToken)->postJson("/api/v1/ratings/{$id}/report", [])
        ->assertStatus(422);
});
