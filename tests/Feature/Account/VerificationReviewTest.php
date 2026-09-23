<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\Storage;

/**
 * Submission and review — Chapter 3 §9/§10/§11.
 *
 * The HTTP surface for a reviewer's decision belongs to the admin dashboard
 * (Phase 13); what a decision MEANS belongs here, because three of the four
 * Verification Centre states only exist once something can move between them.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = signIn()['session']['accessToken'];
    completeBasicProfile($this->token);
});

function submitLevel(string $token, string $type = 'government_id')
{
    return test()->withToken($token)->postJson("/api/v1/account/verifications/{$type}/submit");
}

it('refuses to submit a level whose documents are not all in', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    // Only the front is uploaded; the back is still missing.
    submitLevel($this->token)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VERIFICATION_NOT_SUBMITTABLE');

    // Scoped by type: the phone level already exists from signing in.
    expect(UserVerification::where('type', 'government_id')->sole()->status)
        ->toBe(VerificationStatus::NotStarted);
});

it('refuses to submit a level that has no documents at all', function () {
    submitLevel($this->token)->assertStatus(422);
});

it('refuses to submit a level that is proven without a reviewer', function () {
    // Organization is proven by its email domain, so there is nothing for a
    // human to look at and queueing it would be meaningless.
    submitLevel($this->token, 'organization')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VERIFICATION_NOT_SUBMITTABLE');

    // Phone is already approved by the OTP that signed this person in, so it
    // is refused earlier and for a different reason.
    submitLevel($this->token, 'phone')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VERIFICATION_ALREADY_APPROVED');
});

it('queues a complete level for review and spends one attempt', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);
    uploadVerificationDocument($this->token, 'government_id', 'national_id_back')->assertStatus(201);

    submitLevel($this->token)->assertOk();

    $verification = UserVerification::where('type', 'government_id')->sole();

    expect($verification->status)->toBe(VerificationStatus::Pending)
        ->and($verification->attempt_count)->toBe(1)
        ->and(SecurityEvent::where('event_type', SecurityEventType::VerificationSubmitted->value)->count())->toBe(1);
});

/**
 * Scenario J's cousin: a slow connection and a second tap must not queue the
 * same level twice or spend another attempt.
 */
it('is idempotent when submit is tapped twice', function () {
    submitGovernmentId($this->token);

    submitLevel($this->token)->assertOk();

    expect(UserVerification::where('type', 'government_id')->sole()->attempt_count)->toBe(1);
});

it('locks a level after too many trips through review', function () {
    $max = (int) config('rafeeq.verification.max_submission_attempts');

    submitGovernmentId($this->token);

    foreach (range(2, $max) as $attempt) {
        rejectVerification(VerificationType::GovernmentId, "Attempt {$attempt}: still unreadable.");
        submitLevel($this->token)->assertOk();
    }

    rejectVerification(VerificationType::GovernmentId, 'Final rejection.');

    submitLevel($this->token)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'VERIFICATION_ATTEMPTS_EXHAUSTED');
});

it('refuses to reopen an approved level by uploading again', function () {
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VERIFICATION_ALREADY_APPROVED');
});

it('demands an actionable reason for a rejection', function () {
    submitGovernmentId($this->token);

    expect(fn () => app(ReviewVerificationAction::class)->reject(
        UserVerification::where('type', 'government_id')->sole(),
        AdminUser::factory()->create(),
        '   ',
    ))->toThrow(DomainException::class);

    expect(UserVerification::where('type', 'government_id')->sole()->status)
        ->toBe(VerificationStatus::Pending);
});

it('records who decided and when', function () {
    submitGovernmentId($this->token);

    $reviewer = AdminUser::factory()->create();

    app(ReviewVerificationAction::class)->approve(
        UserVerification::where('type', 'government_id')->sole(),
        $reviewer,
    );

    $verification = UserVerification::where('type', 'government_id')->sole();

    expect($verification->reviewed_by)->toBe($reviewer->id)
        ->and($verification->reviewed_at)->not->toBeNull()
        ->and(SecurityEvent::where('event_type', SecurityEventType::VerificationApproved->value)->count())->toBe(1);
});

it('refuses to decide a level that was never submitted', function () {
    // The factory defaults to `pending`, which IS decidable — so this has to
    // set the state it is actually testing.
    $verification = UserVerification::factory()->create([
        'user_id' => User::sole()->id,
        'type' => VerificationType::Selfie->value,
        'status' => VerificationStatus::NotStarted,
    ]);

    // Deciding unsubmitted evidence would approve something nobody claimed
    // was ready.
    expect(fn () => app(ReviewVerificationAction::class)->approve($verification, AdminUser::factory()->create()))
        ->toThrow(DomainException::class);
});

/**
 * After a rejection the person fixes the photo and tries again. The level has
 * to leave "needs action" the moment new evidence arrives, or the Centre keeps
 * showing a reason that no longer applies to what was uploaded.
 */
it('clears the rejection reason when new evidence is uploaded', function () {
    submitGovernmentId($this->token);
    rejectVerification(VerificationType::GovernmentId, 'The back of your ID is cut off.');

    uploadVerificationDocument($this->token, 'government_id', 'national_id_back')->assertStatus(201);

    $verification = UserVerification::where('type', 'government_id')->sole();

    expect($verification->status)->toBe(VerificationStatus::NotStarted)
        ->and($verification->rejection_reason)->toBeNull();
});

it('keeps the trust level in step with what reviewers actually approved', function () {
    expect(User::sole()->trust_level)->toBe(1);

    submitGovernmentId($this->token);
    expect(User::sole()->fresh()->trust_level)->toBe(1);

    approveVerification(VerificationType::GovernmentId);
    expect(User::sole()->fresh()->trust_level)->toBe(2);

    uploadVerificationDocument($this->token, 'selfie', 'selfie')->assertStatus(201);
    submitLevel($this->token, 'selfie')->assertOk();
    approveVerification(VerificationType::Selfie);

    expect(User::sole()->fresh()->trust_level)->toBe(3);
});
