<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Rating\Models\Rating;
use Illuminate\Support\Facades\Storage;

/**
 * Double-blind rating (Chapter 9, screen 37).
 *
 * 🔴 Pitfall #26 names the failure exactly: "if the API returned the rating before both sides
 * rated, the protection is broken even if the UI does not show it". So most of this file is about
 * what the API refuses to say, and three tests close paths that would defeat the design without
 * ever reading a hidden row:
 *
 *   · submission after the window — wait for the reveal, read hers, then write yours
 *   · editing after the reveal — rate five, read hers, revise to one
 *   · the AVERAGE — a mean that moves when a hidden rating lands states that rating's value
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

/*
|--------------------------------------------------------------------------
| Writing one
|--------------------------------------------------------------------------
*/

it('lets both parties rate a journey that happened', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $mine = rateBooking($this->paxToken, $this->bookingId, [
        'stars' => 5,
        'comment' => 'سواقة هادية ومواعيد مظبوطة.',
        'tags' => ['safe_driving', 'on_time'],
    ])->assertStatus(201)->json('data');

    expect($mine['stars'])->toBe(5)
        ->and($mine['direction'])->toBe('passenger_to_driver')
        ->and($mine['tags'])->toEqualCanonicalizing(['safe_driving', 'on_time'])
        // Nobody else can read it yet: the driver has not rated.
        ->and($mine['isVisible'])->toBeFalse()
        ->and($mine['editableUntil'])->not->toBeNull();
});

/**
 * 🔒 The same reasoning that keeps `reportedUserId` off an incident: a field naming who a rating is
 * about is a way to put stars, or a comment, against a stranger.
 */
it('reads who the rating is about from the journey, not from the request', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    rateBooking($this->paxToken, $this->bookingId, [
        // All ignored: there are no such fields.
        'userId' => User::query()->firstWhere('phone_e164', '+201223339999')?->id,
        'reviewedUserId' => 'whoever',
        'direction' => 'driver_to_passenger',
    ])->assertStatus(201);

    $rating = Rating::sole();

    $driverUserId = Booking::sole()->driver_profile_id;

    expect($rating->reviewed_user_id)->toBe($driverUserId)
        ->and($rating->direction->value)->toBe('passenger_to_driver');

    // And the stranger got nothing pointed at them.
    expect(Rating::query()->where('reviewed_user_id', '!=', $driverUserId)->count())->toBe(0);
});

it('refuses a journey the caller was not on', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    // 404, not 403: whether a particular journey happened is not learnable by asking about its id.
    rateBooking($stranger, $this->bookingId)->assertStatus(404);

    expect(Rating::count())->toBe(0);
});

it('refuses a journey that has not happened', function () {
    rateBooking($this->paxToken, $this->bookingId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRIP_NOT_RATEABLE')
        ->assertJsonPath('error.fields.bookingStatus.0', 'CONFIRMED');
});

/**
 * 🔒 Otherwise "book, then cancel" is a way to leave stars on somebody you never travelled with.
 */
it('refuses a journey that was cancelled', function () {
    Booking::query()->whereKey($this->bookingId)
        ->update(['status' => BookingStatus::CancelledByPassenger->value]);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(422);

    expect(Rating::count())->toBe(0);
});

it('allows one rating per person per journey', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(201);

    rateBooking($this->paxToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RATING_ALREADY_SUBMITTED');

    expect(Rating::count())->toBe(1);
});

it('refuses stars outside one to five', function (int $stars) {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => $stars])->assertStatus(422);
})->with([0, 6, -1, 99]);

it('takes a rating with no comment at all', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    // Somebody who had an uncomfortable ride may give three stars and not want to write about it,
    // and requiring a reason is how a rating screen becomes a form people abandon.
    rateBooking($this->paxToken, $this->bookingId, ['stars' => 3])->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| The reveal
|--------------------------------------------------------------------------
*/

it('reveals both ratings together, with one timestamp, once both exist', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201);

    expect(Rating::sole()->visible_at)->toBeNull();

    rateBooking($this->driverToken, $this->bookingId, ['stars' => 4])->assertStatus(201);

    $ratings = Rating::query()->get();

    expect($ratings)->toHaveCount(2)
        ->and($ratings->whereNull('visible_at'))->toHaveCount(0)
        // 🔒 The same moment for both. Revealing one before the other, even by the length of a
        // request, leaves a window in which one person can read the other's while hers is hidden.
        ->and($ratings->pluck('visible_at')->map->toIso8601String()->unique())->toHaveCount(1);
});

