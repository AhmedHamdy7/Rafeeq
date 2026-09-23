<?php

use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Verification\Support\DocumentStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 3 §7, §8 and §16 story 5.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = readyDriverApplicant();
});

function addVehicle(string $token, array $overrides = [])
{
    return test()->withToken($token)->postJson('/api/v1/driver/vehicles', array_merge([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'year' => 2019,
        'colour' => 'Silver',
        'plateNumber' => 'ABC 1234',
        'seats' => 5,
        'fuelType' => 'petrol',
    ], $overrides));
}

it('adds a vehicle and makes the first one active', function () {
    $vehicle = addVehicle($this->token)->assertStatus(201)->json('data');

    expect($vehicle['make'])->toBe('Toyota')
        ->and($vehicle['seats'])->toBe(5)
        ->and($vehicle['status'])->toBe('PENDING')
        // A driver with one car and no active vehicle could publish nothing
        // and would have no idea why.
        ->and($vehicle['isActive'])->toBeTrue();
});

it('never exposes the internal uniqueness key', function () {
    $response = addVehicle($this->token)->assertStatus(201);

    expect($response->getContent())->not->toContain('plate_normalized')
        ->and($response->getContent())->not->toContain('plateNormalized');
});

it('rejects a plate already registered on the platform', function () {
    addVehicle($this->token)->assertStatus(201);

    fakeOtpSender();
    $other = readyDriverApplicant(phone: '01112223344', devicePublicId: 'dev-2');

    addVehicle($other)->assertStatus(409)
        ->assertJsonPath('error.code', 'DRIVER_DUPLICATE_DETECTED');
});

it('treats a plate as the same whatever the spacing or case', function () {
    addVehicle($this->token, ['plateNumber' => 'ABC 1234'])->assertStatus(201);

    fakeOtpSender();
    $other = readyDriverApplicant(phone: '01112223344', devicePublicId: 'dev-2');

    addVehicle($other, ['plateNumber' => 'abc1234'])->assertStatus(409);
});

it('refuses a car older than the platform allows', function () {
    addVehicle($this->token, ['year' => (int) config('rafeeq.driver.vehicle_minimum_year') - 1])
        ->assertStatus(422);
});

/**
 * The values are literals because a dataset is built while Pest collects tests,
 * before the application boots — `config()` is unavailable there even inside a
 * closure. The test asserts they really are outside the configured range, so a
 * change to the setting fails here rather than silently making the case vacuous.
 */
it('refuses a seat count outside the allowed range', function (int $seats) {
    $min = (int) config('rafeeq.driver.vehicle_minimum_seats');
    $max = (int) config('rafeeq.driver.vehicle_maximum_seats');

    // Written as one boolean rather than two chained expectations: Pest's `or`
    // is not a logical OR between assertions — both sides still run.
    expect($seats < $min || $seats > $max)
        ->toBeTrue("{$seats} is inside the configured range {$min}-{$max}, so this case proves nothing");

    addVehicle($this->token, ['seats' => $seats])->assertStatus(422);
})->with([1, 9]);

it('caps how many vehicles one driver can register', function () {
    $limit = (int) config('rafeeq.driver.maximum_vehicles_per_driver');

    foreach (range(1, $limit) as $index) {
        addVehicle($this->token, ['plateNumber' => "PLT {$index}00"])->assertStatus(201);
    }

    addVehicle($this->token, ['plateNumber' => 'PLT 999'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VEHICLE_LIMIT_REACHED');
});

/**
 * §16 story 5: several vehicles, only one active, and commutes follow whichever
 * is active.
 */
it('moves the active flag rather than ever having two', function () {
    $first = addVehicle($this->token)->assertStatus(201)->json('data.id');
    $second = addVehicle($this->token, ['plateNumber' => 'XYZ 9876'])->assertStatus(201)->json('data.id');

    expect(Vehicle::find($first)->is_active)->toBeTrue()
        ->and(Vehicle::find($second)->is_active)->toBeFalse();

    $this->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$second}/activate")->assertOk();

    expect(Vehicle::find($first)->fresh()->is_active)->toBeFalse()
        ->and(Vehicle::find($second)->fresh()->is_active)->toBeTrue()
        ->and(Vehicle::where('is_active', true)->count())->toBe(1);
});

/**
 * The generated column plus unique index is what makes "one active vehicle"
 * true under a race — not the Action, which only keeps it tidy.
 */
it('is the database that forbids two active vehicles, not just the code', function () {
    $first = addVehicle($this->token)->assertStatus(201)->json('data.id');
    $second = addVehicle($this->token, ['plateNumber' => 'XYZ 9876'])->assertStatus(201)->json('data.id');

    expect(fn () => Vehicle::find($second)->forceFill(['is_active' => true])->save())
        ->toThrow(QueryException::class);

    expect(Vehicle::find($first)->fresh()->is_active)->toBeTrue();
});

it('lists vehicles with the active one first', function () {
    addVehicle($this->token)->assertStatus(201);
    $second = addVehicle($this->token, ['plateNumber' => 'XYZ 9876'])->assertStatus(201)->json('data.id');

    $this->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$second}/activate")->assertOk();

    $listed = $this->withToken($this->token)->getJson('/api/v1/driver/vehicles')->assertOk()->json('data');

    expect($listed[0]['id'])->toBe($second)
        ->and($listed[0]['isActive'])->toBeTrue();
});

