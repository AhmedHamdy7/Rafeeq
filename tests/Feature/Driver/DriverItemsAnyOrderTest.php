<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Actions\ReviewVehicleAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Verification\Enums\VerificationType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Driver verification for the beta (Feature 02):
 *
 *   F2-1  the five items in any order, as drafts, before anything is approved
 *   F2-2  approval needs the ID and the selfie approved
 *   F2-4  the registration as two sides
 *   F2-14 one notice per decision on an item, driver only
 *   F2-15 only the registration is required of the car's papers
 */
beforeEach(function () {
    Storage::fake('documents');
    fakeOtpSender();

    $this->token = signIn(phone: '01012345678', devicePublicId: 'drv')['session']['accessToken'];
    completeBasicProfile($this->token);
});

function startAndFillApplication(string $token, array $registrationSides = ['registration_front', 'registration_back']): string
{
    test()->withToken($token)->postJson('/api/v1/driver/application')->assertStatus(201);

    test()->withToken($token)->putJson('/api/v1/driver/application/licence', [
        'nationalId' => '29604120100001',
        'licenceNumber' => 'DL-9931201',
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ])->assertOk();

    uploadVerificationDocument($token, 'driving_licence', 'licence_front')->assertStatus(201);
    uploadVerificationDocument($token, 'driving_licence', 'licence_back')->assertStatus(201);

    $vehicle = test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2019, 'colour' => 'Silver',
        'plateNumber' => 'ABC 1235', 'seats' => 5, 'fuelType' => 'petrol',
    ])->assertStatus(201)->json('data.id');

    foreach ($registrationSides as $type) {
        test()->withToken($token)->postJson("/api/v1/driver/vehicles/{$vehicle}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image("{$type}.jpg", 1000, 700),
        ])->assertStatus(201);
    }

    return $vehicle;
}

/*
|--------------------------------------------------------------------------
| F2-1 — any order
|--------------------------------------------------------------------------
*/

it('lets a driver start the application, the licence and the car before any ID is approved', function () {
    // Nothing approved, nothing even uploaded for the ID: the old gate answered 403 here.
    startAndFillApplication($this->token);

    $this->withToken($this->token)->getJson('/api/v1/driver/verification')
        ->assertOk()
        ->assertJsonPath('data.items.0.status', 'not_started');
});

it('refuses submit until the ID and the selfie are sent, naming them', function () {
    startAndFillApplication($this->token);

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DRIVER_APPLICATION_INCOMPLETE')
        ->assertJsonPath('error.fields.missing', ['government_id', 'selfie']);

    submitGovernmentId($this->token);
    submitSelfie($this->token);

    // Sent, not approved — that is enough to submit.
    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();
});

/*
|--------------------------------------------------------------------------
| F2-2 — the selfie is required for approval
|--------------------------------------------------------------------------
*/

it('refuses to approve a driver whose selfie is not approved', function () {
    startAndFillApplication($this->token);
    submitGovernmentId($this->token);
    submitSelfie($this->token);
    approveVerification(VerificationType::GovernmentId);

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $profile = DriverProfile::query()->sole();
    $reviewer = AdminUser::factory()->create();
    app(ReviewVehicleAction::class)->execute(Vehicle::sole(), $reviewer, 3, VehicleTier::Standard, true);

    expect(fn () => app(ReviewDriverApplicationAction::class)->approve($profile, $reviewer))
        ->toThrow(fn (DomainException $e) => expect($e->fields['missing'])->toBe(['selfie']));

    approveVerification(VerificationType::Selfie);

    app(ReviewDriverApplicationAction::class)->approve($profile->refresh(), $reviewer);

    expect($profile->refresh()->status)->toBe(DriverProfileStatus::Approved);
});

/*
|--------------------------------------------------------------------------
| F2-4 / F2-15 — the registration, and nothing else
|--------------------------------------------------------------------------
*/

it('names the missing side when only one side of the registration was sent', function () {
    startAndFillApplication($this->token, ['registration_front']);
    submitGovernmentId($this->token);
    submitSelfie($this->token);

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')
        ->assertStatus(422)
        ->assertJsonPath('error.fields.missing', ['registration_back']);

    $this->withToken($this->token)->getJson('/api/v1/driver/verification')
        ->assertJsonPath('data.items.3.status', 'in_progress');
});

it('still accepts the single legacy registration image', function () {
    startAndFillApplication($this->token, ['registration']);
    submitGovernmentId($this->token);
    submitSelfie($this->token);

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();
});

it('never asks for insurance or inspection, from submit through activate', function () {
    approvedDriver(phone: '01098765432', devicePublicId: 'drv-2', seed: 7);

    $driver = User::query()->where('phone_e164', '+201098765432')->sole();
    $vehicle = Vehicle::query()->where('driver_profile_id', $driver->id)->sole();

    expect($vehicle->documents()->pluck('type')->map->value->all())->toBe(['registration']);

    $token = signIn(phone: '01098765432', devicePublicId: 'drv-2')['session']['accessToken'];

    $this->withToken($token)->postJson("/api/v1/driver/vehicles/{$vehicle->id}/activate")->assertOk();
});

/*
|--------------------------------------------------------------------------
| F2-14 — one notice per decision, driver only
|--------------------------------------------------------------------------
*/

it('tells the driver when one of his items is approved or sent back, by item key', function () {
    $this->withToken($this->token)->postJson('/api/v1/driver/application')->assertStatus(201);
    submitGovernmentId($this->token);
    submitSelfie($this->token);

    approveVerification(VerificationType::GovernmentId);
    askForVerificationInfo(VerificationType::Selfie, 'Your face is not visible. Take it again in daylight.');

    $approved = Notification::query()->where('type', 'driver_item_approved')->sole();
    $rejected = Notification::query()->where('type', 'driver_item_rejected')->sole();

    expect($approved->data['itemKey'])->toBe('government_id')
        ->and($rejected->data['itemKey'])->toBe('selfie')
        ->and($rejected->data['rejectionReason'])->toBe('Your face is not visible. Take it again in daylight.');
});

it('tells a passenger nothing about driver verification', function () {
    // Never started a driver application, never added the driver capability.
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    expect(Notification::query()->where('type', 'like', 'driver_%')->count())->toBe(0);
});

it('sends driver_verified once, on the first approval only', function () {
    approvedDriver(phone: '01098765432', devicePublicId: 'drv-2', seed: 7);

    $driver = User::query()->where('phone_e164', '+201098765432')->sole();
    $profile = DriverProfile::query()->where('user_id', $driver->id)->sole();

    expect(Notification::query()->where('user_id', $driver->id)->where('type', 'driver_verified')->count())->toBe(1);

    // Suspended and reinstated: approved again, not newly verified.
    $reviewer = AdminUser::factory()->create();
    app(ReviewDriverApplicationAction::class)->suspend($profile, $reviewer, 'review');
    $profile->refresh()->forceFill(['status' => DriverProfileStatus::PendingReview->value])->save();
    app(ReviewDriverApplicationAction::class)->approve($profile->refresh(), $reviewer);

    expect(Notification::query()->where('user_id', $driver->id)->where('type', 'driver_verified')->count())->toBe(1);
});