/**
 * 🔴 The half that stops silence from being a veto. Without it, a passenger who never rates keeps
 * her driver's rating hidden for ever, and not writing a review is the quietest way to suppress
 * one.
 */
it('reveals a one-sided rating once the window has passed', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(201);

    // Nothing is due yet.
    test()->artisan('ratings:reveal-due')->assertExitCode(0);
    expect(Rating::sole()->visible_at)->toBeNull();

    // The trip row is moved rather than the clock, which would expire the access token.
    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subDays(8)]);

    test()->artisan('ratings:reveal-due')->assertExitCode(0);

    expect(Rating::sole()->visible_at)->not->toBeNull();
});

/**
 * 🔴 Closes the front door on double-blind. If submission outlived the reveal window, somebody
 * could wait for the seventh day, read what she wrote about them, and only then answer it.
 */
it('closes submission when the reveal window closes', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subDays(8)]);

    rateBooking($this->paxToken, $this->bookingId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RATING_WINDOW_CLOSED');

    expect(Rating::count())->toBe(0);
});

it('follows the window length in settings', function () {
    PlatformSetting::query()->create([
        'setting_key' => 'rating.window_days',
        'setting_value' => 2,
        'value_type' => 'integer',
        'description' => 'A shorter window',
    ]);

    journeyCompleted($this->driverToken, $this->tripId);

    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subDays(3)]);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('lets somebody fix what they wrote while nobody can read it', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, [
        'stars' => 5,
        'comment' => 'سواقة هادية',
        'tags' => ['safe_driving'],
    ])->assertStatus(201)->json('data.id');

    $edited = test()->withToken($this->paxToken)->patchJson("/api/v1/ratings/{$id}", [
        'stars' => 4,
        'comment' => 'سواقة هادية بس اتأخرنا شوية',
        'tags' => ['safe_driving', 'comfortable'],
    ])->assertOk()->json('data');

    expect($edited['stars'])->toBe(4)
        ->and($edited['comment'])->toContain('اتأخرنا')
        ->and($edited['tags'])->toEqualCanonicalizing(['safe_driving', 'comfortable'])
        // Stated rather than inferred from `updated_at`, which moves for reasons that are not the
        // reviewer's — the reveal writes to this row too.
        ->and($edited['editedAt'])->not->toBeNull();
});

/**
 * 🔴 The attack this closes: rate five stars, wait for the reveal, read what she said, revise mine
 * to one. Two legitimate API calls, and the whole of double-blind gone — so an edit is refused the
 * moment the rating is visible, whatever the edit clock says.
 */
it('refuses an edit once the rating can be read', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201)->json('data.id');

    // The driver rates, which reveals both — well inside the edit window.
    rateBooking($this->driverToken, $this->bookingId, ['stars' => 1])->assertStatus(201);

    test()->withToken($this->paxToken)->patchJson("/api/v1/ratings/{$id}", ['stars' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RATING_NOT_EDITABLE');

    expect(Rating::query()->whereKey($id)->sole()->stars)->toBe(5);
});

it('refuses an edit once the edit window has run out', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201)->json('data.id');

    Rating::query()->whereKey($id)->update(['edit_deadline_at' => now()->subMinute()]);

    test()->withToken($this->paxToken)->patchJson("/api/v1/ratings/{$id}", ['stars' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RATING_NOT_EDITABLE');
});

/**
 * 🔒 404 rather than 409 or 403: confirming that a rating exists would itself tell the caller that
 * the other person has rated, which is the one fact the design withholds.
 */
it('refuses to edit somebody else rating, and says only that it is not found', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $id = rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)->patchJson("/api/v1/ratings/{$id}", ['stars' => 1])
        ->assertStatus(404);

    expect(Rating::query()->whereKey($id)->sole()->stars)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| The average — where double-blind leaks if nobody is watching
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The subtlest test in this file, and the reason `RecomputeRatingStatsAction` runs on reveal
 * rather than on submit.
 *
 * Filtering the rating QUERY is the obvious half. The half that leaks is the arithmetic: a driver
 * sitting on a known average who watches it move the moment a passenger rates her has read that
 * rating off the mean, to the star — and read it before writing her own.
 */
