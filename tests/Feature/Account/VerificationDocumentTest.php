<?php

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Verification\Enums\VirusScanStatus;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Support\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 3 §5/§8/§14 and decision D7.
 *
 * The upload path is where the most sensitive data in the product enters the
 * system, so these tests assert the security properties as facts about stored
 * bytes and served responses — not about which methods were called.
 */
beforeEach(function () {
    // A real disk would leave test uploads in the project's storage directory
    // and, worse, let one test see another's files — which is how an assertion
    // about "nothing was written" passes or fails for the wrong reason.
    Storage::fake('documents');

    $this->token = signIn()['session']['accessToken'];
    completeBasicProfile($this->token);
    $this->storage = app(DocumentStorage::class);
});

it('accepts a photo and records it against the level', function () {
    $response = uploadVerificationDocument($this->token, 'government_id', 'national_id_front')
        ->assertStatus(201);

    expect($response->json('data.kind'))->toBe('national_id_front')
        ->and($response->json('data.scanStatus'))->toBe('CLEAN')
        ->and(IdentityDocument::count())->toBe(1);

    $document = IdentityDocument::sole();

    expect($this->storage->disk()->exists($document->file_path))->toBeTrue()
        ->and($document->virus_scan_status)->toBe(VirusScanStatus::Clean)
        // Always re-encoded to one format, whatever arrived.
        ->and($document->mime_type)->toBe('image/jpeg')
        // §18 retention: a purge date is set at upload, never inferred later.
        ->and($document->purge_after)->not->toBeNull();
});

/**
 * The single most important property here. A photo of an ID taken on a phone
 * routinely carries the GPS coordinates of where it was taken — usually the
 * person's home. Storing that next to their national ID would hand an
 * attacker who reached the bucket far more than the document itself, and they
 * never agreed to share it.
 */
it('strips image metadata, including any location, from what it stores', function () {
    // A real JPEG carrying an EXIF block with GPS tags. Built by hand because
    // GD cannot write EXIF, which is the same reason re-encoding removes it.
    $withGps = jpegWithGpsExif();

    expect($withGps)->toContain('Exif')
        ->and($withGps)->toContain('RAFEEQ_SECRET_LOCATION');

    uploadVerificationDocument(
        $this->token, 'government_id', 'national_id_front',
        UploadedFile::fake()->createWithContent('id.jpg', $withGps),
    )->assertStatus(201);

    $stored = $this->storage->disk()->get(IdentityDocument::sole()->file_path);

    expect($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('RAFEEQ_SECRET_LOCATION')
        // Still a usable image, not just scrubbed bytes.
        ->and(@imagecreatefromstring($stored))->not->toBeFalse();
});

it('refuses a file that is not an image, whatever it is named', function (string $contents, string $name) {
    uploadVerificationDocument(
        $this->token, 'government_id', 'national_id_front',
        UploadedFile::fake()->createWithContent($name, $contents),
    )->assertStatus(422);

    expect(IdentityDocument::count())->toBe(0);
})->with([
    'windows executable' => ["MZ\x90\x00\x03".str_repeat("\x00", 100), 'id.jpg'],
    'shell script' => ["#!/bin/sh\nrm -rf /", 'id.jpg'],
    'php payload' => ['<?php system($_GET["c"]); ?>', 'id.jpg'],
    'html' => ['<script>alert(1)</script>', 'id.jpg'],
]);

/**
 * A polyglot — valid JPEG header, executable payload appended — is the obvious
 * way past a header-only check.
 */
it('refuses a file that is a valid image and something else at once', function () {
    $polyglot = UploadedFile::fake()->image('ok.jpg', 40, 40)->getContent()."\n<?php system('id'); ?>";

    uploadVerificationDocument(
        $this->token, 'government_id', 'national_id_front',
        UploadedFile::fake()->createWithContent('id.jpg', $polyglot),
    )->assertStatus(422)->assertJsonPath('error.code', 'DOCUMENT_REJECTED_BY_SCANNER');

    expect(IdentityDocument::count())->toBe(0);
});

it('never writes anything to disk for a refused upload', function () {
    uploadVerificationDocument(
        $this->token, 'government_id', 'national_id_front',
        UploadedFile::fake()->createWithContent('id.jpg', "MZ\x90\x00"),
    )->assertStatus(422);

    // Not "deleted afterwards" — never stored. The scan happens before the
    // write, so unscanned bytes never reach the bucket at all.
    expect($this->storage->disk()->allFiles())->toBeEmpty();
});

it('audits a refusal with a hash, never the bytes or the filename', function () {
    uploadVerificationDocument(
        $this->token, 'government_id', 'national_id_front',
        UploadedFile::fake()->createWithContent('my-passport-scan.jpg', 'not-an-image'),
    )->assertStatus(422);

    $event = SecurityEvent::where('event_type', SecurityEventType::DocumentRejected->value)->sole();

    expect($event->metadata['sha256'])->toBe(hash('sha256', 'not-an-image'))
        ->and(json_encode($event->metadata))->not->toContain('my-passport-scan')
        ->and(json_encode($event->metadata))->not->toContain('not-an-image');
});

it('refuses a document that is not part of the level being worked on', function () {
    // A driving licence backs a DRIVER profile, not a trust level.
    uploadVerificationDocument($this->token, 'government_id', 'licence_front')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DOCUMENT_KIND_NOT_ACCEPTED');
});

it('replaces a re-uploaded document instead of leaving two for a reviewer', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);
    $first = IdentityDocument::sole()->file_path;

    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    expect(IdentityDocument::count())->toBe(1)
        // The superseded file is gone from the disk too, not just from the
        // table — an orphan is a document nobody will ever purge.
        ->and($this->storage->disk()->exists($first))->toBeFalse();
});

