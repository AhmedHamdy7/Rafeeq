<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
});

function profileStatus(): DriverProfileStatus
{
    return DriverProfile::query()->sole()->status;
}

function uploadRegistration(string $token, ?string $vehicleId = null)
{
    $vehicleId ??= Vehicle::query()->sole()->id;

    return test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$vehicleId}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ])->assertStatus(201);
}

function addCar(string $token): string
{
    return test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Toyota',
        'model' => 'Corolla',
        'year' => 2019,
        'colour' => 'Silver',
        'plateNumber' => 'ABC 1235',
        'seats' => 5,
        'fuelType' => 'petrol',
    ])->assertStatus(201)->json('data.id');
}

function enterLicence(string $token): void
{
    test()->withToken($token)->putJson('/api/v1/driver/application/licence', [
        'nationalId' => '29604120100001',
        'licenceNumber' => 'DL-9931201',
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ])->assertOk();

    uploadVerificationDocument($token, 'driving_licence', 'licence_front')->assertStatus(201);
    uploadVerificationDocument($token, 'driving_licence', 'licence_back')->assertStatus(201);
}

it('sends the application to review when the fifth item is uploaded', function () {
    $token = readyDriverApplicant();

    completeDriverApplication($token);

    expect(profileStatus())->toBe(DriverProfileStatus::PendingReview)
        ->and(UserVerification::query()->where('type', VerificationType::DrivingLicence->value)->sole()->status)
        ->toBe(VerificationStatus::Pending);

    test()->withToken($token)->getJson('/api/v1/driver/verification')
        ->assertOk()
        ->assertJsonPath('data.overall', 'under_review');
});

it('keeps the application a draft while an item is missing', function () {
    $token = readyDriverApplicant();

    enterLicence($token);
    addCar($token);

    expect(profileStatus())->toBe(DriverProfileStatus::Draft);
});

it('sends the ID and selfie to review with the application when they were uploaded but not sent', function () {
    $token = signIn(phone: '01012345678', devicePublicId: 'dev-1')['session']['accessToken'];
    completeBasicProfile($token);
    test()->withToken($token)->postJson('/api/v1/driver/application')->assertStatus(201);

    uploadVerificationDocument($token, 'government_id', 'national_id_front')->assertStatus(201);
    uploadVerificationDocument($token, 'government_id', 'national_id_back')->assertStatus(201);
    uploadVerificationDocument($token, 'selfie', 'selfie')->assertStatus(201);

    expect(profileStatus())->toBe(DriverProfileStatus::Draft);

    enterLicence($token);
    uploadRegistration($token, addCar($token));

    expect(profileStatus())->toBe(DriverProfileStatus::PendingReview)
        ->and(UserVerification::query()->where('type', VerificationType::GovernmentId->value)->sole()->status)
        ->toBe(VerificationStatus::Pending)
        ->and(UserVerification::query()->where('type', VerificationType::Selfie->value)->sole()->status)
        ->toBe(VerificationStatus::Pending);
});

it('sends the application when the ID is the last item to arrive', function () {
    $token = signIn(phone: '01012345678', devicePublicId: 'dev-1')['session']['accessToken'];
    completeBasicProfile($token);
    submitSelfie($token);
    test()->withToken($token)->postJson('/api/v1/driver/application')->assertStatus(201);

    enterLicence($token);
    uploadRegistration($token, addCar($token));

    expect(profileStatus())->toBe(DriverProfileStatus::Draft);

    uploadVerificationDocument($token, 'government_id', 'national_id_front')->assertStatus(201);
    uploadVerificationDocument($token, 'government_id', 'national_id_back')->assertStatus(201);

    expect(profileStatus())->toBe(DriverProfileStatus::PendingReview);
});

it('leaves the application a draft when the licence has expired', function () {
    $token = readyDriverApplicant();

    enterLicence($token);
    $vehicle = addCar($token);
    DriverProfile::query()->sole()->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();

    uploadRegistration($token, $vehicle);

    expect(profileStatus())->toBe(DriverProfileStatus::Draft);
});

it('sends a corrected application back to review when the rejected item is replaced', function () {
    $token = readyDriverApplicant();
    completeDriverApplication($token);

    $profile = DriverProfile::query()->sole();
    app(ReviewDriverApplicationAction::class)->rejectVehicle(
        $profile,
        $profile->vehicles()->sole(),
        AdminUser::factory()->create(),
        VehicleRejectedItem::Registration,
        'The registration photo is too blurry to read.',
    );

    expect(profileStatus())->toBe(DriverProfileStatus::Rejected);

    uploadRegistration($token);

    expect(profileStatus())->toBe(DriverProfileStatus::PendingReview);
});

it('still accepts the submit button as before', function () {
    $token = readyDriverApplicant();
    completeDriverApplication($token);

    test()->withToken($token)->postJson('/api/v1/driver/application/submit')
        ->assertOk()
        ->assertJsonPath('data.application.status', 'PENDING_REVIEW');
});