it('does not move an average until the rating can be read', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $driverUserId = Booking::sole()->driver_profile_id;

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201);

    expect(UserStat::query()->whereKey($driverUserId)->sole()->avg_rating_as_driver)->toBeNull();

    // Only once it is revealed.
    rateBooking($this->driverToken, $this->bookingId, ['stars' => 3])->assertStatus(201);

    expect((float) UserStat::query()->whereKey($driverUserId)->sole()->avg_rating_as_driver)->toBe(5.0);
});

it('fills the rating every other screen already reads', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 4])->assertStatus(201);
    rateBooking($this->driverToken, $this->bookingId, ['stars' => 5])->assertStatus(201);

    // PersonSummary has read these columns since Phase 6 and returned null for want of ratings.
    $booking = Booking::sole();

    $driverStat = UserStat::query()->whereKey($booking->driver_profile_id)->sole();
    $paxStat = UserStat::query()->whereKey($booking->passenger_user_id)->sole();

    expect((float) $driverStat->avg_rating_as_driver)->toBe(4.0)
        ->and((float) $paxStat->avg_rating_as_passenger)->toBe(5.0)
        // And the two directions do not bleed into each other.
        ->and($driverStat->avg_rating_as_passenger)->toBeNull()
        ->and($paxStat->avg_rating_as_driver)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| What is waiting to be rated
|--------------------------------------------------------------------------
*/

it('lists the journeys still waiting for the caller rating', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    $pending = test()->withToken($this->paxToken)->getJson('/api/v1/ratings/pending')
        ->assertOk()->json('data');

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['bookingId'])->toBe($this->bookingId)
        ->and($pending[0]['direction'])->toBe('passenger_to_driver')
        ->and($pending[0]['rateableUntil'])->not->toBeNull()
        // Who it is about, in the shape every other screen uses.
        ->and($pending[0]['person']['publicFirstName'])->toBe('مريم');
});

it('drops a journey off the pending list once it has been rated', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId)->assertStatus(201);

    expect(test()->withToken($this->paxToken)->getJson('/api/v1/ratings/pending')
        ->assertOk()->json('data'))->toBe([]);
});

/**
 * 🔒 "They are waiting for you" is exactly the fact double-blind withholds, so the pending list
 * must not carry it in any form.
 */
it('says nothing about whether the other person has rated', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    // The driver rates first.
    rateBooking($this->driverToken, $this->bookingId, ['stars' => 2])->assertStatus(201);

    $row = test()->withToken($this->paxToken)->getJson('/api/v1/ratings/pending')
        ->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('otherHasRated')
        ->not->toHaveKey('theirRating')
        ->not->toHaveKey('awaitingYou');

    // And nothing in the serialised row carries the stars they gave.
    expect(json_encode($row))->not->toContain('"stars"');
});

it('drops a journey off the pending list once the window has closed', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    ScheduledTrip::query()->whereKey($this->tripId)->update(['departure_at' => now()->subDays(8)]);

    expect(test()->withToken($this->paxToken)->getJson('/api/v1/ratings/pending')
        ->assertOk()->json('data'))->toBe([]);
});

it('shows a person their own ratings, hidden or not', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 5, 'comment' => 'ممتازة'])->assertStatus(201);

    // Their own, so no visibility filter applies: a person may always read what they said.
    $mine = test()->withToken($this->paxToken)->getJson('/api/v1/ratings/mine')
        ->assertOk()->json('data');

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['comment'])->toBe('ممتازة')
        ->and($mine[0]['isVisible'])->toBeFalse();
});

it('never shows one person ratings to another', function () {
    journeyCompleted($this->driverToken, $this->tripId);

    rateBooking($this->paxToken, $this->bookingId, ['stars' => 5])->assertStatus(201);

    expect(test()->withToken($this->driverToken)->getJson('/api/v1/ratings/mine')
        ->assertOk()->json('data'))->toBe([]);
});

it('requires a signed-in account', function () {
    test()->withoutToken()->getJson('/api/v1/ratings/pending')->assertStatus(401);
    test()->withoutToken()->postJson("/api/v1/bookings/{$this->bookingId}/rating", ['stars' => 5])
        ->assertStatus(401);
});
