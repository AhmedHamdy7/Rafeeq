<?php

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;

/**
 * MASTER_PLAN §247: a progress bar reading "Trust level N of 4" and four rows,
 * each in one of four states — ✓ verified · ⚠ required · ⏳ under review ·
 * ✗ needs action with a clear reason.
 */
beforeEach(function () {
    $this->session = signIn();
    $this->token = $this->session['session']['accessToken'];
});

function centre(string $token): array
{
    return test()->withToken($token)
        ->getJson('/api/v1/account/verifications')
        ->assertOk()
        ->json('data');
}

it('grants level 1 for the verified phone the moment someone signs in', function () {
    $centre = centre($this->token);

    expect($centre['level'])->toBe(1)
        ->and($centre['of'])->toBe(4)
        ->and($centre['percentage'])->toBe(25)
        ->and(User::sole()->trust_level)->toBe(1);

    $phone = collect($centre['rows'])->firstWhere('type', 'phone');

    expect($phone['status'])->toBe('APPROVED')
        ->and($phone['method'])->toBe('otp')
        // Nothing to upload and nobody to review: the OTP already proved it.
        ->and($phone['needsReview'])->toBeFalse()
        ->and($phone['requiredDocuments'])->toBe([]);
});

it('lists all four levels in a stable order, cheapest evidence first', function () {
    expect(collect(centre($this->token)['rows'])->pluck('type')->all())
        ->toBe(['phone', 'government_id', 'selfie', 'organization']);
});

it('tells the app exactly which documents a level still needs', function () {
    $row = collect(centre($this->token)['rows'])->firstWhere('type', 'government_id');

    expect($row['status'])->toBe('NOT_STARTED')
        ->and($row['requiredDocuments'])->toBe(['national_id_front', 'national_id_back'])
        ->and($row['missingDocuments'])->toBe(['national_id_front', 'national_id_back'])
        ->and($row['needsReview'])->toBeTrue();
});

it('shows a submitted level as under review, with nothing to act on', function () {
    submitGovernmentId($this->token);

    $row = collect(centre($this->token)['rows'])->firstWhere('type', 'government_id');

    expect($row['status'])->toBe('PENDING')
        ->and($row['submittedAt'])->not->toBeNull()
        ->and($row['actionNeededReason'])->toBeNull()
        // Progress does not move on submission — only a decision moves it.
        ->and(centre($this->token)['level'])->toBe(1);
});

it('shows a rejected level with the reviewer reason, verbatim', function () {
    submitGovernmentId($this->token);

    rejectVerification(VerificationType::GovernmentId, 'The back of your ID is cut off. Please retake it.');

    $row = collect(centre($this->token)['rows'])->firstWhere('type', 'government_id');

    expect($row['status'])->toBe('ACTION_NEEDED')
        ->and($row['actionNeededReason'])->toBe('The back of your ID is cut off. Please retake it.');
});

it('moves progress only when a reviewer approves', function () {
    submitGovernmentId($this->token);

    expect(centre($this->token)['level'])->toBe(1);

    approveVerification(VerificationType::GovernmentId);

    $centre = centre($this->token);

    expect($centre['level'])->toBe(2)
        ->and($centre['percentage'])->toBe(50)
        ->and(User::sole()->trust_level)->toBe(2);
});

/**
 * An approval that has lapsed is not an approval. Showing a badge for an
 * expired document is exactly the stale trust signal the feature exists to
 * avoid.
 */
it('stops counting a level whose document has expired', function () {
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId, expiresAt: now()->addDay());

    expect(centre($this->token)['level'])->toBe(2);

    $this->travel(2)->days();

    // The original access token is long dead by now — 15 minutes, by design.
    // Signing in again is what a returning person would do anyway.
    fakeOtpSender();
    $token = signIn(devicePublicId: 'dev-1')['session']['accessToken'];

    $centre = centre($token);
    $row = collect($centre['rows'])->firstWhere('type', 'government_id');

    expect($row['status'])->toBe('EXPIRED')
        ->and($centre['level'])->toBe(1);
});

it('counts down the attempts a level has left', function () {
    $max = (int) config('rafeeq.verification.max_submission_attempts');

    $row = collect(centre($this->token)['rows'])->firstWhere('type', 'government_id');
    expect($row['attemptsRemaining'])->toBe($max);

    submitGovernmentId($this->token);

    $row = collect(centre($this->token)['rows'])->firstWhere('type', 'government_id');
    expect($row['attemptsRemaining'])->toBe($max - 1);
});

it('never exposes a document path or an internal trust score', function () {
    submitGovernmentId($this->token);

    $body = $this->withToken($this->token)->getJson('/api/v1/account/verifications')->getContent();

    expect($body)->not->toContain('file_path')
        ->and($body)->not->toContain('users/')   // the storage path prefix
        ->and($body)->not->toContain('score');
});

it('stays reachable while an account is suspended, so an appeal is possible', function () {
    User::sole()->forceFill(['account_status' => 'suspended'])->save();

    $this->withToken($this->token)->getJson('/api/v1/account/verifications')->assertOk();

    // But submitting new evidence is not.
    $this->withToken($this->token)
        ->postJson('/api/v1/account/verifications/government_id/submit')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
});

it('answers 404 for a level that does not exist', function () {
    // Past the profile gate first, or that refuses the request before the
    // controller ever sees the unknown level.
    completeBasicProfile($this->token);

    $this->withToken($this->token)
        ->postJson('/api/v1/account/verifications/not_a_level/submit')
        ->assertStatus(404);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/account/verifications')->assertStatus(401);
});

it('keeps the phone level and phone_verified_at in step across sign-ins', function () {
    fakeOtpSender();
    signIn(devicePublicId: 'second-device');

    $phone = UserVerification::where('type', VerificationType::Phone->value)->sole();

    // One row, not one per sign-in.
    expect($phone->status)->toBe(VerificationStatus::Approved)
        ->and(UserVerification::where('type', VerificationType::Phone->value)->count())->toBe(1)
        ->and(User::sole()->phone_verified_at)->not->toBeNull();
});
