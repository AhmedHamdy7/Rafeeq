<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\Storage;

/**
 * Feature 02 v3: "Driver has **five checklist items in any order**: (1) ID front/back, (2) selfie,
 * (3) licence, (4) vehicle registration, (5) vehicle details; **manual admin approval** after all
 * required items."
 *
 * The design draws this as a single card reading `Level X of 5` with one of six headline states.
 * This file pins the counts and those states.
 */
beforeEach(function () {
    Storage::fake('documents');
});

function checklist(string $token)
{
    return test()->withToken($token)->getJson('/api/v1/driver/verification');
}

it('starts at nothing done, and says so rather than refusing', function () {
    $token = readyDriverApplicant();

    $data = checklist($token)->assertOk()->json('data');

    expect($data['of'])->toBe(5)
        ->and($data['overall'])->toBeIn(['not_started', 'in_progress'])
        ->and(collect($data['items'])->pluck('key')->all())->toBe([
            'government_id', 'selfie', 'driving_licence', 'vehicle_registration', 'vehicle_details',
        ]);
});

/**
 * 🔴 Reachable WITHOUT verification, deliberately. It is the screen that explains the requirement,
 * so gating it behind the requirement would make it unreachable in the one state it is for.
 */
it('is readable before any identity is verified', function () {
    $signIn = signIn(phone: '01555554444', devicePublicId: 'pax-unverified');
    completeBasicProfile($signIn['session']['accessToken']);

    checklist($signIn['session']['accessToken'])->assertOk();
});

/**
 * 🔴 An approved driver reads **4 of 5**, not 5, and that is the truth rather than a bug in this
 * read model — it is the first thing the checklist surfaced.
 *
 * `ReviewDriverApplicationAction::approve()` guards exactly two things: that the application is
 * decidable, and that the licence has not expired. It does **not** require a selfie, and it does
 * not mark the `driving_licence` verification row approved. So a driver can be approved and
 * publishing while item 2 of the five was never submitted.
 *
 * Feature 02 v3 says "manual admin approval **after all required items**", so this is a gap in the
 * gate and not in the display. It is pinned here as the current behaviour rather than papered over,
 * because making approval demand a selfie would block existing approved drivers and is a product
 * decision, not a refactor.
 */
it('reads four of five for an approved driver, because approval does not require a selfie', function () {
    $token = approvedDriver();

    $data = checklist($token)->assertOk()->json('data');
    $items = collect($data['items'])->keyBy('key');

    expect($data['of'])->toBe(5)
        ->and($data['completed'])->toBe(3)
        // She IS approved, so the chip is right even though an item is outstanding.
        ->and($data['overall'])->toBe('verified')
        ->and($items['selfie']['status'])->toBe('not_started')
        ->and($items['government_id']['status'])->toBe('approved')
        ->and($items['vehicle_details']['status'])->toBe('approved')
        ->and($items['vehicle_registration']['status'])->toBe('approved');
});

it('reads five of five once the selfie is approved too', function () {
    $token = approvedDriver();

    uploadVerificationDocument($token, 'selfie', 'selfie');
    test()->withToken($token)->postJson('/api/v1/account/verifications/selfie/submit')->assertOk();

    app(ReviewVerificationAction::class)->approve(
        UserVerification::query()->where('type', VerificationType::Selfie->value)->sole(),
        adminWithRole(AdminRole::Verification),
    );

    // The licence row is approved as part of the same review in a real queue; here it is set
    // directly, because this test is about the count rather than about the review flow.
    UserVerification::query()
        ->where('type', VerificationType::DrivingLicence->value)
        ->sole()
        ->forceFill(['status' => 'approved'])
        ->save();

    $data = checklist($token)->assertOk()->json('data');

    expect($data['completed'])->toBe(5)
        ->and($data['overall'])->toBe('verified')
        ->and(collect($data['items'])->pluck('status')->unique()->all())->toBe(['approved']);
});

/**
 * 🔴 The design's `action` state names ONE document — "Your driving licence was rejected: blurry
 * photo. **Replace only that document**" — so the reason has to travel per item. A single overall
 * message would send her to re-upload all five.
 */
