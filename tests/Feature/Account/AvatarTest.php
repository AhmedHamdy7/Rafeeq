<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Actions\DeriveAvatarAction;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\DocumentStorage;

/**
 * Feature 02 v3 §2: "Before identity verification show initials; after approval show approved
 * verification photo. **No ordinary edit-photo control.**" and "Once a **Driver's** identity is
 * approved, the verified photo replaces initials in both modes. A **Passenger-only account always
 * shows initials**."
 *
 * 🔒 The second sentence is the one worth testing hardest. A passenger who verifies her identity —
 * which many will, because it raises her trust level — must NOT have her face published to
 * strangers. Her photo was collected to prove who she is to a reviewer, and publishing it because
 * she happened to verify is a change of purpose on data handed over for something else.
 */
function approveSelfieFor(string $token): UserVerification
{
    uploadVerificationDocument($token, 'selfie', 'selfie');
    test()->withToken($token)->postJson('/api/v1/account/verifications/selfie/submit')->assertOk();

    $verification = UserVerification::query()
        ->where('type', VerificationType::Selfie->value)
        ->latest('created_at')
        ->sole();

    app(ReviewVerificationAction::class)->approve($verification, adminWithRole(AdminRole::Verification));

    return $verification->refresh();
}

it('derives an avatar from the approved selfie rather than from anything uploaded', function () {
    $driverToken = approvedDriver();

    approveSelfieFor($driverToken);

    $driver = User::sole();

    expect($driver->profile_photo_path)->not->toBeNull()
        // 🔒 A NEW file, not the document. Nothing in the avatar path points at the stored upload.
        ->and($driver->profile_photo_path)->toContain('avatar-')
        ->and(app(DocumentStorage::class)->disk()->exists($driver->profile_photo_path))->toBeTrue();

    // And it really is a decodable square image this server produced, not a copied blob.
    $bytes = (string) app(DocumentStorage::class)->disk()->get($driver->profile_photo_path);
    $size = getimagesizefromstring($bytes);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(256)
        ->and($size[1])->toBe(256)
        ->and($size['mime'])->toBe('image/jpeg');
});

it('publishes an approved driver`s photo on her public profile', function () {
    $driverToken = approvedDriver();
    approveSelfieFor($driverToken);
    $driver = User::sole();

    $paxToken = verifiedPassenger('01099887766');

    $data = test()->withToken($paxToken)
        ->getJson("/api/v1/users/{$driver->getKey()}/public-profile")
        ->assertOk()->json('data');

    expect($data['avatarPath'])->not->toBeNull();

    test()->withToken($paxToken)
        ->get("/api/v1/users/{$driver->getKey()}/avatar")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

/**
 * 🔒 The rule that is easy to get wrong, and expensive if you do.
 */
it('keeps a verified passenger on initials, and refuses to serve her face at all', function () {
    $paxToken = verifiedPassenger('01099887766');
    approveSelfieFor($paxToken);

    $passenger = User::query()->where('phone_e164', '+201099887766')->sole();

    // The file was derived — she may become a driver later and the work is done.
    expect($passenger->profile_photo_path)->not->toBeNull();

    $viewerToken = verifiedPassenger('01077665544', device: 'pax-2');

    // But nothing publishes it.
    $data = test()->withToken($viewerToken)
        ->getJson("/api/v1/users/{$passenger->getKey()}/public-profile")
        ->assertOk()->json('data');

    expect($data['avatarPath'])->toBeNull();

    test()->withToken($viewerToken)
        ->get("/api/v1/users/{$passenger->getKey()}/avatar")
        ->assertStatus(404);
});

it('shows initials until a reviewer has actually approved the selfie', function () {
    $driverToken = approvedDriver();

    uploadVerificationDocument($driverToken, 'selfie', 'selfie');
    test()->withToken($driverToken)->postJson('/api/v1/account/verifications/selfie/submit')->assertOk();

    // Submitted, not approved.
    expect(User::sole()->profile_photo_path)->toBeNull();
});

/**
 * 🔴 Driven through the Action, not the API, and the reason is worth recording.
 *
 * Re-submitting an approved selfie is refused — `VERIFICATION_ALREADY_APPROVED` — so there is today
 * **no route** by which a second avatar can be derived. v3 says identity correction "opens a
 * controlled re-verification flow", and that flow is not built. So the replacement path is reachable
 * only from inside.
 *
 * The code stays and is tested here anyway: it is three lines, it is correct, and the alternative is
 * building the correction flow later on top of an untested assumption that replacing an avatar
 * cleans up after itself.
 */
it('replaces the previous avatar and leaves no orphan behind', function () {
    $driverToken = approvedDriver();

    $verification = approveSelfieFor($driverToken);
    $first = User::sole()->profile_photo_path;

    app(DeriveAvatarAction::class)->execute($verification);
    $second = User::sole()->profile_photo_path;

    $disk = app(DocumentStorage::class)->disk();

    expect($second)->not->toBe($first)
        ->and($disk->exists($second))->toBeTrue()
        /*
         * 🔒 The old one is gone. An unreachable face photo is still a face photo we are keeping,
         * and "nothing links to it" is not a retention policy.
         */
        ->and($disk->exists($first))->toBeFalse();
});

it('is not served to a stranger who is not signed in', function () {
    $driverToken = approvedDriver();
    approveSelfieFor($driverToken);
    $driver = User::sole();

    // `flushHeaders`: `withToken` sets a default header that would otherwise authenticate this.
    test()->flushHeaders()
        ->get("/api/v1/users/{$driver->getKey()}/avatar")
        ->assertStatus(401);
});
