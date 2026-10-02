<?php

use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Verification\Support\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence on a report (Chapter 10, screen 32's "add evidence").
 *
 * 🔒 Two properties carry this feature, and both are easy to lose quietly:
 *
 *   1. the stored bytes are NOT the uploaded bytes — they have been scanned and re-encoded, which
 *      is what actually removes the GPS of where the person was standing when they were frightened;
 *   2. no path, URL or hash ever reaches a payload, so there is nothing to forward and nothing to
 *      adjust into a guess at somebody else's file.
 */
beforeEach(function () {
    Storage::fake('documents');

    fakeOtpSender();
    // Deliberately a bare account: filing a report needs nothing but a sign-in, and so does
    // attaching to one.
    $this->token = signIn('01112223344')['session']['accessToken'];

    $this->incidentId = test()->withToken($this->token)->postJson('/api/v1/incidents', [
        'category' => 'harassment',
        'description' => 'الراكب اللي جنبي قال كلام مش مناسب.',
    ])->assertStatus(201)->json('data.id');
});

/**
 * A real image, because the intake decodes the actual bytes — a placeholder string is refused, as
 * it should be.
 */
function evidenceFile(string $name = 'scene.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 1600, 1200);
}

function attachEvidence(string $token, string $incidentId, array $payload = [])
{
    return test()->withToken($token)->postJson("/api/v1/incidents/{$incidentId}/evidence", array_merge([
        'kind' => 'photo',
        'file' => evidenceFile(),
    ], $payload));
}

/*
|--------------------------------------------------------------------------
| Attaching
|--------------------------------------------------------------------------
*/

it('attaches a photograph to the reporter own report', function () {
    $evidence = attachEvidence($this->token, $this->incidentId)->assertStatus(201)->json('data');

    expect($evidence['id'])->not->toBeEmpty()
        ->and($evidence['kind'])->toBe('photo')
        // Shown because somebody who sends a photograph of a bad moment is owed the answer to
        // "how long do you keep this", and a date on the screen beats a policy page.
        ->and($evidence['purgeAfter'])->toBe(now()->addDays(365)->toDateString());
});

it('counts the attachments on the report it belongs to', function () {
    attachEvidence($this->token, $this->incidentId)->assertStatus(201);
    attachEvidence($this->token, $this->incidentId)->assertStatus(201);

    expect(test()->withToken($this->token)->getJson("/api/v1/incidents/{$this->incidentId}")
        ->assertOk()->json('data.evidenceCount'))->toBe(2);

    expect(test()->withToken($this->token)->getJson("/api/v1/incidents/{$this->incidentId}/evidence")
        ->assertOk()->json('data'))->toHaveCount(2);
});

/**
 * 🔒 Pitfall #24, and this is its sharpest case in the whole product. A photograph taken at the
 * scene of an incident carries the EXIF GPS of **where the person was standing when they were
 * frightened**. They photographed a car; they did not agree to hand over their own position, and a
 * report travels further than the app.
 */
it('does not store the bytes it was given', function () {
    $file = evidenceFile();

    $uploaded = (string) file_get_contents($file->getRealPath());

    attachEvidence($this->token, $this->incidentId, ['file' => $file])->assertStatus(201);

    $path = IncidentEvidence::sole()->file_path;

    $stored = (string) app(DocumentStorage::class)->disk()->get($path);

    // Re-encoded, not copied. Equality here would mean the metadata survived.
    expect($stored)->not->toBe($uploaded)
        // And the hash on the row is of what was STORED, so the chain of custody describes the file
        // a reviewer will actually open.
        ->and(IncidentEvidence::sole()->file_hash)->toBe(hash('sha256', $stored));
});

it('keeps the file on the private disk and nowhere else', function () {
    attachEvidence($this->token, $this->incidentId)->assertStatus(201);

    $path = IncidentEvidence::sole()->file_path;

    // Partitioned by owner, so "delete everything about me" is one prefix rather than a query, and
    // the name itself means nothing — not the category, not the reporter's name, not the filename
    // they chose.
    expect($path)->toStartWith('users/')
        ->and($path)->not->toContain('scene')
        ->and($path)->not->toContain('harassment')
        ->and(Storage::disk('documents')->exists($path))->toBeTrue();
});

/**
 * 🔒 Pitfall #23. The reporter knows what they sent — they chose it seconds ago — and the reviewer
 * reads it through the dashboard. So there is nothing in any payload to forward, and nothing to
 * adjust into a guess at a neighbour's file.
 */
it('never returns a path, a url or a hash', function () {
    $created = attachEvidence($this->token, $this->incidentId)->assertStatus(201)->json('data');

    $listed = test()->withToken($this->token)->getJson("/api/v1/incidents/{$this->incidentId}/evidence")
        ->assertOk()->json('data.0');

    foreach ([$created, $listed] as $payload) {
        expect($payload)->not->toHaveKey('filePath')
            ->not->toHaveKey('file_path')
            ->not->toHaveKey('url')
            ->not->toHaveKey('fileHash')
            ->not->toHaveKey('hash');
    }

    // Hidden on the model as well, so forgetting it in one resource is not enough to leak it.
    expect(IncidentEvidence::sole()->toArray())->not->toHaveKey('file_path');
});

it('accepts a screenshot of the messages too', function () {
    attachEvidence($this->token, $this->incidentId, [
        'kind' => 'screenshot',
        'file' => UploadedFile::fake()->image('chat.png', 1080, 1920),
    ])->assertStatus(201);
});

/**
 * 🔒 The schema has had room for video and audio since Phase 1, and the intake has no way to strip
 * metadata from either — re-encoding a raster image is what does it, and there is no equivalent.
 * Accepting one would mean either storing it unsanitised or claiming a protection that is not
 * there, so it is refused honestly instead.
 */
it('refuses the kinds it cannot sanitise', function (string $kind) {
    attachEvidence($this->token, $this->incidentId, ['kind' => $kind])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
})->with(['video', 'audio', 'document']);

it('refuses a file that is not an image at all', function () {
    attachEvidence($this->token, $this->incidentId, [
        'file' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
    ])->assertStatus(422);

    expect(IncidentEvidence::count())->toBe(0);
});

/**
 * 🔒 A valid image header says nothing about what follows it, so the declared type is a first
 * filter and the bytes are what decide.
 */
it('refuses an image extension wrapped around something else', function () {
    attachEvidence($this->token, $this->incidentId, [
        'file' => UploadedFile::fake()->createWithContent('scene.jpg', 'not an image at all'),
    ])->assertStatus(422);

    expect(IncidentEvidence::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Decompression bombs
|--------------------------------------------------------------------------
|
| 🔒 Found while running this file against the whole suite, where it took the process down with
| "allowed memory size exhausted" inside the sanitiser.
|
| A compressed image says nothing about what it costs to open. A few hundred kilobytes of JPEG can
| declare 20000 × 15000 pixels, and GD allocates four bytes for each one — 1.2 GB — before anything
| else gets a say. The size limit in the FormRequest does not stop it: the FILE is small, and it is
| the bitmap that is not.
|
| The guard lives in `ImageSanitiser`, so it covers identity documents and vehicle documents too,
| not only this endpoint. The cap is lowered here rather than fabricating a real monster, so the
| test costs nothing to run — which is the same reason the guard reads the header instead of
| decoding.
*/

it('refuses a picture too large to open, before trying to open it', function () {
    config(['rafeeq.verification.max_document_megapixels' => 1]);

    attachEvidence($this->token, $this->incidentId, [
        // 1200 × 900 is 1.08 megapixels: just over the lowered cap.
        'file' => UploadedFile::fake()->image('huge.jpg', 1200, 900),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DOCUMENT_DIMENSIONS_TOO_LARGE')
        // The real count, which is only knowable from the header — so this also proves the check
        // happened without decoding the thing.
        ->assertJsonPath('error.fields.megapixels.0', '1.1')
        ->assertJsonPath('error.fields.maxMegapixels.0', '1');

    expect(IncidentEvidence::count())->toBe(0);
});

it('accepts a picture just inside the cap', function () {
    config(['rafeeq.verification.max_document_megapixels' => 1]);

    // 900 × 900 is 0.81 megapixels. The boundary is a limit, not a suggestion in either direction.
    attachEvidence($this->token, $this->incidentId, [
        'file' => UploadedFile::fake()->image('ok.jpg', 900, 900),
    ])->assertStatus(201);
});

/**
 * 🔒 The same guard, on the endpoint it was already protecting without anybody checking. Identity
 * documents are the higher-value target of the two.
 */
it('guards the identity document path with the same limit', function () {
    config(['rafeeq.verification.max_document_megapixels' => 1]);

    completeBasicProfile($this->token);

    uploadVerificationDocument(
        $this->token,
        'government_id',
        'national_id_front',
        UploadedFile::fake()->image('front.jpg', 1200, 900),
    )
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DOCUMENT_DIMENSIONS_TOO_LARGE');
});

/*
|--------------------------------------------------------------------------
| The bounds
|--------------------------------------------------------------------------
*/

/**
 * 🔒 A cap at all, because `incident_evidence` is never deleted: anything written there is written
 * for the whole retention period, so an unbounded upload path is an unbounded commitment.
 */
it('stops at the cap, and says what the cap is', function () {
    foreach (range(1, 5) as $ignored) {
        attachEvidence($this->token, $this->incidentId)->assertStatus(201);
    }

    attachEvidence($this->token, $this->incidentId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INCIDENT_EVIDENCE_LIMIT_REACHED')
        ->assertJsonPath('error.fields.limit.0', '5');

    expect(IncidentEvidence::count())->toBe(5);
});

/**
 * 🔴 A file landing on a closed case is a file nobody is going to read, and answering "accepted"
 * is worse than refusing: the person believes somebody has it. Refusing sends them to file a new
 * report, which puts a human back in front of it — and the wording says exactly that.
 */
it('refuses to add to a report that has been closed', function (string $status) {
    Incident::query()->whereKey($this->incidentId)->update(['status' => $status]);

    attachEvidence($this->token, $this->incidentId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INCIDENT_CLOSED')
        ->assertJsonPath('error.message', 'This report has been closed, so nothing more can be added to it. Please file a new report.');

    expect(IncidentEvidence::count())->toBe(0);
})->with([IncidentStatus::Resolved->value, IncidentStatus::Closed->value]);

it('still accepts evidence while the case is being looked at', function (string $status) {
    Incident::query()->whereKey($this->incidentId)->update(['status' => $status]);

    attachEvidence($this->token, $this->incidentId)->assertStatus(201);
})->with([
    IncidentStatus::Open->value,
    IncidentStatus::UnderReview->value,
    IncidentStatus::Escalated->value,
]);

/**
 * 🔒 404 for anybody else's report, and that includes the person it is about. Letting the subject
 * attach to it — or even learn that it exists — is how a report becomes a reason for a
 * confrontation.
 */
it('refuses to touch somebody else report', function () {
    fakeOtpSender();
    $stranger = signIn('01223339999', devicePublicId: 'other')['session']['accessToken'];

    attachEvidence($stranger, $this->incidentId)->assertStatus(404);
    test()->withToken($stranger)->getJson("/api/v1/incidents/{$this->incidentId}/evidence")
        ->assertStatus(404);

    expect(IncidentEvidence::count())->toBe(0);
});

it('refuses an unknown report', function () {
    attachEvidence($this->token, str_repeat('0', 26))->assertStatus(404);
});

it('requires a signed-in account', function () {
    test()->withoutToken()
        ->postJson("/api/v1/incidents/{$this->incidentId}/evidence", ['kind' => 'photo'])
        ->assertStatus(401);
});

/**
 * 🔴 Nothing can be un-attached — `incident_evidence` is chain of custody and the model refuses
 * deletion outright. This is a test rather than a comment because a client offering a remove button
 * it cannot honour is worse than one that confirms before uploading.
 */
it('cannot be deleted, by anybody, through anything', function () {
    attachEvidence($this->token, $this->incidentId)->assertStatus(201);

    expect(fn () => IncidentEvidence::sole()->delete())->toThrow(RuntimeException::class);

    // And there is no route that would.
    expect(registeredV1Paths())->not->toContain('/v1/incidents/{incident}/evidence/{evidence}');
});

/**
 * The report itself is already in the record that is never deleted. A second safety event per
 * attached photograph would add volume to the table operators read without adding a fact — the
 * evidence row IS the record that a file arrived, and it is itself undeletable.
 */
it('writes no extra safety event per file', function () {
    $before = SafetyEvent::count();

    attachEvidence($this->token, $this->incidentId)->assertStatus(201);
    attachEvidence($this->token, $this->incidentId)->assertStatus(201);

    expect(SafetyEvent::count())->toBe($before);
});