it('edits a vehicle without wiping the fields it left out', function () {
    $id = addVehicle($this->token)->assertStatus(201)->json('data.id');

    $updated = $this->withToken($this->token)
        ->patchJson("/api/v1/driver/vehicles/{$id}", ['colour' => 'Black'])
        ->assertOk()->json('data');

    expect($updated['colour'])->toBe('Black')
        ->and($updated['make'])->toBe('Toyota')
        ->and($updated['seats'])->toBe(5);
});

/**
 * IDOR (Bible §6): someone else's vehicle must be indistinguishable from one
 * that does not exist.
 */
it('answers 404 for a vehicle belonging to another driver', function () {
    $mine = addVehicle($this->token)->assertStatus(201)->json('data.id');

    fakeOtpSender();
    $other = readyDriverApplicant(phone: '01112223344', devicePublicId: 'dev-2');
    $theirs = addVehicle($other, ['plateNumber' => 'XYZ 9876'])->assertStatus(201)->json('data.id');

    $this->withToken($this->token)->patchJson("/api/v1/driver/vehicles/{$theirs}", ['colour' => 'Red'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$theirs}/activate")
        ->assertStatus(404);

    expect(Vehicle::find($theirs)->colour)->not->toBe('Red')
        ->and(Vehicle::find($mine)->is_active)->toBeTrue();
});

it('cannot be told it is approved by the request that creates it', function () {
    // Even if a client sends them, these must not stick: a vehicle declaring
    // itself approved would bypass the whole review.
    addVehicle($this->token, ['verification_status' => 'approved', 'is_active' => true])
        ->assertStatus(201);

    expect(Vehicle::sole()->verification_status->value)->toBe('pending');
});

it('stores a vehicle document through the same secure intake as an ID', function () {
    $id = addVehicle($this->token)->assertStatus(201)->json('data.id');

    $this->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$id}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ])->assertStatus(201);

    $document = VehicleDocument::sole();

    expect(app(DocumentStorage::class)->disk()->exists($document->file_path))->toBeTrue()
        // A registration carries the owner's name and address, so it is no less
        // sensitive than an ID — and the path reveals nothing.
        ->and($document->file_path)->not->toContain('registration')
        ->and($document->toArray())->not->toHaveKey('file_path')
        ->and($document->purge_after)->not->toBeNull();
});

it('refuses a vehicle document that is not an image', function () {
    $id = addVehicle($this->token)->assertStatus(201)->json('data.id');

    // Counted, not assumed empty: the identity documents from the application
    // setup are already on this disk.
    $before = count(app(DocumentStorage::class)->disk()->allFiles());

    $this->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$id}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->createWithContent('reg.jpg', "MZ\x90\x00"),
    ])->assertStatus(422)->assertJsonPath('error.code', 'DOCUMENT_REJECTED_BY_SCANNER');

    expect(VehicleDocument::count())->toBe(0)
        // Not "deleted afterwards" — never written. The scan runs before the
        // store, so unscanned bytes never reach the disk at all.
        ->and(app(DocumentStorage::class)->disk()->allFiles())->toHaveCount($before);
});

it('replaces a re-uploaded document instead of leaving a reviewer two', function () {
    $id = addVehicle($this->token)->assertStatus(201)->json('data.id');

    $before = count(app(DocumentStorage::class)->disk()->allFiles());

    foreach (range(1, 2) as $ignored) {
        $this->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$id}/documents", [
            'type' => 'registration',
            'file' => UploadedFile::fake()->image('registration.jpg', 900, 600),
        ])->assertStatus(201);
    }

    expect(VehicleDocument::count())->toBe(1)
        // One net new file after two uploads: the superseded one is gone from
        // the disk as well as the table. An orphan is a document no purge job
        // will ever find.
        ->and(app(DocumentStorage::class)->disk()->allFiles())->toHaveCount($before + 1);
});
