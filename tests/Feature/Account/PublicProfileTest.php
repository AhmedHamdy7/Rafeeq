<?php

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;

/**
 * Feature 02 v3: `GET /users/{id}/public-profile` — "Public viewers see user name, permitted public
 * profile information, rating/reviews/trip count, and **verified driver badge only when actually
 * approved**; **never** see documents, ID images, DOB, private admin notes."
 *
 * 🔒 Most of this file is about the second half of that sentence. This is the one payload in the API
 * reachable for an ARBITRARY user id, so what it refuses to say matters more than what it says.
 */
function publicProfile(string $token, string $userId)
{
    return test()->withToken($token)->getJson("/api/v1/users/{$userId}/public-profile");
}

it('shows a driver to a passenger who has never met her', function () {
    $driverToken = approvedDriver();
    $driver = User::sole();

    $paxToken = verifiedPassenger('01099887766');

    $response = publicProfile($paxToken, $driver->getKey())->assertOk();

    expect($response->json('data.publicFirstName'))->not->toBeEmpty()
        ->and($response->json('data.isVerifiedDriver'))->toBeTrue()
        ->and($response->json('data.joinedMonth'))->toMatch('/^\d{4}-\d{2}$/')
        ->and($response->json('data'))->toHaveKey('asDriver')
        ->and($response->json('data.reviews.count'))->toBe(0);
});

/**
 * 🔒 The privacy promises, one assertion each, because each is a separate decision somebody could
 * undo without noticing they had.
 */
it('never leaks the things it is not allowed to say', function () {
    $driverToken = approvedDriver();
    $driver = User::sole();
    $driver->forceFill(['date_of_birth' => '1990-04-12', 'email' => 'nour@example.com'])->save();

    $paxToken = verifiedPassenger('01099887766');

    $response = publicProfile($paxToken, $driver->getKey())->assertOk();
    $body = $response->getContent();
    $data = $response->json('data');

    expect($data)->not->toHaveKey('fullName')
        ->and($data)->not->toHaveKey('phone')
        ->and($data)->not->toHaveKey('email')
        ->and($data)->not->toHaveKey('dateOfBirth')
        ->and($data)->not->toHaveKey('gender')
        // And not merely absent from the top level — nowhere in the payload at all.
        ->and($body)->not->toContain($driver->full_name)
        ->and($body)->not->toContain($driver->phone_e164)
        ->and($body)->not->toContain('nour@example.com')
        ->and($body)->not->toContain('1990-04-12')
        ->and($body)->not->toContain('gender');
});

/**
 * 🔴 v3: "verified driver badge **only when actually approved**". This is the field a passenger uses
 * to decide a stranger is safe to ride with, so applying must never look like approval.
 */
it('withholds the driver badge from somebody who has merely applied', function () {
    $applicantToken = readyDriverApplicant();
    completeDriverApplication($applicantToken);
    $applicant = User::sole();

    $paxToken = verifiedPassenger('01099887766');

    expect(publicProfile($paxToken, $applicant->getKey())->assertOk()->json('data.isVerifiedDriver'))
        ->toBeFalse();
});

it('omits the driving numbers entirely for somebody who does not drive', function () {
    $paxToken = verifiedPassenger('01099887766');
    $subject = User::whereNotNull('phone_e164')->sole();

    $viewerToken = verifiedPassenger('01077665544', device: 'pax-2');

    $data = publicProfile($viewerToken, $subject->getKey())->assertOk()->json('data');

    /*
     * Absent, not zeroed. A `0` here renders as a driver with a terrible record rather than a
     * person who has never driven, and that difference is somebody's reputation.
     */
    expect($data)->not->toHaveKey('asDriver')
        ->and($data['asPassenger'])->toHaveKeys(['completedTrips', 'rating', 'onTimeRate'])
        // Null, never 0: nobody has rated her, which is not the same as rating her nought.
        ->and($data['asPassenger']['rating'])->toBeNull();
});

/**
 * 🔒 A 404 for a suspended account, and the SAME 404 as for an id that never existed.
 *
 * Distinguishing them would make this an oracle: "this id exists but is suspended" is a fact about
 * somebody that nobody outside the safety team is owed, and it is readable by anyone who can type a
 * user id.
 */
it('answers the same 404 for a suspended account as for one that does not exist', function () {
    $driverToken = approvedDriver();
    $driver = User::sole();

    $paxToken = verifiedPassenger('01099887766');

    $driver->forceFill(['account_status' => AccountStatus::Suspended->value])->save();

    $suspended = publicProfile($paxToken, $driver->getKey())->assertStatus(404);
    $nobody = publicProfile($paxToken, '01HQZZZZZZZZZZZZZZZZZZZZZZ')->assertStatus(404);

    expect($suspended->json('error.code'))->toBe($nobody->json('error.code'))
        ->and($suspended->json('error.message'))->toBe($nobody->json('error.message'));
});

/**
 * 🔴 Deliberately NOT behind the verified tier. Checking out the driver she is about to ride with is
 * the thing we most want a passenger to do, and she now needs no verification to book at all.
 */
it('is readable by an unverified passenger, because looking somebody up is not a risk', function () {
    $driverToken = approvedDriver();
    $driver = User::sole();

    $unverified = signIn(phone: '01555554444', devicePublicId: 'pax-unverified')['session']['accessToken'];
    completeBasicProfile($unverified);

    publicProfile($unverified, $driver->getKey())->assertOk();
});

it('refuses an anonymous caller', function () {
    $driverToken = approvedDriver();
    $driver = User::sole();

    /*
     * `flushHeaders()` is load-bearing. `withToken()` sets a DEFAULT header on the test instance
     * that persists across every later request, so the helpers above leave this one authenticated
     * as the driver — and without this line the test passes while proving nothing, which is the
     * worst kind of green.
     */
    test()->flushHeaders()
        ->getJson("/api/v1/users/{$driver->getKey()}/public-profile")
        ->assertStatus(401);
});
