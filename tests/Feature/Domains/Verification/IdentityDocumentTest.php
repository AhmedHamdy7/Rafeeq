<?php

use App\Domains\Verification\Models\IdentityDocument;
use Illuminate\Support\Facades\DB;

it('hides the private file path and OCR payload from serialization', function () {
    $document = IdentityDocument::factory()->create(['ocr_payload' => ['name' => 'نور حسن']]);

    expect($document->toArray())->not->toHaveKey('file_path')
        ->and($document->toArray())->not->toHaveKey('ocr_payload');
});

it('encrypts the OCR payload at rest', function () {
    $document = IdentityDocument::factory()->create(['ocr_payload' => ['national_id' => '29604011234567']]);

    $raw = DB::table('identity_documents')->where('id', $document->id)->value('ocr_payload');

    expect($raw)->not->toContain('29604011234567')
        ->and($document->fresh()->ocr_payload)->toBe(['national_id' => '29604011234567']);
});