it('stores nothing that identifies the document from its path alone', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $path = IdentityDocument::sole()->file_path;

    // A bucket listing must reveal nothing about what a file contains.
    expect($path)->not->toContain('national_id')
        ->and($path)->not->toContain('front')
        ->and($path)->not->toContain('مريم');
});

it('serves a document only to its owner, through a short-lived link', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $url = $this->storage->temporaryUrl(IdentityDocument::sole());

    $response = $this->withToken($this->token)->get($url)->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        // Untrusted content stored on our own origin must never be able to
        // execute against our session if it is ever opened in a browser.
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('refuses a document link once it has expired', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $url = $this->storage->temporaryUrl(IdentityDocument::sole());

    $this->travel((int) config('rafeeq.verification.document_url_ttl_seconds') + 5)->seconds();

    $this->withToken($this->token)->get($url)->assertStatus(403);
});

/**
 * A signature proves we issued the link and that it has not expired. It does
 * NOT prove the person holding it is the person it was issued to, which is why
 * ownership is still checked when the route runs.
 */
it('refuses someone else a validly signed link to a document that is not theirs', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $url = $this->storage->temporaryUrl(IdentityDocument::sole());

    fakeOtpSender();
    $otherToken = signIn(phone: '01112223344', devicePublicId: 'other')['session']['accessToken'];

    // 404, not 403: a 403 would confirm the document exists.
    $this->withToken($otherToken)->get($url)->assertStatus(404);
});

it('refuses an unauthenticated caller even with a valid signature', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $url = $this->storage->temporaryUrl(IdentityDocument::sole());

    // `withoutToken()` matters: `withToken()` sets a DEFAULT header on the
    // test case, so the bearer token from the upload above would still be sent
    // and this would assert nothing.
    $this->withoutToken()->getJson($url)->assertStatus(401);
});

/**
 * The other delivery mode (decision D7): production points the disk at S3 and
 * turns presigning on, so the link goes straight to storage and never touches
 * the app. Covered because the two modes have different security properties and
 * a change must not silently switch between them.
 */
it('hands out a presigned storage link when configured to', function () {
    config()->set('rafeeq.verification.presigned_document_urls', true);

    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $url = $this->storage->temporaryUrl(IdentityDocument::sole());

    // Straight to storage, so it carries no route of ours and still expires.
    expect($url)->not->toContain('/api/v1/')
        ->and($url)->toContain('expiration');
});

it('audits every document read', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $this->withToken($this->token)->get($this->storage->temporaryUrl(IdentityDocument::sole()))->assertOk();

    expect(SecurityEvent::where('event_type', SecurityEventType::DocumentAccessed->value)->count())->toBe(1);
});

it('keeps documents out of any serialized payload', function () {
    uploadVerificationDocument($this->token, 'government_id', 'national_id_front')->assertStatus(201);

    $document = IdentityDocument::sole();

    expect($document->toArray())->not->toHaveKey('file_path')
        ->and($document->toArray())->not->toHaveKey('ocr_payload');
});

/**
 * Builds a minimal but genuine JPEG whose APP1 segment is an EXIF block
 * containing a GPS-flavoured payload, so the metadata-stripping test is
 * asserting against real metadata rather than a string we invented.
 */
function jpegWithGpsExif(): string
{
    $image = UploadedFile::fake()->image('base.jpg', 60, 60)->getContent();

    $exifPayload = "Exif\x00\x00".'GPSLatitude=30.0444;GPSLongitude=31.2357;RAFEEQ_SECRET_LOCATION';
    $segment = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;

    // APP1 goes immediately after the SOI marker.
    return substr($image, 0, 2).$segment.substr($image, 2);
}
