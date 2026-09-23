<?php

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Verification\Enums\VerificationType;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 3 §6, §9, §14.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = readyDriverApplicant();
});

function licenceDetails(string $token, array $overrides = [])
{
    return test()->withToken($token)->putJson('/api/v1/driver/application/licence', array_merge([
        'nationalId' => '29604120101234',
        'licenceNumber' => 'DL-9931204',
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ], $overrides));
}

it('opens an application in draft and reports what is missing', function () {
    $application = $this->withToken($this->token)
        ->getJson('/api/v1/driver/application')->assertOk()->json('data');

    expect($application['status'])->toBe('DRAFT')
        ->and($application['missing'])->toContain('national_id', 'licence_number', 'vehicle')
        ->and($application['hasNationalId'])->toBeFalse();
});

/**
 * Both numbers are stored encrypted with a separate hash for duplicate
 * detection. Echoing one back — even to the person who typed it — would undo
 * that for a field they already know.
 */
it('stores the licence numbers encrypted and never renders them back', function () {
    $response = licenceDetails($this->token)->assertOk();

    expect($response->json('data.hasNationalId'))->toBeTrue()
        ->and($response->json('data.hasLicenceNumber'))->toBeTrue()
        ->and($response->getContent())->not->toContain('29604120101234')
        ->and($response->getContent())->not->toContain('DL-9931204');

    $profile = DriverProfile::sole();

    // Readable through the model, unreadable in the column.
    expect($profile->national_id)->toBe('29604120101234')
        ->and($profile->getRawOriginal('national_id_encrypted'))->not->toContain('29604120101234')
        ->and($profile->national_id_hash)->toBe(hash('sha256', '29604120101234'))
        // Nothing sensitive in a default serialization either.
        ->and($profile->toArray())->not->toHaveKey('national_id_encrypted')
        ->and($profile->toArray())->not->toHaveKey('national_id_hash');
});

it('refuses a licence that expires too soon to survive the review', function () {
    $minimum = (int) config('rafeeq.driver.licence_minimum_validity_days');

    licenceDetails($this->token, ['licenceExpiry' => now()->addDays($minimum - 5)->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DRIVER_LICENCE_EXPIRED');

    expect(DriverProfile::sole()->licence_number_hash)->toBeNull();
});

it('refuses a licence that has already expired', function () {
    licenceDetails($this->token, ['licenceExpiry' => now()->subDay()->toDateString()])
        ->assertStatus(422);
});

/**
 * §14: detect duplicate national IDs and licence numbers. §16 story 4: a
 * duplicate goes to fraud review and is never approved automatically.
 */
it('refuses a national id already registered to someone else', function () {
    licenceDetails($this->token)->assertOk();

    fakeOtpSender();
    $other = readyDriverApplicant(phone: '01112223344', devicePublicId: 'dev-2');

    licenceDetails($other, ['licenceNumber' => 'DL-0000001'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRIVER_DUPLICATE_DETECTED');
});

it('refuses a licence number already registered to someone else', function () {
    licenceDetails($this->token)->assertOk();

    fakeOtpSender();
    $other = readyDriverApplicant(phone: '01112223344', devicePublicId: 'dev-2');

    licenceDetails($other, ['nationalId' => '29604120109999'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRIVER_DUPLICATE_DETECTED');
});

/**
 * The refusal must not say WHICH detail matched: an honest applicant learns
 * nothing from it, while someone probing would learn whether a specific person
 * is on the platform.
 */
it('never reveals which detail was the duplicate', function () {
    licenceDetails($this->token)->assertOk();

    fakeOtpSender();
    $other = readyDriverApplicant(phone: '01112223344', devicePublicId: 'dev-2');

    $response = licenceDetails($other, ['licenceNumber' => 'DL-0000001']);

    expect($response->getContent())->not->toContain('national_id')
        ->and($response->getContent())->not->toContain('licence_number');

    // But a fraud reviewer can see it in the audit trail.
    $event = SecurityEvent::where('event_type', SecurityEventType::DriverDuplicateDetected->value)->sole();

    expect($event->metadata['national_id_match'])->toBeTrue()
        ->and($event->risk_level->value)->toBe('high');
});

it('lets someone resubmit their own unchanged details', function () {
    licenceDetails($this->token)->assertOk();

    // Not a duplicate of anyone — it is the same person's own record.
    licenceDetails($this->token)->assertOk();
});

it('refuses to submit while anything is still missing', function () {
    licenceDetails($this->token)->assertOk();

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DRIVER_APPLICATION_INCOMPLETE')
        ->assertJsonPath('error.fields.missing', ['licence_front', 'licence_back', 'vehicle']);
});

it('queues a complete application for review', function () {
    completeDriverApplication($this->token);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/application/submit')->assertOk();

    expect($response->json('data.application.status'))->toBe('PENDING_REVIEW')
        ->and($response->json('data.application.missing'))->toBe([])
        // The 24–48 hour expectation the chapter sets, said out loud.
        ->and($response->json('data.message'))->not->toBeEmpty()
        ->and(SecurityEvent::where('event_type', SecurityEventType::DriverApplicationSubmitted->value)->count())->toBe(1);
});

it('is idempotent when submit is tapped twice', function () {
    completeDriverApplication($this->token);

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();
    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    expect(SecurityEvent::where('event_type', SecurityEventType::DriverApplicationSubmitted->value)->count())->toBe(1);
});

/**
 * §9: editing is disabled once an application is with a reviewer. Otherwise a
 * reviewer could approve details that changed while they were reading.
 */
it('locks every edit while the application is under review', function () {
    completeDriverApplication($this->token);
    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    licenceDetails($this->token)->assertStatus(409)
        ->assertJsonPath('error.code', 'DRIVER_APPLICATION_LOCKED');

    $this->withToken($this->token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2020,
        'colour' => 'White', 'plateNumber' => 'XYZ 9876', 'seats' => 5,
    ])->assertStatus(409);
});

/**
 * §9 offers withdrawal instead of editing under review.
 */
it('reopens editing when the application is withdrawn, without losing anything', function () {
    completeDriverApplication($this->token);
    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $withdrawn = $this->withToken($this->token)
        ->deleteJson('/api/v1/driver/application')->assertOk()->json('data');

    expect($withdrawn['status'])->toBe('DRAFT')
        // Withdrawing reopens it; it does not throw the work away.
        ->and($withdrawn['hasLicenceNumber'])->toBeTrue()
        ->and($withdrawn['vehicles'])->toHaveCount(1)
        ->and($withdrawn['missing'])->toBe([]);

    licenceDetails($this->token)->assertOk();
});

it('refuses to withdraw an application that is not under review', function () {
    $this->withToken($this->token)->deleteJson('/api/v1/driver/application')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRIVER_APPLICATION_LOCKED');
});

it('re-checks the licence at submission, not only when it was typed', function () {
    completeDriverApplication($this->token);

    // The application sat in draft long enough for the licence to lapse.
    DriverProfile::sole()->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DRIVER_LICENCE_EXPIRED');

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Draft);
});

it('answers 404 when there is no application yet', function () {
    // A different phone: the phone IS the identity, so reusing the default one
    // would return the account `beforeEach` already set up.
    fakeOtpSender();
    $token = signIn(phone: '01112223344', devicePublicId: 'fresh')['session']['accessToken'];
    completeBasicProfile($token);
    submitGovernmentId($token);
    approveVerification(VerificationType::GovernmentId);

    $this->withToken($token)->getJson('/api/v1/driver/application')->assertStatus(404);
});
