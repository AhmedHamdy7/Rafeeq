<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\DocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for private files (ERD §18): `purge_after` enforced, not a promise on paper.
 *
 * 🔴 Both directions matter. A file kept past its date breaks a promise the member was shown; a file
 * destroyed while a reviewer or an investigation still needs it turns a privacy rule into harm.
 */
beforeEach(function () {
    Storage::fake('documents');
});

function onDisk(Model $file): bool
{
    return app(DocumentStorage::class)->disk()->exists($file->file_path);
}

function withBytes(Model $file): Model
{
    app(DocumentStorage::class)->disk()->put($file->file_path, 'bytes');

    return $file;
}

function expiredDocument(UserVerification $verification): IdentityDocument
{
    return withBytes(IdentityDocument::factory()->create([
        'user_verification_id' => $verification->id,
        'purge_after' => now()->subDay()->toDateString(),
    ]));
}

function expiredEvidence(IncidentStatus $status): IncidentEvidence
{
    $incident = Incident::factory()->create();
    $incident->forceFill(['status' => $status->value])->save();

    return withBytes(IncidentEvidence::factory()->create([
        'incident_id' => $incident->id,
        'purge_after' => now()->subDay()->toDateString(),
    ]));
}

it('destroys a decided document past its date and keeps the row with its hash', function () {
    $document = expiredDocument(UserVerification::factory()->approved()->create());
    $hash = $document->file_hash;

    $this->artisan('files:purge-expired')->assertSuccessful();

    $document->refresh();

    expect(onDisk($document))->toBeFalse()
        ->and($document->purged_at)->not->toBeNull()
        ->and($document->file_hash)->toBe($hash);
});

it('leaves a file whose date has not come', function () {
    $document = withBytes(IdentityDocument::factory()->create([
        'user_verification_id' => UserVerification::factory()->approved()->create()->id,
        'purge_after' => now()->addDay()->toDateString(),
    ]));

    $this->artisan('files:purge-expired')->assertSuccessful();

    expect(onDisk($document))->toBeTrue()
        ->and($document->refresh()->purged_at)->toBeNull();
});

/**
 * 🔒 Deleting the licence a reviewer is about to open would turn a privacy rule into a rejection.
 */
it('holds a document that is still under review', function () {
    $document = expiredDocument(UserVerification::factory()->create());

    $this->artisan('files:purge-expired')->assertSuccessful();

    expect(onDisk($document))->toBeTrue()
        ->and($document->refresh()->purged_at)->toBeNull();
});

it('holds a vehicle document under review and destroys a decided one', function () {
    $pending = withBytes(VehicleDocument::factory()->create(['purge_after' => now()->subDay()->toDateString()]));
    $pending->forceFill(['verification_status' => VehicleVerificationStatus::Pending->value])->save();
    $decided = withBytes(VehicleDocument::factory()->create(['purge_after' => now()->subDay()->toDateString()]));

    $this->artisan('files:purge-expired')->assertSuccessful();

    expect(onDisk($pending))->toBeTrue()
        ->and(onDisk($decided))->toBeFalse();
});

/**
 * 🔴 A report escalated to the police when its retention date passes is the report whose
 * photographs must still exist.
 */
it('holds evidence on a case that is still open, whatever its date', function (IncidentStatus $status) {
    $evidence = expiredEvidence($status);

    $this->artisan('files:purge-expired')->assertSuccessful();

    expect(onDisk($evidence))->toBeTrue()
        ->and($evidence->refresh()->purged_at)->toBeNull();
})->with([IncidentStatus::Open, IncidentStatus::UnderReview, IncidentStatus::Escalated]);

it('destroys evidence once its case is closed and its date has passed', function (IncidentStatus $status) {
    $evidence = expiredEvidence($status);

    $this->artisan('files:purge-expired')->assertSuccessful();

    expect(onDisk($evidence))->toBeFalse()
        ->and(IncidentEvidence::query()->whereKey($evidence->id)->exists())->toBeTrue();
})->with([IncidentStatus::Resolved, IncidentStatus::Closed]);

it('purges once — a second run touches nothing', function () {
    $document = expiredDocument(UserVerification::factory()->approved()->create());

    $this->artisan('files:purge-expired')->expectsOutputToContain('Purged 1 identity documents')->assertSuccessful();
    $first = $document->refresh()->purged_at;

    $this->travel(1)->minutes();
    $this->artisan('files:purge-expired')->expectsOutputToContain('Purged 0 identity documents')->assertSuccessful();

    expect($document->refresh()->purged_at->equalTo($first))->toBeTrue();
});

it('tells staff a destroyed file is gone rather than missing', function () {
    $evidence = expiredEvidence(IncidentStatus::Closed);
    $this->artisan('files:purge-expired')->assertSuccessful();

    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $this->get(route('admin.files.evidence', $evidence->id))->assertStatus(410);
});

/**
 * Decided 2026-10-06: 90 days from upload, editable from Settings — and stamped on the row when the
 * file arrives, so a later change never extends the life of a file sent under the old policy.
 */
it('dates a new document from the retention setting in force when it is uploaded', function () {
    PlatformSetting::updateOrCreate(
        ['setting_key' => 'verification.document_retention_days'],
        ['setting_value' => 30, 'value_type' => 'integer'],
    );

    fakeOtpSender();
    submitGovernmentId(signIn('01112223344')['session']['accessToken']);

    expect(IdentityDocument::query()->first()->purge_after->toDateString())->toBe(now()->addDays(30)->toDateString());
});