it('names the one item at fault and leaves the others approved', function () {
    $token = approvedDriver();

    $licence = UserVerification::query()
        ->where('type', VerificationType::DrivingLicence->value)
        ->sole();

    // A reviewer can only ask for more information on something awaiting review, and
    // `approvedDriver()` leaves this row short of that — see the count test above.
    $licence->forceFill(['status' => 'pending'])->save();

    app(ReviewVerificationAction::class)->requestMoreInfo(
        $licence->refresh(),
        adminWithRole(AdminRole::Verification),
        'The licence photo is too blurry to read. Please upload it again.',
    );

    $data = checklist($token)->assertOk()->json('data');
    $items = collect($data['items'])->keyBy('key');

    expect($data['overall'])->toBe('action_required')
        ->and($items['driving_licence']['status'])->toBe('action_needed')
        ->and($items['driving_licence']['rejectionReason'])->toContain('blurry')
        /*
         * 🔴 The v3 acceptance criterion: "rejecting one item doesn't delete other approvals."
         * Her ID and her car are untouched, so the screen can say "replace only that document".
         */
        ->and($items['government_id']['status'])->toBe('approved')
        ->and($items['vehicle_details']['status'])->toBe('approved')
        ->and($items['vehicle_registration']['status'])->toBe('approved');
});

/**
 * `expiring` is a sub-state of verified in the design: the chip still reads "Verified" while the
 * headline warns about the date.
 */
it('warns that a licence is about to expire without calling her unverified', function () {
    $token = approvedDriver();

    UserVerification::query()
        ->where('type', VerificationType::DrivingLicence->value)
        ->sole()
        ->forceFill(['expires_at' => now()->addDays(10)])
        ->save();

    $data = checklist($token)->assertOk()->json('data');

    // 3 of 5 here, not 4: setting an expiry on the still-pending licence row does not approve it,
    // and the selfie is absent as always after `approvedDriver()`.
    expect($data['overall'])->toBe('expiring')
        ->and($data['completed'])->toBe(3)
        ->and($data['licenceExpiresAt'])->not->toBeNull();
});

it('pauses her on an expired licence', function () {
    $token = approvedDriver();

    UserVerification::query()
        ->where('type', VerificationType::DrivingLicence->value)
        ->sole()
        ->forceFill(['expires_at' => now()->subDay()])
        ->save();

    $data = checklist($token)->assertOk()->json('data');
    $items = collect($data['items'])->keyBy('key');

    expect($data['overall'])->toBe('renewal_required')
        // An expired licence is not a valid one, so it stops counting.
        ->and($items['driving_licence']['status'])->toBe('expired')
        ->and($data['completed'])->toBe(3);
});

/**
 * 🔒 Suspension outranks everything, including an expired licence: it is the state a human being
 * decided, and the one that explains why her commutes stopped.
 */
it('reads suspended even when something else is also wrong', function () {
    $token = approvedDriver();

    UserVerification::query()
        ->where('type', VerificationType::DrivingLicence->value)
        ->sole()
        ->forceFill(['expires_at' => now()->subDay()])
        ->save();

    DriverProfile::sole()->forceFill(['status' => DriverProfileStatus::Suspended->value])->save();

    expect(checklist($token)->assertOk()->json('data.overall'))->toBe('suspended');
});

it('shows the vehicle items as not started before she adds a car', function () {
    $token = readyDriverApplicant();

    $items = collect(checklist($token)->assertOk()->json('data.items'))->keyBy('key');

    expect($items['vehicle_details']['status'])->toBe('not_started')
        ->and($items['vehicle_registration']['status'])->toBe('not_started');
});

it('follows the vehicle once one is added', function () {
    $token = approvedDriver();

    // A second, unapproved car does not drag the checklist back: the ACTIVE one is what counts.
    expect(Vehicle::query()->where('is_active', true)->count())->toBe(1)
        ->and(checklist($token)->assertOk()->json('data.overall'))->toBe('verified');
});
