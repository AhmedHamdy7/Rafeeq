<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Enums\VirusScanStatus;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Support\DocumentStorage;
use App\Livewire\Admin\VerificationQueue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Staff opening identity documents and report evidence (Phase 13).
 *
 * 🔴 These files are the most sensitive bytes the platform holds. What is tested is everything
 * that keeps them that way: a permission per kind of file, an audit row per opening, nothing the
 * virus scan did not pass, nothing whose bytes no longer match the hash taken when it arrived, and
 * nothing a browser or proxy may keep.
 */
beforeEach(function () {
    Storage::fake('documents');
});

/**
 * Puts real bytes behind a row, with the hash the row says they should have.
 */
function storedFile(string $path, string $contents = 'jpeg-bytes'): string
{
    app(DocumentStorage::class)->disk()->put($path, $contents);

    return hash('sha256', $contents);
}

function storedDocument(array $attributes = []): IdentityDocument
{
    $document = IdentityDocument::factory()->create($attributes);
    $document->forceFill(['file_hash' => storedFile($document->file_path)])->save();

    return $document;
}

function storedEvidence(): IncidentEvidence
{
    $evidence = IncidentEvidence::factory()->create();
    $evidence->forceFill(['file_hash' => storedFile($evidence->file_path)])->save();

    return $evidence;
}

it('lets a verification reviewer open an identity document, and records that they did', function () {
    $document = storedDocument();
    $reviewer = actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->get(route('admin.files.document', $document->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('jpeg-bytes', escape: false);

    $audit = AdminAction::query()->where('action', 'verification.view_document')->sole();

    expect($audit->admin_id)->toBe($reviewer->id)
        ->and($audit->entity_id)->toBe($document->id);
});

/**
 * 🔒 Nothing that would let a copy outlive the screen it was opened on.
 */
it('tells every cache not to keep the file', function () {
    $document = storedDocument();
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $cache = $this->get(route('admin.files.document', $document->id))->headers->get('Cache-Control');

    expect($cache)->toContain('no-store')->toContain('private');
});

/**
 * 🔴 Seeing the queue is not opening what is in it — support and operations can do the first.
 */
it('refuses staff who may see the queue but not the documents', function (AdminRole $role) {
    $document = storedDocument();
    actingAsAdmin(adminWithRole($role));

    $this->get(route('admin.files.document', $document->id))->assertForbidden();

    expect(AdminAction::query()->count())->toBe(0);
})->with([AdminRole::Support, AdminRole::Operations, AdminRole::SafetyLead]);

it('never serves a document the virus scan did not pass, whoever asks', function (VirusScanStatus $status) {
    $document = storedDocument(['virus_scan_status' => $status]);
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.files.document', $document->id))->assertNotFound();
})->with([VirusScanStatus::Infected, VirusScanStatus::Pending]);

/**
 * 🔴 Chain of custody: bytes that no longer match what arrived are refused, not shown as if they
 * were the original — and the refusal is not recorded as somebody having seen the file.
 */
it('refuses a file whose bytes no longer match the hash taken when it arrived', function () {
    $evidence = storedEvidence();
    app(DocumentStorage::class)->disk()->put($evidence->file_path, 'altered');

    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $this->get(route('admin.files.evidence', $evidence->id))->assertStatus(409);

    expect(AdminAction::query()->where('action', 'evidence.view')->exists())->toBeFalse();
});

it('lets the safety lead open report evidence, and records that they did', function () {
    $evidence = storedEvidence();
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    $this->get(route('admin.files.evidence', $evidence->id))->assertOk();

    expect(AdminAction::query()->where('action', 'evidence.view')->where('entity_id', $evidence->id)->exists())->toBeTrue();
});

it('refuses report evidence to staff without the evidence permission', function (AdminRole $role) {
    $evidence = storedEvidence();
    actingAsAdmin(adminWithRole($role));

    $this->get(route('admin.files.evidence', $evidence->id))->assertForbidden();
})->with([AdminRole::Support, AdminRole::Operations, AdminRole::Verification]);

it('sends somebody who is not signed in to the login page', function () {
    $document = storedDocument();

    $this->get(route('admin.files.document', $document->id))->assertRedirect(route('admin.login'));
});

it('answers 404 for a file that is listed but missing from storage', function () {
    $document = IdentityDocument::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->get(route('admin.files.document', $document->id))->assertNotFound();
});

it('offers the reviewer a link to each clean document, and offers support none', function () {
    fakeOtpSender();
    submitGovernmentId(signIn('01112223344')['session']['accessToken']);

    $verification = pendingVerification(VerificationType::GovernmentId);
    $clean = $verification->documents()->first();

    actingAsAdmin(adminWithRole(AdminRole::Verification));
    Livewire::test(VerificationQueue::class)
        ->assertSee(route('admin.files.document', $clean->id), escape: false);

    actingAsAdmin(adminWithRole(AdminRole::Support));
    Livewire::test(VerificationQueue::class)
        ->assertDontSee(route('admin.files.document', $clean->id), escape: false);
});
